<?php
/**
 * 需給オペレーション中核
 *
 * ① 収穫後猶予日内の次定植をツール作業なしで自動維持
 * ② 定植計画の遅れを可視化し、先の計画が届くかを監視
 * ③ 計画ライン上の先余り → 営業拡大。当週バッファの放出は定植済で判断
 * ④ 低温期に向かう能力減は早めに営業へ
 * ⑤ 確定コミットは GCAL のみ。能力はシステム計画
 */
require_once __DIR__ . '/rotation_capacity.php';
require_once __DIR__ . '/inventory_trust.php';
require_once __DIR__ . '/capacity_outlook.php';
require_once __DIR__ . '/plant_schedule.php';
require_once __DIR__ . '/gcal_shipments.php';
require_once __DIR__ . '/overgrow_metrics.php';
require_once __DIR__ . '/staff_recommend.php';
require_once __DIR__ . '/date_display.php';

/** 拡大トレンドの最小連続週 */
const GF_TREND_MIN_WEEKS = 4;

/** 減少トレンドは早め（低温期の察知） */
const GF_TREND_TIGHTEN_MIN_WEEKS = 3;

/** 計画上の拡大を話してよい最短リード（当週・翌週は定植済バッファを売らない） */
const GF_PLAN_EXPAND_LEAD_WEEKS = 3;

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
    plant_schedule_collapse_open_dupes($link);

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
 * アラート1行: 「8/9週 ±■kg」
 */
function supply_alert_short_line(array $a): string
{
    if (($a['type'] ?? '') === 'plant_delay' || ($a['kind'] ?? '') === 'exec') {
        return (string)($a['short_line'] ?? $a['label'] ?? '定植遅れ');
    }
    $isTighten = in_array(($a['type'] ?? ''), ['commit_tighten', 'trend_tighten'], true);
    $isSpot = ($a['kind'] ?? '') === 'spot' || ($a['type'] ?? '') === 'spot_surplus';
    $sign = $isTighten ? '−' : '+';
    $kg = (int)round((float)($a['kg_per_week'] ?? 0));
    $start = supply_week_label((string)$a['start_week']);
    $endRaw = (string)($a['end_week'] ?? $a['start_week']);
    $weeks = (int)($a['weeks'] ?? 1);
    $ranged = !$isSpot && ($weeks > 1 || $endRaw !== (string)$a['start_week']);
    if (!$ranged) {
        return $start . ' ' . $sign . $kg . 'kg';
    }
    return $start . '〜' . supply_week_label($endRaw) . ' ' . $sign . $kg . 'kg/週';
}

function supply_trend_kind_is_expand(array $t): bool
{
    return in_array((string)($t['type'] ?? ''), ['trend_expand', 'commit_expand'], true);
}

function supply_trend_kind_is_tighten(array $t): bool
{
    return in_array((string)($t['type'] ?? ''), ['trend_tighten', 'commit_tighten'], true);
}

/**
 * 同方向の連続週トレンドを1行にまとめる
 *
 * @param list<array> $trends
 * @return list<array>
 */
function supply_merge_consecutive_trends(array $trends): array
{
    if (count($trends) < 2) {
        foreach ($trends as &$t) {
            $t['short_line'] = supply_alert_short_line($t);
        }
        unset($t);
        return $trends;
    }
    usort($trends, static function ($a, $b) {
        $c = strcmp((string)$a['start_week'], (string)$b['start_week']);
        if ($c !== 0) {
            return $c;
        }
        return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
    });
    $out = [];
    foreach ($trends as $t) {
        if (!$out) {
            $out[] = $t;
            continue;
        }
        $prevIdx = count($out) - 1;
        $prev = $out[$prevIdx];
        $same = (supply_trend_kind_is_expand($prev) && supply_trend_kind_is_expand($t))
            || (supply_trend_kind_is_tighten($prev) && supply_trend_kind_is_tighten($t));
        $nextStart = date('Y-m-d', strtotime((string)$prev['end_week'] . ' +7 days'));
        $adjacent = (string)$t['start_week'] <= $nextStart;
        if (!$same || !$adjacent) {
            $out[] = $t;
            continue;
        }
        $end = (string)$t['end_week'] > (string)$prev['end_week'] ? (string)$t['end_week'] : (string)$prev['end_week'];
        $weeks = (int)round((strtotime($end) - strtotime((string)$prev['start_week'])) / 86400 / 7) + 1;
        $w1 = max(1, (int)($prev['weeks'] ?? 1));
        $w2 = max(1, (int)($t['weeks'] ?? 1));
        $per = round(
            (((float)$prev['kg_per_week'] * $w1) + ((float)$t['kg_per_week'] * $w2)) / ($w1 + $w2),
            0
        );
        $prev['end_week'] = $end;
        $prev['weeks'] = $weeks;
        $prev['kg_per_week'] = $per;
        $prev['total_kg'] = round($per * $weeks, 0);
        $prev['short_line'] = supply_alert_short_line($prev);
        $out[$prevIdx] = $prev;
    }
    foreach ($out as &$t) {
        $t['short_line'] = supply_alert_short_line($t);
    }
    unset($t);
    return $out;
}

/**
 * 減少トレンドが余剰在庫で賄えるか、ベッドあたり何kgを下回ると累計が割れるか。
 * 前提: 収穫後 GF_REPLANT_GRACE_DAYS 日で次定植する計画能力。
 *
 * @param list<array> $cumWeeks
 * @return array{covered:bool,y0:float,y_break:float,min_cum:float,min_week:?string,beds_hint:float,note:string}
 */
function supply_tighten_cover_analysis(mysqli $link, array $cumWeeks, string $startWeek, string $endWeek): array
{
    $defaults = plant_schedule_season_defaults($link);
    $y0 = (float)($defaults['yield'] ?? 0);
    $nBeds = 0;
    $sumKg = 0.0;
    $sql = "
SELECT COALESCE(pr.postproc_total_kg, pr.pred_total_kg) AS kg
FROM cycles c
JOIN beds b ON b.id = c.bed_id AND b.active = 1
JOIN predictions pr ON pr.cycle_id = c.id
 AND NOT EXISTS (
   SELECT 1 FROM predictions p2 WHERE p2.cycle_id = pr.cycle_id AND p2.created_at > pr.created_at
 )
WHERE c.harvest_end IS NULL AND pr.pred_days IS NOT NULL
  AND DATE_SUB(
        DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY),
        INTERVAL (DAYOFWEEK(DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY)) - 1) DAY
      ) BETWEEN ? AND ?
";
    $stmt = mysqli_prepare($link, $sql);
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ss', $startWeek, $endWeek);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $kg = (float)$row['kg'];
            if ($kg <= 0) {
                continue;
            }
            $sumKg += $kg;
            $nBeds++;
        }
        mysqli_stmt_close($stmt);
    }
    if ($nBeds >= 3) {
        $y0 = $sumKg / $nBeds;
    }
    if ($y0 < 20) {
        $y0 = 130.0;
    }

    $from = false;
    $minCum = null;
    $minWeek = null;
    $capToMin = 0.0;
    $accCap = 0.0;
    foreach ($cumWeeks as $w) {
        $wk = (string)($w['week'] ?? '');
        if ($wk === $startWeek) {
            $from = true;
        }
        if (!$from) {
            continue;
        }
        $accCap += (float)($w['capacity_kg'] ?? 0);
        $cum = (float)($w['cum_surplus_kg'] ?? 0);
        if ($minCum === null || $cum < $minCum) {
            $minCum = $cum;
            $minWeek = $wk;
            $capToMin = $accCap;
        }
    }
    if ($minCum === null) {
        $minCum = 0.0;
    }

    $covered = $minCum > 1.0;
    $yBreak = $y0;
    if ($covered && $capToMin > 10) {
        $yBreak = $y0 * (1.0 - $minCum / $capToMin);
    }
    $yBreak = max(0.0, round($yBreak, 0));
    $y0r = round($y0, 0);
    $grace = (int)GF_REPLANT_GRACE_DAYS;

    if ($covered) {
        if ($yBreak <= 0) {
            $note = sprintf(
                '収穫後%d日で次定植する計画なら、いまの累計余剰（最低 +%dkg・%s）だけで出荷を賄える。ベッドあたり収穫がゼロでも、この期間の累計は割れない。',
                $grace,
                (int)round($minCum, 0),
                $minWeek ? supply_week_label($minWeek) : ''
            );
        } else {
            $note = sprintf(
                '収穫後%d日で次定植する計画なら、いまの累計余剰（最低 +%dkg・%s）で賄える。ベッドあたり収穫量が現状 %dkg から %dkg を下回ると、累計在庫がマイナスになるリスクが顕在化する。',
                $grace,
                (int)round($minCum, 0),
                $minWeek ? supply_week_label($minWeek) : '',
                (int)$y0r,
                (int)$yBreak
            );
        }
    } else {
        $note = sprintf(
            '収穫後%d日定植の計画でも、累計余剰は賄いきれない（最低 %dkg%s）。ベッドあたり現状 %dkg。',
            $grace,
            (int)round($minCum, 0),
            $minWeek ? '・' . supply_week_label($minWeek) : '',
            (int)$y0r
        );
    }

    return [
        'covered' => $covered,
        'y0' => (float)$y0r,
        'y_break' => (float)$yBreak,
        'min_cum' => round((float)$minCum, 0),
        'min_week' => $minWeek,
        'beds_hint' => (float)$nBeds,
        'note' => $note,
    ];
}

/**
 * GCAL確定にトレンド提案を載せた一次シミュレーション（kg/週）
 *
 * @param list<array{week:string,gcal_kg?:float,ship_kg?:float}> $weekRows
 * @param list<array> $actions
 * @return list<float>
 */
function supply_sim_commit_series(array $weekRows, array $actions, string $which = 'spot'): array
{
    $wantSpot = ($which === 'spot');
    $adj = [];
    foreach ($actions as $a) {
        if (($a['kind'] ?? '') === 'exec' || ($a['type'] ?? '') === 'plant_delay') {
            continue;
        }
        $isSpot = ($a['kind'] ?? '') === 'spot' || ($a['type'] ?? '') === 'spot_surplus';
        if ($wantSpot !== $isSpot) {
            continue;
        }
        $isTighten = in_array(($a['type'] ?? ''), ['commit_tighten', 'trend_tighten'], true);
        $delta = ($isTighten ? -1.0 : 1.0) * (float)($a['kg_per_week'] ?? 0);
        $ts = strtotime((string)$a['start_week']);
        $te = strtotime((string)($a['end_week'] ?? $a['start_week']));
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
 * 需給ページの累計余剰線を、予測ページ（既存株ベース）の残高に
 * 常時回転・予定定植の増分を継続加算して作る。
 *
 * 予測側 supply_inventory_surplus_rows() の surplus_kg（経過週から続く実績ベースの
 * 累計）をそのまま土台にし、そこへ週ごとの planned_kg + rotation_kg（＝まだ cycles
 * に登録されていない、常時回転で仮定する将来の定植分）を積み増していく。
 * どちらの成分も「その週までの累計」として同じループで連続的に積み上げるため、
 * 予測ページとの境界で数字が飛ぶことがない。データが無い週は直前の残高を維持する
 * （その週の実績デルタを0とみなす）。
 *
 * @param list<array{week:string,planned_kg?:float,rotation_kg?:float}> $weekSlice
 * @param bool $withRotation true=計画累計（土台＋将来定植）。false=定植済累計（同じ土台のみ）
 * @return list<float>
 */
function supply_cum_surplus_continuous(mysqli $link, array $weekSlice, bool $withRotation = true): array
{
    if (!$weekSlice) {
        return [];
    }
    $horizonEnd = $weekSlice[count($weekSlice) - 1]['week'];
    $firstWeek = $weekSlice[0]['week'];
    $invRows = supply_inventory_surplus_rows($link, $horizonEnd);
    $invByWeek = [];
    $lastInv = 0.0;
    foreach ($invRows as $r) {
        $invByWeek[$r['week_start_date']] = (float)$r['surplus_kg'];
        if ($r['week_start_date'] < $firstWeek) {
            $lastInv = (float)$r['surplus_kg'];
        }
    }

    $out = [];
    $rotPlanCum = 0.0;
    foreach ($weekSlice as $w) {
        $wk = $w['week'];
        if (isset($invByWeek[$wk])) {
            $lastInv = $invByWeek[$wk];
        }
        if ($withRotation) {
            $rotPlanCum += (float)($w['planned_kg'] ?? 0) + (float)($w['rotation_kg'] ?? 0);
        }
        $out[] = round($lastInv + $rotPlanCum, 1);
    }
    return $out;
}

/**
 * トレンド平準化シミュレーション（一時は使わない）
 * 数か月の増減合計を期間で均し、strength で弱/強を切り替える。
 *
 * @param list<array{week:string,gcal_kg?:float,ship_kg?:float}> $weekRows
 * @param list<array> $actions
 * @param float $strength 1.0=強（満額） / 0.5=弱
 * @return array{series:list<float>,per_week:float,level_end:string,mode:string,note:string,strength:float}
 */
function supply_sim_level_series(array $weekRows, array $actions, ?string $levelEndWeek = null, float $strength = 1.0): array
{
    $raw = supply_sim_commit_series($weekRows, $actions, 'trend');
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
            'strength' => $strength,
        ];
    }

    $strength = max(0.1, min(1.0, $strength));
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
    $label = $strength < 0.99 ? '弱' : '強';

    if ($firstPos !== null && $extraTotal > 0) {
        $start = $firstPos;
        if ($endIdx < $start) {
            $endIdx = $n - 1;
        }
        $len = max(1, $endIdx - $start + 1);
        $perWeek = round($extraTotal / $len * $strength, 1);
        for ($i = $start; $i <= $endIdx; $i++) {
            $out[$i] = round($gcals[$i] + $perWeek, 1);
        }
        for ($i = $endIdx + 1; $i < $n; $i++) {
            $out[$i] = round($gcals[$i] + $perWeek, 1);
        }
        $noteParts[] = sprintf(
            '拡大を平準化（%s）: %s〜%s 週あたり +%.0fkg',
            $label,
            format_sunday_week((string)$weekRows[$start]['week']),
            format_sunday_week((string)$weekRows[$endIdx]['week']),
            $perWeek
        );
    }

    if ($firstNeg !== null && $deficitTotal > 0) {
        $start = $firstNeg;
        if ($endIdx < $start) {
            $endIdx = $n - 1;
        }
        $len = max(1, $endIdx - $start + 1);
        $negPer = round($deficitTotal / $len * $strength, 1);
        for ($i = $start; $i <= $endIdx; $i++) {
            $out[$i] = round(max(0.0, $out[$i] - $negPer), 1);
        }
        for ($i = $endIdx + 1; $i < $n; $i++) {
            $out[$i] = round(max(0.0, $out[$i] - $negPer), 1);
        }
        $noteParts[] = sprintf('絞りを平準化（%s）: 週あたり −%.0fkg', $label, $negPer);
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
        'strength' => $strength,
    ];
}

/**
 * 定植計画に対する遅れ（収穫後猶予を過ぎた未実施）
 *
 * @return list<array{
 *   bed_id:int,bed_name:string,planned_plant_date:?string,last_end:?string,
 *   delay_days:int,kind:string,schedule_id:?int
 * }>
 */
function supply_plant_delay_rows(mysqli $link): array
{
    $today = date('Y-m-d');
    $grace = GF_REPLANT_GRACE_DAYS;
    $rows = [];
    $seen = [];

    $chk = mysqli_query($link, "SHOW TABLES LIKE 'plant_schedule'");
    $has = $chk && mysqli_num_rows($chk) > 0;
    if ($chk) {
        mysqli_free_result($chk);
    }

    if ($has) {
        $sql = "
SELECT b.id AS bed_id, b.name AS bed_name, s.id AS schedule_id, s.planned_plant_date,
       (SELECT MAX(c.harvest_end) FROM cycles c WHERE c.bed_id = b.id) AS last_end
FROM beds b
JOIN plant_schedule s ON s.bed_id = b.id AND s.status IN ('planned','approved')
WHERE b.active = 1
  AND s.planned_plant_date < ?
  AND NOT EXISTS (
    SELECT 1 FROM cycles c2 WHERE c2.bed_id = b.id AND c2.harvest_end IS NULL
  )
ORDER BY s.planned_plant_date ASC, b.name ASC
";
        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param($stmt, 's', $today);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $bedId = (int)$row['bed_id'];
            if (isset($seen[$bedId])) {
                continue;
            }
            $planned = (string)$row['planned_plant_date'];
            $last = $row['last_end'];
            // 運営指標は空き後の猶予超過（予定日との差ではない）
            if ($last) {
                $due = date('Y-m-d', strtotime($last . ' +' . $grace . ' days'));
                $delay = (int)floor((strtotime($today) - strtotime($due)) / 86400);
            } else {
                $delay = (int)floor((strtotime($today) - strtotime($planned)) / 86400);
            }
            if ($delay < 1) {
                continue;
            }
            $rows[] = [
                'bed_id' => $bedId,
                'bed_name' => (string)$row['bed_name'],
                'planned_plant_date' => $planned,
                'last_end' => $last,
                'delay_days' => $delay,
                'kind' => 'late_schedule',
                'schedule_id' => (int)$row['schedule_id'],
            ];
            $seen[$bedId] = true;
        }
        mysqli_stmt_close($stmt);
    }

    $sqlEmpty = "
SELECT b.id AS bed_id, b.name AS bed_name,
       (SELECT MAX(c.harvest_end) FROM cycles c WHERE c.bed_id = b.id) AS last_end
FROM beds b
WHERE b.active = 1
  AND NOT EXISTS (SELECT 1 FROM cycles c WHERE c.bed_id = b.id AND c.harvest_end IS NULL)
";
    $res = mysqli_query($link, $sqlEmpty);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $bedId = (int)$row['bed_id'];
            if (isset($seen[$bedId])) {
                continue;
            }
            $last = $row['last_end'];
            if (!$last) {
                continue;
            }
            $due = date('Y-m-d', strtotime($last . ' +' . $grace . ' days'));
            if ($due >= $today) {
                continue;
            }
            $delay = (int)floor((strtotime($today) - strtotime($due)) / 86400);
            if ($delay < 1) {
                continue;
            }
            $rows[] = [
                'bed_id' => $bedId,
                'bed_name' => (string)$row['bed_name'],
                'planned_plant_date' => $due,
                'last_end' => $last,
                'delay_days' => $delay,
                'kind' => 'idle_empty',
                'schedule_id' => null,
            ];
            $seen[$bedId] = true;
        }
        mysqli_free_result($res);
    }

    usort($rows, static function ($a, $b) {
        return ($b['delay_days'] <=> $a['delay_days']) ?: strcmp($a['bed_name'], $b['bed_name']);
    });
    return $rows;
}

function supply_week_is_cold_season(string $week): bool
{
    $m = (int)date('n', strtotime($week));
    return $m >= 10 || $m <= 2;
}

/**
 * 計画累計から見て、その週の余りを先の拡大として話してよいか。
 * 当週バッファの放出とは別。後週の計画累計が割れないこと。
 */
function supply_plan_can_recommend_expand(array $withRot, string $week, float $releaseKg): bool
{
    $from = false;
    $weekDelta = null;
    $minCum = null;
    foreach ($withRot as $w) {
        if ((string)($w['week'] ?? '') === $week) {
            $from = true;
            $weekDelta = (float)($w['week_delta_kg'] ?? 0);
        }
        if (!$from) {
            continue;
        }
        $c = (float)($w['cum_surplus_kg'] ?? 0);
        if ($minCum === null || $c < $minCum) {
            $minCum = $c;
        }
    }
    if (!$from || $weekDelta === null || $weekDelta < 80) {
        return false;
    }
    if ($minCum === null || $minCum < $releaseKg) {
        return false;
    }
    return true;
}

function supply_near_week_cutoff(): string
{
    $cur = gcal_week_start_sunday(date('Y-m-d'));
    return date('Y-m-d', strtotime($cur . ' +' . (GF_PLAN_EXPAND_LEAD_WEEKS - 1) * 7 . ' days'));
}

/**
 * 定植済累計から見て、その週のプラスをスポット放出してよいか。
 * 回転・予定定植の山だけでは放出不可。後週の定植済出荷が割れるなら不可。
 */
function supply_planted_can_release_spot(array $openOnly, string $week, float $releaseKg): bool
{
    $from = false;
    $weekDelta = null;
    $minCum = null;
    foreach ($openOnly as $w) {
        if ((string)($w['week'] ?? '') === $week) {
            $from = true;
            $weekDelta = (float)($w['week_delta_kg'] ?? 0);
        }
        if (!$from) {
            continue;
        }
        $c = (float)($w['cum_surplus_kg'] ?? 0);
        if ($minCum === null || $c < $minCum) {
            $minCum = $c;
        }
    }
    if (!$from || $weekDelta === null || $weekDelta < 80) {
        return false;
    }
    if ($minCum === null || $minCum < $releaseKg) {
        return false;
    }
    return true;
}

/**
 * 週次デルタを一時/トレンドに分類し、営業トークを付与
 *
 * @param list<array> $cumWeeks trust_attach_cumulative 結果（回転込み）
 * @param list<array>|null $openOnly 定植済のみ。null ならここで作る
 * @return array{spot:list,trends:list,actions:list,grace_days:int,plant_delays:list}
 */
function supply_classify_surplus_deficit(mysqli $link, array $cumWeeks, ?array $openOnly = null): array
{
    if ($openOnly === null) {
        $openOnly = trust_cumulative_open_only($link, max(16, count($cumWeeks) + 2));
    }
    $grace = supply_pred_harvest_grace_days($link);
    $delays = supply_plant_delay_rows($link);
    $delayN = count($delays);
    $plantExecLate = $delayN;
    $spot = [];
    $trends = [];
    $nearCutoff = supply_near_week_cutoff();

    // --- トレンド: 拡大は4週、減少は3週から（低温期を早めに） ---
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
        $needLen = $sign < 0 ? GF_TREND_TIGHTEN_MIN_WEEKS : GF_TREND_MIN_WEEKS;
        if ($len >= $needLen) {
            $deltas = [];
            for ($k = $i; $k <= $end; $k++) {
                $deltas[] = (float)$cumWeeks[$k]['week_delta_kg'];
            }
            $avg = array_sum($deltas) / count($deltas);
            $perWeek = round(abs($avg), 0);
            $start = $cumWeeks[$i]['week'];
            $finish = $cumWeeks[$end]['week'];
            $type = $sign > 0 ? 'trend_expand' : 'trend_tighten';
            $cold = $sign < 0 && supply_week_is_cold_season($start);
            $label = $sign > 0
                ? '計画どおりなら出荷拡大'
                : ($cold ? '低温期の減少を先行察知' : 'ベース栽培量の減少トレンド');
            $talk = $sign > 0
                ? sprintf(
                    '%sから、週あたり＋%.0fkgでの対応が可能となります。いかがですか？',
                    supply_week_label($start),
                    $perWeek
                )
                : sprintf(
                    '%sから、週あたり−%.0fkgでの対応となります。ご調整お願いします。',
                    supply_week_label($start),
                    $perWeek
                );
            $short = supply_alert_short_line([
                'kind' => 'trend',
                'type' => $type,
                'start_week' => $start,
                'end_week' => $finish,
                'weeks' => $len,
                'kg_per_week' => $perWeek,
            ]);
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
                    '%s〜%s（%d週間連続・計画ライン）。%s',
                    supply_week_label($start),
                    supply_week_label($finish),
                    $len,
                    $sign > 0
                        ? '定植が計画どおりなら拡大を先行提案。当週の定植済バッファは売らない。'
                        : ($cold
                            ? '低温期に向かう減少。割れを待たず仲卸へ先に伝達。'
                            : '一時変動ではなくベース減。仲卸には数か月見通しとして先行共有。')
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

    $planExpandN = 0;
    foreach ($cumWeeks as $idx => $w) {
        $week = $w['week'];
        if (isset($covered[$week])) {
            continue;
        }
        $delta = (float)$w['week_delta_kg'];
        if ($delta < 80) {
            continue;
        }
        $isNear = $week <= $nearCutoff;

        if ($isNear) {
            $openDelta = null;
            foreach ($openOnly as $ow) {
                if ((string)($ow['week'] ?? '') === $week) {
                    $openDelta = (float)($ow['week_delta_kg'] ?? 0);
                    break;
                }
            }
            $releaseKg = $openDelta !== null ? min($delta, $openDelta) : $delta;
            // 当週〜翌々週は定植済バッファを売らない（9月の持ち越しを放出しない）
            if (!supply_planted_can_release_spot($openOnly, $week, max($releaseKg, 80))) {
                continue;
            }
            $delta = $releaseKg;
            $nearHarvestKg = supply_open_harvest_kg_within_days($link, $week, $grace);
            if ($nearHarvestKg < 40 && (float)($w['open_kg'] ?? 0) < 40) {
                if ($delta < 120) {
                    continue;
                }
            }
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
                'short_line' => supply_week_label($week) . ' +' . (int)round($delta, 0) . 'kg',
                'grace_days' => $grace,
                'near_harvest_kg' => $nearHarvestKg,
            ];
            continue;
        }

        // 先々: 計画ラインの余り。定植遅れが多ければ拡大は出さない
        if ($plantExecLate >= 3) {
            continue;
        }
        if ($planExpandN >= 6) {
            continue;
        }
        if (!supply_plan_can_recommend_expand($cumWeeks, $week, $delta)) {
            continue;
        }
        $planExpandN++;
        $trends[] = [
            'kind' => 'trend',
            'type' => 'trend_expand',
            'urgency' => 'ok',
            'priority' => 45,
            'start_week' => $week,
            'end_week' => $week,
            'weeks' => 1,
            'kg_per_week' => round($delta, 0),
            'total_kg' => round($delta, 0),
            'label' => '計画上の拡大チャンス',
            'detail' => sprintf(
                '%sは計画どおりに定植が回れば約%.0fkgの余り。先行商談可。いまの畑の在庫ではない。',
                supply_week_label($week),
                $delta
            ),
            'sales_talk' => sprintf(
                '%sから、週あたり＋%.0fkgでの対応が可能となります。いかがですか？',
                supply_week_label($week),
                $delta
            ),
            'short_line' => supply_week_label($week) . ' +' . (int)round($delta, 0) . 'kg',
        ];
        $covered[$week] = true;
    }

    // 在庫割れがある場合の減少トレンドが無ければ、割れ起点のtightenを補完
    $sum = trust_break_summary($cumWeeks);
    $hasTighten = (bool)array_filter($trends, static fn($t) => $t['type'] === 'trend_tighten');
    if ($sum['first_break_week'] && !$hasTighten) {
        $idx = (int)$sum['first_break_index'];
        $startIdx = max(0, $idx - 1);
        $endIdx = min($n - 1, $startIdx + max(GF_TREND_TIGHTEN_MIN_WEEKS, 6) - 1);
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
                '%sから、週あたり−%.0fkgでの対応となります。ご調整お願いします。',
                supply_week_label($start),
                $perWeek
            ),
            'short_line' => '',
            'break_week' => $sum['first_break_week'],
        ];
        $hasTighten = true;
    }

    // 計画能力そのものが連続して落ちる（開始週が低温期なら詳細にだけ書く）
    $earliestTighten = null;
    foreach ($trends as $t) {
        if (($t['type'] ?? '') === 'trend_tighten') {
            if ($earliestTighten === null || $t['start_week'] < $earliestTighten) {
                $earliestTighten = $t['start_week'];
            }
        }
    }
    $dropStart = null;
    $dropEnd = null;
    $dropKg = [];
    for ($k = 2; $k < $n; $k++) {
        $prevCap = (float)($cumWeeks[$k - 1]['capacity_kg'] ?? 0);
        $cap = (float)($cumWeeks[$k]['capacity_kg'] ?? 0);
        $drop = $prevCap - $cap;
        if ($drop >= 40) {
            if ($dropStart === null) {
                $dropStart = $k;
            }
            $dropEnd = $k;
            $dropKg[] = $drop;
        } elseif ($dropStart !== null) {
            break;
        }
    }
    if ($dropStart !== null && $dropEnd !== null) {
        $dropLen = $dropEnd - $dropStart + 1;
        $startW = $cumWeeks[$dropStart]['week'];
        $endW = $cumWeeks[$dropEnd]['week'];
        $cold = supply_week_is_cold_season($startW);
        $want = ($cold && $dropLen >= 2) || $dropLen >= 3;
        $earlier = $earliestTighten === null || $startW < $earliestTighten;
        if ($want && $earlier) {
            $perWeek = round(array_sum($dropKg) / max(1, count($dropKg)), 0);
            $row = [
                'kind' => 'trend',
                'type' => 'trend_tighten',
                'urgency' => 'critical',
                'priority' => 105,
                'start_week' => $startW,
                'end_week' => $endW,
                'weeks' => $dropLen,
                'kg_per_week' => $perWeek,
                'total_kg' => round($perWeek * $dropLen, 0),
                'label' => $cold ? '低温期の能力減少を先行察知' : '計画能力の減少トレンド',
                'detail' => $cold
                    ? sprintf('計画能力が%sから連続して落ちる。低温期の減として仲卸へ先に伝達。', supply_week_label($startW))
                    : sprintf('計画能力が%s〜%sで連続して落ちる。一時ではなくトレンド。', supply_week_label($startW), supply_week_label($endW)),
                'sales_talk' => sprintf(
                    '%sから、週あたり−%.0fkgでの対応となります。ご調整お願いします。',
                    supply_week_label($startW),
                    $perWeek
                ),
            ];
            $row['short_line'] = supply_alert_short_line($row);
            $trends[] = $row;
        }
    }

    $trends = supply_merge_consecutive_trends($trends);
    foreach ($trends as &$t) {
        if (!supply_trend_kind_is_tighten($t)) {
            continue;
        }
        $an = supply_tighten_cover_analysis(
            $link,
            $cumWeeks,
            (string)$t['start_week'],
            (string)$t['end_week']
        );
        $t['cover'] = $an;
        if ($an['covered']) {
            $t['urgency'] = 'warn';
        }
        $t['short_line'] = supply_alert_short_line($t);
    }
    unset($t);
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
    foreach ($delays as $d) {
        $planned = (string)($d['planned_plant_date'] ?? date('Y-m-d'));
        $lastEnd = $d['last_end'] ?? null;
        $wk = gcal_week_start_sunday($planned);
        $kindLabel = '定植遅れ';
        $emptyDays = null;
        if ($lastEnd) {
            $emptyDays = (int)floor((strtotime(date('Y-m-d')) - strtotime((string)$lastEnd)) / 86400);
        }
        $detail = $emptyDays !== null
            ? sprintf(
                '%s は空き%d日（猶予%d日を %d日超過）。空き後%d日以内の定植が崩れると、先の計画能力が届かない。',
                $d['bed_name'],
                $emptyDays,
                GF_REPLANT_GRACE_DAYS,
                (int)$d['delay_days'],
                GF_REPLANT_GRACE_DAYS
            )
            : sprintf(
                '%s は定植が %d日遅れ。収穫後%d日以内の定植が崩れると、先の計画能力が届かない。',
                $d['bed_name'],
                (int)$d['delay_days'],
                GF_REPLANT_GRACE_DAYS
            );
        $actions[] = [
            'type' => 'plant_delay',
            'kind' => 'exec',
            'urgency' => ((int)$d['delay_days'] >= 3) ? 'critical' : 'warn',
            'start_week' => $wk,
            'end_week' => $wk,
            'weeks' => 1,
            'kg_per_week' => 0,
            'total_kg' => 0,
            'label' => $kindLabel,
            'detail' => $detail,
            'sales_talk' => '',
            'short_line' => $emptyDays !== null
                ? sprintf('%s 空き%d日（猶予超過）', $d['bed_name'], $emptyDays)
                : sprintf('%s 定植 %d日遅れ', $d['bed_name'], (int)$d['delay_days']),
            'break_week' => null,
            'runway_weeks' => $sum['runway_weeks'],
            'bed_id' => (int)$d['bed_id'],
            'delay_days' => (int)$d['delay_days'],
        ];
    }
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
            'cover' => $t['cover'] ?? null,
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
        $ka = ($a['kind'] ?? '') === 'exec' ? 0 : 1;
        $kb = ($b['kind'] ?? '') === 'exec' ? 0 : 1;
        if ($ka !== $kb) {
            return $ka <=> $kb;
        }
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
        'plant_delays' => $delays,
    ];
}

function supply_week_label(string $week): string
{
    return format_sunday_week($week, $week);
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
    $classified = supply_classify_surplus_deficit($link, $trust['with_rotation'], $trust['open_only']);
    $delays = $classified['plant_delays'] ?? [];
    $delayN = count($delays);
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
    if ($delayN > 0) {
        $health = $delayN >= 3 ? 'critical' : 'warn';
        $notes[] = "定植遅れ {$delayN} ベッド — 計画能力が先で届かなくなる";
    }
    if ($empty > 0) {
        $notes[] = "空きベッド {$empty} — 常時回転の次定植は自動投入。現場が植える";
    }
    if ($sum['zero_weeks'] > 0 && $health === 'ok') {
        $health = 'warn';
        $notes[] = "能力0の週が {$sum['zero_weeks']} — 回転シミュレーションを点検";
    } elseif ($sum['zero_weeks'] > 0) {
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
            'plant_delay_n' => $delayN,
            'zero_capacity_weeks' => $sum['zero_weeks'],
            'overgrow_beds' => $riskN,
            'staff_alerts' => count($staff),
            'trend_n' => count($classified['trends']),
            'spot_n' => count($classified['spot']),
            'pred_mae_kg' => $mae,
            'pred_mape_pct' => $mape,
            'pred_eval_n' => $nEval,
            'first_break_week' => $trust['summary']['first_break_week'],
            'grace_days' => $classified['grace_days'],
            'yoy_miss_weeks' => $sum['yoy_miss_weeks'],
        ],
        'trends' => $classified['trends'],
        'spot' => $classified['spot'],
        'actions' => $classified['actions'],
        'plant_delays' => $delays,
        'seasonal' => $season,
        'dual_lines_sample' => array_slice(supply_dual_week_lines($link, 8), 0, 8),
    ];
}
