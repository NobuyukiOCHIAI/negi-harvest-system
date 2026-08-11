<?php
/**
 * 需給オペレーション中核
 *
 * ① フル稼働（空き→猶予内定植）をデフォルトで自発維持
 * ② システム計画ライン（能力）と GCAL確定コミットを分離管理
 *    ※確定コミットは GCAL からのみ（manual 反映はしない）
 * ③ 一時余剰（収穫猶予内の廃棄リスク）と数か月トレンドを区別
 * ④ アラートは「●月●週 ±■kg」を時系列で表示。青線はトレンド反映シミュレーション
 * ⑤ 季節ベース（昨対・月次）可視化用データ
 * ⑥ 入力/同期のたびに ensure で再リコメンド
 * ⑦ 監視エージェント用スナップショット
 */
require_once __DIR__ . '/rotation_capacity.php';
require_once __DIR__ . '/inventory_trust.php';
require_once __DIR__ . '/capacity_outlook.php';
require_once __DIR__ . '/plant_schedule.php';
require_once __DIR__ . '/gcal_shipments.php';
require_once __DIR__ . '/overgrow_metrics.php';
require_once __DIR__ . '/staff_recommend.php';

/** トレンド判定の最小連続週（≒1か月） */
const GF_TREND_MIN_WEEKS = 4;

/** 一時余剰とみなす「収穫までの安全猶予」既定（日）。実績ギャップから上書き */
const GF_SPOT_GRACE_DEFAULT_DAYS = 10;

/** ensure の最短間隔（秒）— ページ連打で INSERT 連発しない */
const GF_ENSURE_TTL_SEC = 300;

/**
 * 予測日と実収穫開始のギャップから「ゴミにならない安全猶予」日数を算出
 * （過去の正のギャップの高め分位。データ不足時は既定10日）
 */
function supply_pred_harvest_grace_days(mysqli $link): int
{
    $sql = "
SELECT DATEDIFF(c.harvest_start, DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY)) AS gap_days
FROM cycles c
JOIN predictions pr ON pr.cycle_id = c.id
 AND NOT EXISTS (
   SELECT 1 FROM predictions p2 WHERE p2.cycle_id = pr.cycle_id AND p2.created_at > pr.created_at
 )
WHERE c.harvest_start IS NOT NULL
  AND pr.pred_days IS NOT NULL
  AND c.plant_date IS NOT NULL
ORDER BY c.harvest_start DESC
LIMIT 200
";
    $res = mysqli_query($link, $sql);
    $gaps = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $g = (int)$row['gap_days'];
            // 予測より遅い収穫（=余裕があった）だけを安全側に使う。負は予測超過で除外
            if ($g >= 0 && $g <= 30) {
                $gaps[] = $g;
            }
        }
        mysqli_free_result($res);
    }
    if (count($gaps) < 8) {
        return GF_SPOT_GRACE_DEFAULT_DAYS;
    }
    sort($gaps);
    // 高めの安全側: 90%点付近（ゴミにならない最長寄り）
    $idx = (int)floor((count($gaps) - 1) * 0.90);
    $days = (int)$gaps[$idx];
    return max(5, min(14, $days > 0 ? $days : GF_SPOT_GRACE_DEFAULT_DAYS));
}

/**
 * フル稼働の自発維持: 空きベッドを常時回転で plant_schedule に積む
 * （人間のボタン待ちにしない）
 *
 * @return array{ran:bool,created:int,skipped_reserved:int,message:string,throttled:bool}
 */
function supply_ensure_full_rotation(mysqli $link, bool $force = false): array
{
    $now = time();
    $stateKey = 'supply_ensure_full_rotation';
    $last = 0;
    $chk = mysqli_query($link, "SHOW TABLES LIKE 'sync_state'");
    $hasSync = $chk && mysqli_num_rows($chk) > 0;
    if ($chk) {
        mysqli_free_result($chk);
    }
    if ($hasSync && !$force) {
        $st = mysqli_prepare($link, "SELECT UNIX_TIMESTAMP(last_synced_at) AS ts FROM sync_state WHERE sync_key = ? LIMIT 1");
        if ($st) {
            mysqli_stmt_bind_param($st, 's', $stateKey);
            mysqli_stmt_execute($st);
            $rr = mysqli_stmt_get_result($st);
            if ($row = mysqli_fetch_assoc($rr)) {
                $last = (int)$row['ts'];
            }
            mysqli_stmt_close($st);
        }
        if ($last > 0 && ($now - $last) < GF_ENSURE_TTL_SEC) {
            return [
                'ran' => false,
                'created' => 0,
                'skipped_reserved' => 0,
                'throttled' => true,
                'message' => '直近に常時回転を確認済み',
            ];
        }
    }

    $r = rotation_generate_continuous_plants($link, 16);
    // システム計画ライン（能力相当）を source=plan として併記（GCAL確定と分離）
    supply_sync_capacity_plan_line($link, 16);

    if ($hasSync) {
        $msg = sprintf('created=%d skipped=%d', $r['created'], $r['skipped_reserved']);
        $at = date('Y-m-d H:i:s');
        $status = 'ok';
        $ins = mysqli_prepare(
            $link,
            "INSERT INTO sync_state (sync_key, last_synced_at, last_status, last_message)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               last_synced_at=VALUES(last_synced_at),
               last_status=VALUES(last_status),
               last_message=VALUES(last_message)"
        );
        if ($ins) {
            mysqli_stmt_bind_param($ins, 'ssss', $stateKey, $at, $status, $msg);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
        }
    }

    return [
        'ran' => true,
        'created' => (int)$r['created'],
        'skipped_reserved' => (int)$r['skipped_reserved'],
        'throttled' => false,
        'message' => sprintf(
            '常時フル稼働を維持（定植計画 +%d · 予約済スキップ %d）',
            $r['created'],
            $r['skipped_reserved']
        ),
    ];
}

/**
 * 週次のシステム計画ライン = フル回転能力を calendar_shipments.source='plan' に反映
 * システム計画ライン（能力相当）を source=plan として併記（GCAL確定と分離）
 * ※確定コミットは GCAL のみ。plan は能力表示用。
 */
function supply_sync_capacity_plan_line(mysqli $link, int $weeksAhead = 16): int
{
    $outlook = rotation_capacity_outlook($link, $weeksAhead);
    $stmt = mysqli_prepare(
        $link,
        "INSERT INTO calendar_shipments (week_start_date, committed_amount_kg, source, gcal_event_id)
         VALUES (?, ?, 'plan', NULL)
         ON DUPLICATE KEY UPDATE committed_amount_kg = VALUES(committed_amount_kg)"
    );
    if (!$stmt) {
        return 0;
    }
    $n = 0;
    foreach ($outlook['weeks'] as $w) {
        $week = $w['week'];
        $kg = round((float)$w['capacity_kg'], 2);
        mysqli_stmt_bind_param($stmt, 'sd', $week, $kg);
        if (mysqli_stmt_execute($stmt)) {
            $n++;
        }
    }
    mysqli_stmt_close($stmt);
    return $n;
}

/**
 * 週次ライン: GCAL確定 / システム計画(能力)
 * （確定コミットは GCAL のみ。シミュレーションは supply_sim_commit_series）
 *
 * @return list<array>
 */
function supply_dual_week_lines(mysqli $link, int $weeksAhead = 16): array
{
    $outlook = rotation_capacity_outlook($link, $weeksAhead);
    $today = date('Y-m-d');
    $currentWeek = gcal_week_start_sunday($today);
    $horizonEnd = date('Y-m-d', strtotime('+' . $weeksAhead . ' weeks', strtotime($currentWeek)));

    $bySource = ['gcal' => [], 'manual' => [], 'plan' => []];
    $res = mysqli_query(
        $link,
        "SELECT week_start_date, source, committed_amount_kg
         FROM calendar_shipments
         WHERE week_start_date BETWEEN '{$currentWeek}' AND '{$horizonEnd}'"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $src = $row['source'];
            if (!isset($bySource[$src])) {
                continue;
            }
            $bySource[$src][$row['week_start_date']] = (float)$row['committed_amount_kg'];
        }
        mysqli_free_result($res);
    }

    $out = [];
    foreach ($outlook['weeks'] as $w) {
        $week = $w['week'];
        $cap = (float)$w['capacity_kg'];
        $gcal = (float)($bySource['gcal'][$week] ?? 0);
        $plan = (float)($bySource['plan'][$week] ?? $cap);
        // 確定コミット = GCAL のみ
        $out[] = [
            'week' => $week,
            'capacity_kg' => $cap,
            'plan_kg' => $plan,
            'gcal_kg' => $gcal,
            'manual_kg' => 0.0,
            'commit_kg' => $gcal,
            'effective_ship_kg' => $gcal,
            'open_kg' => (float)($w['open_kg'] ?? 0),
            'yoy_kg' => (float)($w['yoy_kg'] ?? 0),
            'delta_vs_commit' => round($cap - $gcal, 1),
            'delta_vs_gcal' => round($cap - $gcal, 1),
        ];
    }
    return $out;
}

/**
 * アラート1行: 「●月●週 ±■kg」
 */
function supply_alert_short_line(array $a): string
{
    $isTighten = in_array(($a['type'] ?? ''), ['commit_tighten', 'trend_tighten'], true);
    $isSpot = ($a['kind'] ?? '') === 'spot' || ($a['type'] ?? '') === 'spot_surplus';
    $sign = $isTighten ? '−' : '+';
    $kg = (int)round((float)($a['kg_per_week'] ?? 0));
    $base = supply_week_label((string)$a['start_week']) . ' ' . $sign . $kg . 'kg';
    return $isSpot ? ($base . '（一時）') : $base;
}

/**
 * GCAL確定にトレンド提案を載せた一次シミュレーション（kg/週）
 *
 * @param list<array{week:string,gcal_kg?:float,ship_kg?:float}> $weekRows
 * @param list<array> $actions
 * @return list<float>
 */
function supply_sim_commit_series(array $weekRows, array $actions): array
{
    $adj = [];
    foreach ($actions as $a) {
        if (($a['kind'] ?? '') === 'spot' || ($a['type'] ?? '') === 'spot_surplus') {
            continue;
        }
        $isTighten = in_array(($a['type'] ?? ''), ['commit_tighten', 'trend_tighten'], true);
        $delta = ($isTighten ? -1.0 : 1.0) * (float)($a['kg_per_week'] ?? 0);
        $ts = strtotime((string)$a['start_week']);
        $te = strtotime((string)$a['end_week']);
        if ($ts === false || $te === false) {
            continue;
        }
        for ($x = $ts; $x <= $te; $x = strtotime('+7 days', $x)) {
            $w = date('Y-m-d', $x);
            $adj[$w] = ($adj[$w] ?? 0.0) + $delta;
        }
    }
    $out = [];
    foreach ($weekRows as $w) {
        $week = (string)$w['week'];
        $gcal = (float)($w['gcal_kg'] ?? $w['ship_kg'] ?? 0);
        $out[] = round(max(0.0, $gcal + ($adj[$week] ?? 0.0)), 1);
    }
    return $out;
}

/**
 * 予測「直近週」と同一ロジックの週次累計余剰行
 *
 * @return list<array{
 *   week_start_date:string,is_elapsed:bool,is_current:bool,
 *   forecast_kg:float,ship_kg:?float,week_delta_kg:float,surplus_kg:float
 * }>
 */
function supply_inventory_surplus_rows(mysqli $link, ?string $horizonEnd = null): array
{
    $today = date('Y-m-d');
    $currentWeek = gcal_week_start_sunday($today);
    if ($horizonEnd === null) {
        $horizonEnd = date('Y-m-d', strtotime('+3 months', strtotime($currentWeek)));
    }

    $sql = "
SELECT
  COALESCE(pr.postproc_total_kg, pr.pred_total_kg) AS forecast_kg,
  DATE_SUB(
    DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY),
    INTERVAL (DAYOFWEEK(DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY)) - 1) DAY
  ) AS week_start_date
FROM cycles c
JOIN predictions pr
  ON pr.cycle_id = c.id
 AND NOT EXISTS (
       SELECT 1 FROM predictions p2
        WHERE p2.cycle_id = pr.cycle_id
          AND p2.created_at > pr.created_at
     )
WHERE c.harvest_end IS NULL
  AND pr.pred_days IS NOT NULL
";
    $fcByWeek = [];
    $res = mysqli_query($link, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $w = $row['week_start_date'];
            if ($w === null) {
                continue;
            }
            $fcByWeek[$w] = ($fcByWeek[$w] ?? 0.0) + (float)$row['forecast_kg'];
        }
        mysqli_free_result($res);
    }

    $shipCommitByWeek = [];
    $res = mysqli_query(
        $link,
        "SELECT week_start_date, committed_amount_kg
         FROM calendar_shipments
         WHERE source = 'gcal'
         ORDER BY week_start_date ASC"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $w = $row['week_start_date'];
            if (!isset($shipCommitByWeek[$w])) {
                $shipCommitByWeek[$w] = (float)$row['committed_amount_kg'];
            }
        }
        mysqli_free_result($res);
    }

    $remainingByWeek = [];
    $res = mysqli_query(
        $link,
        "SELECT week_start_date, SUM(amount_kg) AS remaining_kg
         FROM calendar_shipment_events
         WHERE ship_date > CURDATE()
         GROUP BY week_start_date"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $remainingByWeek[$row['week_start_date']] = (float)$row['remaining_kg'];
        }
        mysqli_free_result($res);
    }

    $eventWeeks = [];
    $res = mysqli_query($link, "SELECT DISTINCT week_start_date FROM calendar_shipment_events");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $eventWeeks[$row['week_start_date']] = true;
        }
        mysqli_free_result($res);
    }

    $shippedThroughTodayByWeek = [];
    $res = mysqli_query(
        $link,
        "SELECT week_start_date, SUM(amount_kg) AS shipped_kg
         FROM calendar_shipment_events
         WHERE ship_date <= CURDATE()
         GROUP BY week_start_date"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $shippedThroughTodayByWeek[$row['week_start_date']] = (float)$row['shipped_kg'];
        }
        mysqli_free_result($res);
    }

    $weeks = array_values(array_unique(array_merge(
        array_keys($fcByWeek),
        array_keys($shipCommitByWeek),
        array_keys($remainingByWeek)
    )));
    sort($weeks);

    $rows = [];
    $surplus = null;
    foreach ($weeks as $w) {
        if ($w > $horizonEnd) {
            continue;
        }
        $sumKg = (float)($fcByWeek[$w] ?? 0);
        $commit = $shipCommitByWeek[$w] ?? null;

        if (isset($eventWeeks[$w])) {
            $shipRemain = (float)($remainingByWeek[$w] ?? 0);
        } elseif ($commit !== null) {
            if ($w < $currentWeek) {
                $shipRemain = 0.0;
            } else {
                $shipped = (float)($shippedThroughTodayByWeek[$w] ?? 0);
                $shipRemain = max(0.0, (float)$commit - $shipped);
            }
        } else {
            $shipRemain = null;
        }

        if ($sumKg <= 0.0 && ($shipRemain === null || $shipRemain <= 0.0)) {
            continue;
        }

        $delta = $sumKg - (float)($shipRemain ?? 0);
        if ($surplus === null) {
            $surplus = $delta;
        } else {
            $surplus = $surplus + $delta;
        }

        $rows[] = [
            'week_start_date' => $w,
            'is_elapsed' => ($w < $currentWeek),
            'is_current' => ($w === $currentWeek),
            'forecast_kg' => round($sumKg, 1),
            'ship_kg' => $shipRemain === null ? null : round($shipRemain, 1),
            'week_delta_kg' => round($delta, 1),
            'surplus_kg' => round($surplus, 1),
        ];
    }
    return $rows;
}

/**
 * 予測「直近週」と同じく、経過週のオープン予測余剰を今週開始時点まで積み上げた残高
 */
function supply_inventory_style_cum_carry(mysqli $link): float
{
    $today = date('Y-m-d');
    $currentWeek = gcal_week_start_sunday($today);
    $rows = supply_inventory_surplus_rows($link);
    $carry = 0.0;
    foreach ($rows as $r) {
        if ($r['week_start_date'] >= $currentWeek) {
            break;
        }
        $carry = (float)$r['surplus_kg'];
    }
    return round($carry, 1);
}

/**
 * 週次余剰配列に期初繰越を足して累計系列を作る
 *
 * @param list<float> $weekDeltas
 * @return list<float>
 */
function supply_cum_surplus_with_carry(array $weekDeltas, float $carryKg): array
{
    $cum = $carryKg;
    $out = [];
    foreach ($weekDeltas as $v) {
        $cum = round($cum + (float)$v, 1);
        $out[] = $cum;
    }
    return $out;
}

/**
 * トレンド平準化シミュレーション
 * アラート直載せの跳ねを均し、開始〜levelEnd まで一定の上げ／下げを維持する。
 *
 * @param list<array{week:string,gcal_kg?:float,ship_kg?:float}> $weekRows
 * @param list<array> $actions
 * @return array{series:list<float>,per_week:float,level_end:string,mode:string,note:string}
 */
function supply_sim_level_series(array $weekRows, array $actions, ?string $levelEndWeek = null): array
{
    $raw = supply_sim_commit_series($weekRows, $actions);
    $n = count($weekRows);
    $gcals = [];
    foreach ($weekRows as $w) {
        $gcals[] = (float)($w['gcal_kg'] ?? $w['ship_kg'] ?? 0);
    }
    if ($n === 0) {
        return [
            'series' => [],
            'per_week' => 0.0,
            'level_end' => '',
            'mode' => 'level',
            'note' => 'データなし',
        ];
    }

    $extraTotal = 0.0;
    $deficitTotal = 0.0;
    $firstPos = null;
    $firstNeg = null;
    for ($i = 0; $i < $n; $i++) {
        $d = $raw[$i] - $gcals[$i];
        if ($d > 1) {
            $extraTotal += $d;
            if ($firstPos === null) {
                $firstPos = $i;
            }
        } elseif ($d < -1) {
            $deficitTotal += -$d;
            if ($firstNeg === null) {
                $firstNeg = $i;
            }
        }
    }

    $refWeek = $weekRows[$firstPos ?? $firstNeg ?? 0]['week'];
    $y = (int)date('Y', strtotime((string)$refWeek));
    if ($levelEndWeek === null || $levelEndWeek === '') {
        $levelEndWeek = date('Y-m-d', strtotime("last sunday of September {$y}"));
    }
    if ($levelEndWeek === 'horizon') {
        $levelEndWeek = (string)$weekRows[$n - 1]['week'];
    }

    $endIdx = $n - 1;
    for ($i = 0; $i < $n; $i++) {
        if (strtotime((string)$weekRows[$i]['week']) >= strtotime($levelEndWeek)) {
            $endIdx = $i;
            break;
        }
    }

    $out = $gcals;
    $perWeek = 0.0;
    $noteParts = [];

    if ($firstPos !== null && $extraTotal > 0) {
        $start = $firstPos;
        $len = max(1, $endIdx - $start + 1);
        $perWeek = round($extraTotal / $len, 1);
        for ($i = $start; $i <= $endIdx; $i++) {
            $out[$i] = round($gcals[$i] + $perWeek, 1);
        }
        // 平準化期間後も「上げたまま」を維持
        for ($i = $endIdx + 1; $i < $n; $i++) {
            $out[$i] = round($gcals[$i] + $perWeek, 1);
        }
        $noteParts[] = sprintf(
            '拡大を平準化: %s〜%s 週あたり +%.0fkg（合計%.0fkgを均し、その先も維持）',
            date('n/j', strtotime((string)$weekRows[$start]['week'])),
            date('n/j', strtotime((string)$weekRows[$endIdx]['week'])),
            $perWeek,
            $extraTotal
        );
    }

    if ($firstNeg !== null && $deficitTotal > 0) {
        $start = $firstNeg;
        $len = max(1, $endIdx - $start + 1);
        $negPer = round($deficitTotal / $len, 1);
        for ($i = $start; $i <= $endIdx; $i++) {
            $out[$i] = round(max(0.0, $out[$i] - $negPer), 1);
        }
        for ($i = $endIdx + 1; $i < $n; $i++) {
            $out[$i] = round(max(0.0, $out[$i] - $negPer), 1);
        }
        $noteParts[] = sprintf('絞りを平準化: 週あたり −%.0fkg', $negPer);
        if ($perWeek == 0.0) {
            $perWeek = -$negPer;
        }
    }

    if (!$noteParts) {
        $noteParts[] = '平準化するトレンド差がありません（GCALと同水準）';
    }

    return [
        'series' => array_map(static fn($v) => round((float)$v, 1), $out),
        'per_week' => $perWeek,
        'level_end' => (string)$weekRows[$endIdx]['week'],
        'mode' => 'level',
        'note' => implode(' · ', $noteParts),
    ];
}

/**
 * 週次デルタを一時/トレンドに分類し、営業トークを付与
 *
 * @param list<array> $cumWeeks trust_attach_cumulative 結果
 * @return array{spot:list,trends:list,actions:list,grace_days:int}
 */
function supply_classify_surplus_deficit(mysqli $link, array $cumWeeks): array
{
    $grace = supply_pred_harvest_grace_days($link);
    $spot = [];
    $trends = [];

    // --- トレンド: 同符号の週次デルタが連続 GF_TREND_MIN_WEEKS 以上 ---
    $n = count($cumWeeks);
    $i = 0;
    while ($i < $n) {
        $delta = (float)$cumWeeks[$i]['week_delta_kg'];
        $sign = $delta >= 40 ? 1 : ($delta <= -40 ? -1 : 0);
        if ($sign === 0) {
            $i++;
            continue;
        }
        $j = $i + 1;
        while ($j < $n) {
            $d = (float)$cumWeeks[$j]['week_delta_kg'];
            $s = $d >= 40 ? 1 : ($d <= -40 ? -1 : 0);
            // フラット1週はトレンドを壊さない（連続期間を分断しない）
            if ($s === 0 && abs($d) < 40) {
                $j++;
                continue;
            }
            if ($s !== $sign) {
                break;
            }
            $j++;
        }
        // 末尾のフラットを削る
        $end = $j - 1;
        while ($end > $i && abs((float)$cumWeeks[$end]['week_delta_kg']) < 40) {
            $end--;
        }
        $len = $end - $i + 1;
        if ($len >= GF_TREND_MIN_WEEKS) {
            $deltas = [];
            for ($k = $i; $k <= $end; $k++) {
                $deltas[] = (float)$cumWeeks[$k]['week_delta_kg'];
            }
            $avg = array_sum($deltas) / count($deltas);
            $perWeek = round(abs($avg), 0);
            $start = $cumWeeks[$i]['week'];
            $finish = $cumWeeks[$end]['week'];
            $type = $sign > 0 ? 'trend_expand' : 'trend_tighten';
            $label = $sign > 0 ? 'ベース栽培量の拡大トレンド' : 'ベース栽培量の減少トレンド';
            $talk = $sign > 0
                ? sprintf(
                    '%s週から、週あたり＋%.0fkgでの対応が可能となります。いかがですか？',
                    supply_week_label($start),
                    $perWeek
                )
                : sprintf(
                    '%s週から、週あたり−%.0fkgでの対応となります。ご調整お願いします。',
                    supply_week_label($start),
                    $perWeek
                );
            $short = supply_week_label($start) . ' ' . ($sign > 0 ? '+' : '−') . (int)$perWeek . 'kg';
            $trends[] = [
                'kind' => 'trend',
                'type' => $type,
                'urgency' => $sign < 0 ? 'critical' : 'ok',
                'priority' => $sign < 0 ? 100 : 40,
                'start_week' => $start,
                'end_week' => $finish,
                'weeks' => $len,
                'kg_per_week' => $perWeek,
                'total_kg' => round($perWeek * $len, 0),
                'label' => $label,
                'detail' => sprintf(
                    '%s〜%s（%d週間連続）。一時変動ではなくベースの増減。仲卸には数か月見通しとして先行共有。',
                    supply_week_label($start),
                    supply_week_label($finish),
                    $len
                ),
                'sales_talk' => $talk,
                'short_line' => $short,
            ];
            $i = $end + 1;
            continue;
        }
        $i++;
    }

    // --- 一時余剰: トレンドに含まれない短期の大きなプラス、かつ近い収穫猶予内 ---
    $covered = [];
    foreach ($trends as $t) {
        $ts = strtotime($t['start_week']);
        $te = strtotime($t['end_week']);
        for ($x = $ts; $x <= $te; $x = strtotime('+7 days', $x)) {
            $covered[date('Y-m-d', $x)] = true;
        }
    }

    foreach ($cumWeeks as $idx => $w) {
        $week = $w['week'];
        if (isset($covered[$week])) {
            continue;
        }
        $delta = (float)$w['week_delta_kg'];
        if ($delta < 80) {
            continue;
        }
        // この週のオープン収穫が「猶予日以内」に来る／来ているか
        $nearHarvestKg = supply_open_harvest_kg_within_days($link, $week, $grace);
        if ($nearHarvestKg < 40 && (float)($w['open_kg'] ?? 0) < 40) {
            // 近い収穫が薄い一時山は軽め
            if ($delta < 120) {
                continue;
            }
        }
        // 前後がトレンド未満ならスポット
        $spot[] = [
            'kind' => 'spot',
            'type' => 'spot_surplus',
            'urgency' => 'warn',
            'priority' => 70,
            'start_week' => $week,
            'end_week' => $week,
            'weeks' => 1,
            'kg_per_week' => round($delta, 0),
            'total_kg' => round($delta, 0),
            'label' => '一時的余剰（廃棄リスク）',
            'detail' => sprintf(
                '%sに約%.0fkgの一時的余剰発生 ⇒ 廃棄リスク有（収穫猶予%d日以内の山）。スポット営業を実施。',
                supply_week_label($week),
                $delta,
                $grace
            ),
            'sales_talk' => sprintf(
                '%sに一時的に約%.0fkgの余剰が見込まれます。スポットでの販路検討をお願いします。',
                supply_week_label($week),
                $delta
            ),
            'short_line' => supply_week_label($week) . ' +' . (int)round($delta, 0) . 'kg（一時）',
            'grace_days' => $grace,
            'near_harvest_kg' => $nearHarvestKg,
        ];
    }

    // 在庫割れがある場合の減少トレンドが無ければ、割れ起点のtightenを補完
    $sum = trust_break_summary($cumWeeks);
    $hasTighten = (bool)array_filter($trends, static fn($t) => $t['type'] === 'trend_tighten');
    if ($sum['first_break_week'] && !$hasTighten) {
        $idx = (int)$sum['first_break_index'];
        $startIdx = max(0, $idx - 1);
        $endIdx = min($n - 1, $startIdx + max(GF_TREND_MIN_WEEKS, 6) - 1);
        $need = max(30.0, -$sum['min_cum_kg'] / max(1, $endIdx - $startIdx + 1));
        $start = $cumWeeks[$startIdx]['week'];
        $finish = $cumWeeks[$endIdx]['week'];
        $perWeek = round($need, 0);
        $trends[] = [
            'kind' => 'trend',
            'type' => 'trend_tighten',
            'urgency' => 'critical',
            'priority' => 110,
            'start_week' => $start,
            'end_week' => $finish,
            'weeks' => $endIdx - $startIdx + 1,
            'kg_per_week' => $perWeek,
            'total_kg' => round($perWeek * ($endIdx - $startIdx + 1), 0),
            'label' => '減少フェーズへの移行（在庫割れ回避）',
            'detail' => sprintf(
                '累計割れ見込み %s。早めに削減幅を仲卸へ伝達（拡大よりシビア）。',
                supply_week_label($sum['first_break_week'])
            ),
            'sales_talk' => sprintf(
                '%s週から、週あたり−%.0fkgでの対応となります。ご調整お願いします。',
                supply_week_label($start),
                $perWeek
            ),
            'short_line' => supply_week_label($start) . ' −' . (int)$perWeek . 'kg',
            'break_week' => $sum['first_break_week'],
        ];
    }

    // 画面表示は時系列（開始週昇順）。同週なら減少を先に
    usort($trends, static function ($a, $b) {
        $c = strcmp($a['start_week'], $b['start_week']);
        if ($c !== 0) {
            return $c;
        }
        return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
    });
    usort($spot, static function ($a, $b) {
        return strcmp($a['start_week'], $b['start_week']);
    });

    $actions = [];
    foreach ($trends as $t) {
        $actions[] = [
            'type' => $t['type'] === 'trend_expand' ? 'commit_expand' : 'commit_tighten',
            'kind' => 'trend',
            'urgency' => $t['urgency'],
            'start_week' => $t['start_week'],
            'end_week' => $t['end_week'],
            'weeks' => $t['weeks'],
            'kg_per_week' => $t['kg_per_week'],
            'total_kg' => $t['total_kg'],
            'label' => $t['label'],
            'detail' => $t['detail'],
            'sales_talk' => $t['sales_talk'],
            'short_line' => $t['short_line'] ?? supply_alert_short_line($t),
            'break_week' => $t['break_week'] ?? null,
            'runway_weeks' => $sum['runway_weeks'],
        ];
    }
    foreach (array_slice($spot, 0, 5) as $s) {
        $actions[] = [
            'type' => 'spot_surplus',
            'kind' => 'spot',
            'urgency' => $s['urgency'],
            'start_week' => $s['start_week'],
            'end_week' => $s['end_week'],
            'weeks' => 1,
            'kg_per_week' => $s['kg_per_week'],
            'total_kg' => $s['total_kg'],
            'label' => $s['label'],
            'detail' => $s['detail'],
            'sales_talk' => $s['sales_talk'],
            'short_line' => $s['short_line'] ?? supply_alert_short_line($s),
            'break_week' => null,
            'runway_weeks' => $sum['runway_weeks'],
        ];
    }
    usort($actions, static function ($a, $b) {
        $c = strcmp($a['start_week'], $b['start_week']);
        if ($c !== 0) {
            return $c;
        }
        $pa = ($a['kind'] ?? '') === 'spot' ? 1 : (($a['type'] ?? '') === 'commit_tighten' ? 0 : 2);
        $pb = ($b['kind'] ?? '') === 'spot' ? 1 : (($b['type'] ?? '') === 'commit_tighten' ? 0 : 2);
        return $pa <=> $pb;
    });

    return [
        'spot' => $spot,
        'trends' => $trends,
        'actions' => $actions,
        'grace_days' => $grace,
        'summary' => $sum,
    ];
}

function supply_week_label(string $week): string
{
    $ts = strtotime($week);
    if ($ts === false) {
        return $week;
    }
    // n月 w週（日曜始まりの月内週）
    $m = (int)date('n', $ts);
    $d = (int)date('j', $ts);
    $w = (int)ceil($d / 7);
    return sprintf('%d月%d週', $m, $w);
}

/**
 * 指定週に収穫が来る（または猶予日内の）オープン予測kg
 */
function supply_open_harvest_kg_within_days(mysqli $link, string $weekStart, int $graceDays): float
{
    $weekEnd = date('Y-m-d', strtotime($weekStart . ' +6 days'));
    $from = date('Y-m-d', strtotime($weekStart . ' -' . $graceDays . ' days'));
    $sql = "
SELECT COALESCE(SUM(COALESCE(pr.postproc_total_kg, pr.pred_total_kg)),0) AS kg
FROM cycles c
JOIN beds b ON b.id = c.bed_id AND b.active = 1
LEFT JOIN predictions pr ON pr.cycle_id = c.id
 AND NOT EXISTS (
   SELECT 1 FROM predictions p2 WHERE p2.cycle_id = pr.cycle_id AND p2.created_at > pr.created_at
 )
WHERE c.harvest_end IS NULL
  AND DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(COALESCE(pr.pred_days,60)) AS SIGNED) DAY)
      BETWEEN '{$from}' AND '{$weekEnd}'
";
    $res = mysqli_query($link, $sql);
    $kg = 0.0;
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $kg = (float)$row['kg'];
    }
    if ($res) {
        mysqli_free_result($res);
    }
    return round($kg, 1);
}

/**
 * 季節ベース: 月次の昨対実績と今年の能力見通し
 *
 * @return list<array{month:string,yoy_kg:float,cap_kg:float,commit_kg:float}>
 */
function supply_seasonal_baseline(mysqli $link, int $months = 12): array
{
    $out = [];
    $start = date('Y-m-01');
    $outlook = rotation_capacity_outlook($link, max(52, $months * 5));
    $weeks = $outlook['weeks'];

    for ($i = 0; $i < $months; $i++) {
        $mStart = date('Y-m-01', strtotime($start . " +{$i} months"));
        $mEnd = date('Y-m-t', strtotime($mStart));
        $lyStart = date('Y-m-01', strtotime($mStart . ' -1 year'));
        $lyEnd = date('Y-m-t', strtotime($lyStart));

        $yoy = 0.0;
        $res = mysqli_query(
            $link,
            "SELECT COALESCE(SUM(h.harvest_kg),0) AS kg
             FROM harvests h
             WHERE h.harvest_date BETWEEN '{$lyStart}' AND '{$lyEnd}'
               AND (h.loss_type_id IS NULL OR h.loss_type_id NOT IN (
                    SELECT id FROM loss_types WHERE name IN ('GOMI','ゴミ','gomi')
               ))"
        );
        if ($res && ($row = mysqli_fetch_assoc($res))) {
            $yoy = (float)$row['kg'];
        }
        if ($res) {
            mysqli_free_result($res);
        }

        $cap = 0.0;
        $commit = 0.0;
        foreach ($weeks as $w) {
            if ($w['week'] >= $mStart && $w['week'] <= $mEnd) {
                $cap += (float)$w['capacity_kg'];
                $commit += (float)$w['ship_kg'];
            }
        }

        $out[] = [
            'month' => date('Y-m', strtotime($mStart)),
            'label' => date('n月', strtotime($mStart)),
            'yoy_kg' => round($yoy, 0),
            'cap_kg' => round($cap, 0),
            'commit_kg' => round($commit, 0),
            'hint_kg' => round($yoy, 0),
        ];
    }
    return $out;
}

/**
 * 監視エージェント用スナップショット
 *
 * @return array
 */
function supply_agent_snapshot(mysqli $link): array
{
    supply_ensure_full_rotation($link);

    $trust = trust_outlook_bundle($link, 20);
    $classified = supply_classify_surplus_deficit($link, $trust['with_rotation']);
    $sum = capacity_outlook_summary($link);
    $empty = count(plant_schedule_empty_beds($link));
    $staff = staff_auto_recommendations($link);
    $overgrow = open_cycle_progress($link);
    $riskN = count(array_filter($overgrow, static fn($r) => (int)$r['risk'] === 1));

    // 予測精度: 直近完了サイクルの |pred-actual|/actual
    $mae = null;
    $mape = null;
    $nEval = 0;
    $res = mysqli_query(
        $link,
        "SELECT
            COALESCE(pr.postproc_total_kg, pr.pred_total_kg) AS pred_kg,
            (SELECT COALESCE(SUM(h.harvest_kg),0) FROM harvests h
              WHERE h.cycle_id = c.id
                AND (h.loss_type_id IS NULL OR h.loss_type_id NOT IN (
                     SELECT id FROM loss_types WHERE name IN ('GOMI','ゴミ','gomi')
                ))) AS actual_kg
         FROM cycles c
         JOIN predictions pr ON pr.cycle_id = c.id
          AND NOT EXISTS (
            SELECT 1 FROM predictions p2 WHERE p2.cycle_id = pr.cycle_id AND p2.created_at > pr.created_at
          )
         WHERE c.harvest_end IS NOT NULL
           AND c.harvest_end >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)
         ORDER BY c.harvest_end DESC
         LIMIT 40"
    );
    $absErr = 0.0;
    $absPct = 0.0;
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $pred = (float)$row['pred_kg'];
            $act = (float)$row['actual_kg'];
            if ($act < 5 || $pred <= 0) {
                continue;
            }
            $absErr += abs($pred - $act);
            $absPct += abs($pred - $act) / $act;
            $nEval++;
        }
        mysqli_free_result($res);
    }
    if ($nEval > 0) {
        $mae = round($absErr / $nEval, 1);
        $mape = round(100 * $absPct / $nEval, 1);
    }

    $health = 'ok';
    $notes = [];
    if ($empty > 0) {
        $notes[] = "空きベッド {$empty} — 常時回転で計画投入済みか確認";
    }
    if ($sum['zero_weeks'] > 0) {
        $health = 'warn';
        $notes[] = "能力0の週が {$sum['zero_weeks']} — 回転シミュレーションを点検";
    }
    if ($trust['summary']['status'] === 'critical') {
        $health = 'critical';
        $notes[] = $trust['summary']['status_label'];
    } elseif ($trust['summary']['status'] === 'warn' && $health === 'ok') {
        $health = 'warn';
        $notes[] = $trust['summary']['status_label'];
    }
    if ($mape !== null && $mape > 35) {
        $health = $health === 'ok' ? 'warn' : $health;
        $notes[] = "予測MAPE {$mape}% — モデル再学習・特徴量を確認";
    }
    if ($riskN > 0) {
        $notes[] = "過栽培リスク {$riskN} ベッド";
    }
    foreach (array_slice($classified['trends'], 0, 3) as $t) {
        $notes[] = ($t['short_line'] ?? supply_alert_short_line($t));
    }
    foreach (array_slice($classified['spot'], 0, 2) as $s) {
        $notes[] = ($s['short_line'] ?? supply_alert_short_line($s));
    }

    $season = supply_seasonal_baseline($link, 6);

    return [
        'checked_at' => date('Y-m-d H:i:s'),
        'health' => $health,
        'notes' => $notes,
        'kpis' => [
            'runway_weeks' => $trust['summary']['runway_weeks'],
            'trust_status' => $trust['summary']['status'],
            'empty_beds' => $empty,
            'zero_capacity_weeks' => $sum['zero_weeks'],
            'overgrow_beds' => $riskN,
            'staff_alerts' => count($staff),
            'trend_n' => count($classified['trends']),
            'spot_n' => count($classified['spot']),
            'pred_mae_kg' => $mae,
            'pred_mape_pct' => $mape,
            'pred_eval_n' => $nEval,
            'grace_days' => $classified['grace_days'],
            'yoy_miss_weeks' => $sum['yoy_miss_weeks'],
        ],
        'trends' => $classified['trends'],
        'spot' => $classified['spot'],
        'actions' => $classified['actions'],
        'seasonal' => $season,
        'dual_lines_sample' => array_slice(supply_dual_week_lines($link, 8), 0, 8),
    ];
}
