<?php
/**
 * 定植スケジュール生成（需要ギャップ → 定植日逆算）
 */
require_once __DIR__ . '/gcal_shipments.php';

/**
 * 定植計画ページの「不足週」（週次）しきい値。
 * その週単体で 出荷 − 収穫予測 がこのkg超 → 不足。
 * ※収穫予測ページの不足は別定義（累計余剰がマイナス）。
 */
const GF_SHORTAGE_THRESHOLD_KG = 20.0;

/**
 * 週次: 収穫予測合計 / 出荷コミット / ギャップ（出荷 − 予測）
 * week_start = 日曜起点
 *
 * @return list<array{week:string,forecast_kg:float,ship_kg:float,gap_kg:float}>
 */
function plant_schedule_weekly_gaps(mysqli $link, int $weeksAhead = 12): array
{
    $today = date('Y-m-d');
    $currentWeek = gcal_week_start_sunday($today);
    $horizonEnd = date('Y-m-d', strtotime('+' . $weeksAhead . ' weeks', strtotime($currentWeek)));

    $forecastByWeek = [];
    $sql = "
SELECT
  DATE_SUB(
    DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY),
    INTERVAL (DAYOFWEEK(DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY)) - 1) DAY
  ) AS week_start_date,
  COALESCE(pr.postproc_total_kg, pr.pred_total_kg) AS forecast_kg
FROM cycles c
JOIN predictions pr ON pr.cycle_id = c.id
 AND NOT EXISTS (
   SELECT 1 FROM predictions p2 WHERE p2.cycle_id = pr.cycle_id AND p2.created_at > pr.created_at
 )
WHERE c.harvest_end IS NULL AND pr.pred_days IS NOT NULL
";
    $res = mysqli_query($link, $sql);
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $w = $row['week_start_date'];
            if ($w < $currentWeek || $w > $horizonEnd) {
                continue;
            }
            if (!isset($forecastByWeek[$w])) {
                $forecastByWeek[$w] = 0.0;
            }
            $forecastByWeek[$w] += (float)$row['forecast_kg'];
        }
        mysqli_free_result($res);
    }

    $shipByWeek = [];
    $res = mysqli_query(
        $link,
        "SELECT week_start_date, source, committed_amount_kg
         FROM calendar_shipments
         WHERE week_start_date BETWEEN '{$currentWeek}' AND '{$horizonEnd}'
         ORDER BY week_start_date ASC, FIELD(source, 'manual', 'plan', 'gcal')"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $w = $row['week_start_date'];
            if (!isset($shipByWeek[$w])) {
                $shipByWeek[$w] = (float)$row['committed_amount_kg'];
            }
        }
        mysqli_free_result($res);
    }

    $weeks = [];
    $ts = strtotime($currentWeek);
    $endTs = strtotime($horizonEnd);
    while ($ts <= $endTs) {
        $w = date('Y-m-d', $ts);
        $fc = (float)($forecastByWeek[$w] ?? 0);
        $sh = (float)($shipByWeek[$w] ?? 0);
        $weeks[] = [
            'week' => $w,
            'forecast_kg' => round($fc, 1),
            'ship_kg' => round($sh, 1),
            'gap_kg' => round($sh - $fc, 1),
        ];
        $ts = strtotime('+7 days', $ts);
    }
    return $weeks;
}

/**
 * 空ベッド一覧（未完了サイクルなし）
 *
 * @return list<array{bed_id:int,name:string,group_type:string}>
 */
function plant_schedule_empty_beds(mysqli $link): array
{
    $sql = "
SELECT b.id AS bed_id, b.name, b.group_type
FROM beds b
WHERE b.active = 1
  AND NOT EXISTS (
    SELECT 1 FROM cycles c
    WHERE c.bed_id = b.id AND c.harvest_end IS NULL
  )
ORDER BY b.group_type ASC, b.name ASC
";
    $res = mysqli_query($link, $sql);
    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = [
            'bed_id' => (int)$row['bed_id'],
            'name' => $row['name'],
            'group_type' => $row['group_type'],
        ];
    }
    mysqli_free_result($res);
    return $out;
}

/**
 * 同季節の平均日数・収量
 * 優先: 直近の実測（定植→初回収穫）→ オープン予測 → 既定値
 *
 * @return array{days:float,yield:float}
 */
function plant_schedule_season_defaults(mysqli $link): array
{
    $days = null;
    $yield = null;

    // 直近4か月の実測生育日（夏場は短縮しやすい）
    $res = mysqli_query(
        $link,
        "SELECT AVG(DATEDIFF(c.harvest_start, c.plant_date)) AS d, COUNT(*) AS n
         FROM cycles c
         WHERE c.harvest_start IS NOT NULL
           AND c.plant_date >= DATE_SUB(CURDATE(), INTERVAL 120 DAY)
           AND DATEDIFF(c.harvest_start, c.plant_date) BETWEEN 20 AND 90"
    );
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($res) {
        mysqli_free_result($res);
    }
    if ($row && (int)$row['n'] >= 5 && $row['d'] !== null) {
        $days = (float)$row['d'];
    }

    $res = mysqli_query(
        $link,
        "SELECT AVG(pr.pred_days) AS d, AVG(COALESCE(pr.postproc_total_kg, pr.pred_total_kg)) AS y
         FROM cycles c
         JOIN predictions pr ON pr.cycle_id = c.id
          AND NOT EXISTS (
            SELECT 1 FROM predictions p2 WHERE p2.cycle_id = pr.cycle_id AND p2.created_at > pr.created_at
          )
         WHERE c.harvest_end IS NULL AND pr.pred_days IS NOT NULL"
    );
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($res) {
        mysqli_free_result($res);
    }
    if ($days === null && $row && $row['d'] !== null) {
        $days = (float)$row['d'];
    }
    if ($row && $row['y'] !== null) {
        $yield = (float)$row['y'];
    }

    if ($days === null || $days < 20 || $days > 90) {
        $days = 35.0;
    }
    if ($yield === null || $yield < 50) {
        $yield = 120.0;
    }
    return ['days' => round($days, 1), 'yield' => round($yield, 1)];
}

/**
 * 定植日 + 想定日数 → 収穫週（日曜起点）
 */
function plant_schedule_harvest_week_from_plant(string $plantDate, float $days): string
{
    $n = max(1, (int)round($days));
    $harvest = date('Y-m-d', strtotime($plantDate . " +{$n} days"));
    return gcal_week_start_sunday($harvest);
}

/**
 * 今日定植した場合に届く最初の収穫週
 */
function plant_schedule_earliest_harvest_week(float $days): string
{
    return plant_schedule_harvest_week_from_plant(date('Y-m-d'), $days);
}

/**
 * 過栽培リスクのある定植月（1-12）を返す。
 *
 * @return array<int,true>
 */
function plant_schedule_risk_months(mysqli $link): array
{
    require_once __DIR__ . '/overgrow_metrics.php';
    $months = [];
    foreach (overgrow_weekly_stats($link, 24) as $w) {
        if (!(int)$w['risk']) {
            continue;
        }
        $m = (int)date('n', strtotime($w['week']));
        $months[$m] = true;
    }
    return $months;
}

/**
 * planned/approved の既存割当 bed_id セット
 *
 * @return array<int,true>
 */
function plant_schedule_reserved_beds(mysqli $link): array
{
    $res = mysqli_query(
        $link,
        "SELECT bed_id FROM plant_schedule
         WHERE status IN ('planned','approved')
           AND planned_plant_date >= CURDATE()"
    );
    $out = [];
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $out[(int)$row['bed_id']] = true;
        }
        mysqli_free_result($res);
    }
    return $out;
}

/**
 * planned/approved の target_harvest_week を「定植日+想定日数」に合わせて直す
 *
 * @return int 更新件数
 */
function plant_schedule_repair_targets(mysqli $link): int
{
    $defaults = plant_schedule_season_defaults($link);
    $res = mysqli_query(
        $link,
        "SELECT id, planned_plant_date, expected_days, target_harvest_week
         FROM plant_schedule
         WHERE status IN ('planned','approved')"
    );
    if (!$res) {
        return 0;
    }
    $updated = 0;
    $stmt = mysqli_prepare(
        $link,
        "UPDATE plant_schedule SET target_harvest_week = ?, expected_days = ? WHERE id = ?"
    );
    while ($row = mysqli_fetch_assoc($res)) {
        $days = $row['expected_days'] !== null ? (float)$row['expected_days'] : (float)$defaults['days'];
        if ($days < 20 || $days > 90) {
            $days = (float)$defaults['days'];
        }
        $week = plant_schedule_harvest_week_from_plant($row['planned_plant_date'], $days);
        if ($week === $row['target_harvest_week'] && (float)($row['expected_days'] ?? 0) === $days) {
            continue;
        }
        $id = (int)$row['id'];
        mysqli_stmt_bind_param($stmt, 'sdi', $week, $days, $id);
        mysqli_stmt_execute($stmt);
        $updated++;
    }
    mysqli_free_result($res);
    mysqli_stmt_close($stmt);
    return $updated;
}

/**
 * planned を消してから作り直す（approved は残す）
 *
 * @return array{cleared:int,created:int,repaired:int,skipped_late:int,defaults:array,available_left:int}
 */
function plant_schedule_regenerate(mysqli $link, int $weeksAhead = 12): array
{
    $cleared = 0;
    $res = mysqli_query($link, "DELETE FROM plant_schedule WHERE status = 'planned'");
    if ($res) {
        $cleared = mysqli_affected_rows($link);
    }
    $repaired = plant_schedule_repair_targets($link);
    $gen = plant_schedule_generate($link, $weeksAhead);
    return [
        'cleared' => $cleared,
        'created' => $gen['created'],
        'repaired' => $repaired,
        'skipped_late' => $gen['skipped_late'] ?? 0,
        'defaults' => $gen['defaults'],
        'available_left' => $gen['available_left'] ?? 0,
    ];
}

/**
 * ギャップから推奨定植案を生成して INSERT（既存 planned は消さない。追加のみ）
 *
 * @return array{created:int,skipped_gap:int,skipped_late:int,defaults:array,available_left:int,earliest_harvest_week:string}
 */
function plant_schedule_generate(mysqli $link, int $weeksAhead = 12): array
{
    $defaults = plant_schedule_season_defaults($link);
    $avgDays = $defaults['days'];
    $avgYield = max(1.0, $defaults['yield']);
    $riskMonths = plant_schedule_risk_months($link);
    $empty = plant_schedule_empty_beds($link);
    $reserved = plant_schedule_reserved_beds($link);
    $available = [];
    foreach ($empty as $b) {
        if (!isset($reserved[$b['bed_id']])) {
            $available[] = $b;
        }
    }

    $created = 0;
    $skippedGap = 0;
    $skippedLate = 0;
    $today = date('Y-m-d');
    $earliestWeek = plant_schedule_earliest_harvest_week($avgDays);
    $gaps = plant_schedule_weekly_gaps($link, $weeksAhead);

    // 到達可能な週だけ、ギャップ大きい順
    $reachable = [];
    foreach ($gaps as $g) {
        if ($g['gap_kg'] <= GF_SHORTAGE_THRESHOLD_KG) {
            $skippedGap++;
            continue;
        }
        if ($g['week'] < $earliestWeek) {
            // 今日定植してもこの週には届かない
            $skippedLate++;
            continue;
        }
        $reachable[] = $g;
    }
    usort($reachable, static function ($a, $b) {
        return $b['gap_kg'] <=> $a['gap_kg'];
    });

    $stmt = mysqli_prepare(
        $link,
        "INSERT INTO plant_schedule
          (planned_plant_date, bed_id, status, target_harvest_week, gap_kg, expected_yield_kg, expected_days, note)
         VALUES (?, ?, 'planned', ?, ?, ?, ?, ?)"
    );

    foreach ($reachable as $g) {
        $needBeds = (int)ceil($g['gap_kg'] / $avgYield);
        if ($needBeds < 1) {
            continue;
        }
        // 収穫希望週の中央 − 想定日数 = 定植日
        $harvestMid = date('Y-m-d', strtotime($g['week'] . ' +3 days'));
        $plantDate = date('Y-m-d', strtotime($harvestMid . ' -' . (int)round($avgDays) . ' days'));
        $note = '';
        if ($plantDate < $today) {
            $plantDate = $today;
            $note = '定植日を今日に繰り上げ';
        }
        // 実収穫週（定植日基準）。目標週に届かないならこの不足は埋められない
        $actualWeek = plant_schedule_harvest_week_from_plant($plantDate, $avgDays);
        if ($actualWeek > $g['week']) {
            $skippedLate++;
            continue;
        }
        $targetWeek = $actualWeek;

        $plantMonth = (int)date('n', strtotime($plantDate));
        if (isset($riskMonths[$plantMonth])) {
            $needBeds = max(1, (int)ceil($needBeds / 2));
            $note = ($note !== '' ? $note . ' / ' : '') . '過栽培リスク月・間引き済';
        }

        for ($i = 0; $i < $needBeds; $i++) {
            if (!$available) {
                break 2;
            }
            $bed = array_shift($available);
            $noteVal = $note;
            mysqli_stmt_bind_param(
                $stmt,
                'sisddds',
                $plantDate,
                $bed['bed_id'],
                $targetWeek,
                $g['gap_kg'],
                $avgYield,
                $avgDays,
                $noteVal
            );
            mysqli_stmt_execute($stmt);
            $created++;
        }
    }
    mysqli_stmt_close($stmt);

    return [
        'created' => $created,
        'skipped_gap' => $skippedGap,
        'skipped_late' => $skippedLate,
        'defaults' => $defaults,
        'available_left' => count($available),
        'earliest_harvest_week' => $earliestWeek,
    ];
}

/**
 * @return list<array>
 */
function plant_schedule_list(mysqli $link, ?string $from = null, ?string $to = null): array
{
    $from = $from ?: date('Y-m-d', strtotime('-7 days'));
    $to = $to ?: date('Y-m-d', strtotime('+8 weeks'));
    $sql = "
SELECT s.*, b.name AS bed_name, b.group_type
FROM plant_schedule s
JOIN beds b ON b.id = s.bed_id
WHERE s.planned_plant_date BETWEEN ? AND ?
ORDER BY s.planned_plant_date ASC, b.name ASC
";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $from, $to);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $out[] = $row;
    }
    mysqli_stmt_close($stmt);
    return $out;
}

function plant_schedule_set_status(mysqli $link, int $id, string $status): bool
{
    $allowed = ['planned', 'approved', 'done', 'skipped'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    $stmt = mysqli_prepare($link, "UPDATE plant_schedule SET status = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'si', $status, $id);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $ok;
}

/**
 * planned/approved の期待収量を収穫週に載せたシミュレーション
 *
 * @return array{
 *   weeks: list<array>,
 *   planned_n: int,
 *   shortage_before: int,
 *   shortage_after: int,
 *   defaults: array{days:float,yield:float}
 * }
 */
function plant_schedule_simulate(mysqli $link, int $weeksAhead = 12, ?float $gapThresh = null): array
{
    $gapThresh = $gapThresh ?? GF_SHORTAGE_THRESHOLD_KG;
    $defaults = plant_schedule_season_defaults($link);
    $gaps = plant_schedule_weekly_gaps($link, $weeksAhead);
    $adds = [];
    $plansByWeek = [];
    $plannedN = 0;

    $chk = mysqli_query($link, "SHOW TABLES LIKE 'plant_schedule'");
    $has = $chk && mysqli_num_rows($chk) > 0;
    if ($chk) {
        mysqli_free_result($chk);
    }
    if ($has) {
        $sql = "
SELECT s.*, b.name AS bed_name, b.group_type
FROM plant_schedule s
JOIN beds b ON b.id = s.bed_id
WHERE s.status IN ('planned','approved')
ORDER BY s.planned_plant_date ASC, b.name ASC
";
        $res = mysqli_query($link, $sql);
        if ($res) {
            while ($row = mysqli_fetch_assoc($res)) {
                $days = $row['expected_days'] !== null
                    ? (float)$row['expected_days']
                    : (float)$defaults['days'];
                // 定植日基準の実収穫週に載せる（古い target のズレを防ぐ）
                $w = plant_schedule_harvest_week_from_plant($row['planned_plant_date'], $days);
                $row['effective_harvest_week'] = $w;
                $kg = (float)($row['expected_yield_kg'] ?? $defaults['yield']);
                if (!isset($adds[$w])) {
                    $adds[$w] = 0.0;
                }
                $adds[$w] += $kg;
                if (!isset($plansByWeek[$w])) {
                    $plansByWeek[$w] = [];
                }
                $plansByWeek[$w][] = $row;
                $plannedN++;
            }
            mysqli_free_result($res);
        }
    }

    $weeks = [];
    $shortageBefore = 0;
    $shortageAfter = 0;
    $avgYield = max(1.0, (float)$defaults['yield']);
    $avgDays = (int)round((float)$defaults['days']);

    foreach ($gaps as $g) {
        $add = (float)($adds[$g['week']] ?? 0);
        $simFc = round($g['forecast_kg'] + $add, 1);
        $simGap = round($g['ship_kg'] - $simFc, 1);
        if ($g['gap_kg'] > $gapThresh) {
            $shortageBefore++;
        }
        if ($simGap > $gapThresh) {
            $shortageAfter++;
        }
        $needBeds = $simGap > $gapThresh ? (int)ceil($simGap / $avgYield) : 0;
        $harvestMid = date('Y-m-d', strtotime($g['week'] . ' +3 days'));
        $suggestPlant = date('Y-m-d', strtotime($harvestMid . ' -' . $avgDays . ' days'));
        if ($suggestPlant < date('Y-m-d')) {
            $suggestPlant = date('Y-m-d');
        }
        $weeks[] = [
            'week' => $g['week'],
            'forecast_kg' => $g['forecast_kg'],
            'ship_kg' => $g['ship_kg'],
            'gap_kg' => $g['gap_kg'],
            'planned_kg' => round($add, 1),
            'sim_forecast_kg' => $simFc,
            'sim_gap_kg' => $simGap,
            'plans' => $plansByWeek[$g['week']] ?? [],
            'need_beds' => $needBeds,
            'suggest_plant_date' => $suggestPlant,
        ];
    }

    return [
        'weeks' => $weeks,
        'planned_n' => $plannedN,
        'shortage_before' => $shortageBefore,
        'shortage_after' => $shortageAfter,
        'defaults' => $defaults,
        'earliest_harvest_week' => plant_schedule_earliest_harvest_week((float)$defaults['days']),
    ];
}
