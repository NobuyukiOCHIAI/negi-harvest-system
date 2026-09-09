<?php
/**
 * 連続定植・回転込みの栽培能力シミュレーション
 *
 * 原則: ベッドが空いたら猶予日以内に必ず定植し、ホライズン内で回転を繰り返す。
 * これがないと「いま植わっている分」が尽きると収量0に見える。
 */
require_once __DIR__ . '/gcal_shipments.php';
require_once __DIR__ . '/plant_schedule.php';

/** 空き後の定植猶予（日） */
const GF_REPLANT_GRACE_DAYS = 5;

/**
 * 未完了サイクル（能力計算用: 残量kg・収穫週）
 *
 * @return list<array{
 *   bed_id:int,
 *   bed_name:string,
 *   forecast_kg:float,
 *   harvested_kg:float,
 *   remain_kg:float,
 *   expected_harvest:string,
 *   harvest_week:string,
 *   free_date:string
 * }>
 */
function rotation_open_cycles(mysqli $link): array
{
    $today = date('Y-m-d');
    $out = [];

    $sql = "
SELECT
  c.bed_id,
  b.name AS bed_name,
  COALESCE(pr.postproc_total_kg, pr.pred_total_kg) AS forecast_kg,
  (SELECT COALESCE(SUM(h.harvest_kg), 0) FROM harvests h WHERE h.cycle_id = c.id) AS harvested_kg,
  DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY) AS expected_harvest
FROM cycles c
JOIN beds b ON b.id = c.bed_id
LEFT JOIN predictions pr
  ON pr.cycle_id = c.id
 AND NOT EXISTS (
       SELECT 1 FROM predictions p2
        WHERE p2.cycle_id = pr.cycle_id AND p2.created_at > pr.created_at
     )
WHERE c.harvest_end IS NULL AND b.active = 1
";
    $res = mysqli_query($link, $sql);
    if (!$res) {
        return $out;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $forecast = $row['forecast_kg'] !== null ? (float)$row['forecast_kg'] : 0.0;
        $harvested = (float)$row['harvested_kg'];
        $remain = max(0.0, $forecast - $harvested);
        $expected = $row['expected_harvest'] ?: $today;
        if ($expected < $today) {
            // 予測超過 → 残量は当週に一度だけ載せる
            $expected = $today;
        }
        $week = gcal_week_start_sunday($expected);
        $out[] = [
            'bed_id' => (int)$row['bed_id'],
            'bed_name' => $row['bed_name'],
            'forecast_kg' => $forecast,
            'harvested_kg' => $harvested,
            'remain_kg' => $remain,
            'expected_harvest' => $expected,
            'harvest_week' => $week,
            'free_date' => $expected,
        ];
    }
    mysqli_free_result($res);
    return $out;
}

/**
 * ベッドごとの「次に空く日」とオープン予測の週次kg
 *
 * @return array{
 *   open_by_week: array<string,float>,
 *   beds: list<array{bed_id:int,name:string,free_date:string,source:string}>
 * }
 */
function rotation_bed_states(mysqli $link): array
{
    $today = date('Y-m-d');
    $openByWeek = [];
    $busy = [];

    foreach (rotation_open_cycles($link) as $oc) {
        $bedId = $oc['bed_id'];
        $remain = $oc['remain_kg'];
        if ($remain > 0.001) {
            $week = $oc['harvest_week'];
            if (!isset($openByWeek[$week])) {
                $openByWeek[$week] = 0.0;
            }
            $openByWeek[$week] += $remain;
        }
        $busy[$bedId] = [
            'bed_id' => $bedId,
            'name' => $oc['bed_name'],
            'free_date' => $oc['free_date'],
            'source' => 'open_cycle',
        ];
    }

    // 空きベッド（未完了なし）
    $res = mysqli_query(
        $link,
        "SELECT b.id AS bed_id, b.name,
                (SELECT MAX(c.harvest_end) FROM cycles c WHERE c.bed_id = b.id) AS last_end
         FROM beds b
         WHERE b.active = 1
           AND NOT EXISTS (
             SELECT 1 FROM cycles c WHERE c.bed_id = b.id AND c.harvest_end IS NULL
           )
         ORDER BY b.name"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $bedId = (int)$row['bed_id'];
            $last = $row['last_end'] ?: date('Y-m-d', strtotime($today . ' -1 day'));
            $busy[$bedId] = [
                'bed_id' => $bedId,
                'name' => $row['name'],
                'free_date' => $last,
                'source' => 'empty',
            ];
        }
        mysqli_free_result($res);
    }

    return [
        'open_by_week' => $openByWeek,
        'beds' => array_values($busy),
    ];
}

/**
 * 緑・能力計算用の有効定植日。
 * 予定が過去なら明日（過大評価防止）。DBの planned_plant_date は変えない。
 */
function rotation_effective_plant_date(string $plannedPlantDate, ?string $today = null): string
{
    $today = $today ?: date('Y-m-d');
    $planned = substr($plannedPlantDate, 0, 10);
    if ($planned < $today) {
        return date('Y-m-d', strtotime($today . ' +1 day'));
    }
    return $planned;
}

/**
 * 既に planned/approved の定植を週次kgへ
 *
 * @return array<string,float>
 */
function rotation_planned_by_week(mysqli $link, float $defaultDays, float $defaultYield): array
{
    $adds = [];
    $chk = mysqli_query($link, "SHOW TABLES LIKE 'plant_schedule'");
    $has = $chk && mysqli_num_rows($chk) > 0;
    if ($chk) {
        mysqli_free_result($chk);
    }
    if (!$has) {
        return $adds;
    }

    $openWeekByBed = [];
    foreach (rotation_open_cycles($link) as $oc) {
        $openWeekByBed[$oc['bed_id']] = $oc['harvest_week'];
    }

    $res = mysqli_query(
        $link,
        "SELECT s.planned_plant_date, s.expected_days, s.expected_yield_kg, s.bed_id
         FROM plant_schedule s
         JOIN beds b ON b.id = s.bed_id AND b.active = 1
         WHERE s.status IN ('planned','approved')"
    );
    if (!$res) {
        return $adds;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $bedId = (int)$row['bed_id'];
        $days = $row['expected_days'] !== null ? (float)$row['expected_days'] : $defaultDays;
        $kg = $row['expected_yield_kg'] !== null ? (float)$row['expected_yield_kg'] : $defaultYield;
        $plantEff = rotation_effective_plant_date((string)$row['planned_plant_date']);
        $w = plant_schedule_harvest_week_from_plant($plantEff, $days);
        // 栽培中ベッドの今サイクル収穫週と同週の計画は二重計上しない
        if (isset($openWeekByBed[$bedId]) && $openWeekByBed[$bedId] === $w) {
            continue;
        }
        if (!isset($adds[$w])) {
            $adds[$w] = 0.0;
        }
        $adds[$w] += $kg;
    }
    mysqli_free_result($res);
    return $adds;
}

/**
 * 空き→猶予→定植→収穫をホライズン内で繰り返した仮想収量
 *
 * @param list<array{bed_id:int,name:string,free_date:string,source:string}> $beds
 * @param array<int,true> $skipBeds すでに plant_schedule で予約済みのベッドは仮想定植しない（二重計上防止）
 * @return array{
 *   by_week: array<string,float>,
 *   plant_events: list<array>,
 *   cycles_sim: int
 * }
 */
function rotation_simulate_replant(
    array $beds,
    string $horizonEnd,
    float $avgDays,
    float $avgYield,
    int $graceDays = GF_REPLANT_GRACE_DAYS,
    array $skipBeds = []
): array {
    $today = date('Y-m-d');
    $byWeek = [];
    $events = [];
    $cycles = 0;
    $daysI = max(1, (int)round($avgDays));

    foreach ($beds as $b) {
        $bedId = (int)$b['bed_id'];
        if (isset($skipBeds[$bedId])) {
            // 予約済みベッドは「その予約の収穫後」から仮想回転を続けるため、
            // 予約の収穫完了想定日を free 起点にする（呼び出し側で上書き可）
            continue;
        }
        $free = $b['free_date'] ?: $today;
        // 安全弁: 無限ループ防止
        for ($n = 0; $n < 40; $n++) {
            $plant = date('Y-m-d', strtotime($free . ' +' . $graceDays . ' days'));
            if ($plant < $today) {
                $plant = $today;
            }
            if ($plant > $horizonEnd) {
                break;
            }
            $harvest = date('Y-m-d', strtotime($plant . ' +' . $daysI . ' days'));
            $week = gcal_week_start_sunday($harvest);
            if ($week > $horizonEnd) {
                break;
            }
            if (!isset($byWeek[$week])) {
                $byWeek[$week] = 0.0;
            }
            $byWeek[$week] += $avgYield;
            $events[] = [
                'bed_id' => $bedId,
                'bed_name' => $b['name'],
                'plant_date' => $plant,
                'harvest_date' => $harvest,
                'harvest_week' => $week,
                'yield_kg' => $avgYield,
                'virtual' => true,
            ];
            $cycles++;
            // 収穫完了でまた空く
            $free = $harvest;
        }
    }

    return [
        'by_week' => $byWeek,
        'plant_events' => $events,
        'cycles_sim' => $cycles,
    ];
}

/**
 * 予約済みスケジュールの「収穫後 free_date」マップ
 *
 * @return array<int,string> bed_id => free_date after last planned harvest
 */
function rotation_reserved_free_dates(mysqli $link, float $defaultDays): array
{
    $out = [];
    $chk = mysqli_query($link, "SHOW TABLES LIKE 'plant_schedule'");
    $has = $chk && mysqli_num_rows($chk) > 0;
    if ($chk) {
        mysqli_free_result($chk);
    }
    if (!$has) {
        return $out;
    }
    $res = mysqli_query(
        $link,
        "SELECT bed_id, planned_plant_date, expected_days
         FROM plant_schedule
         WHERE status IN ('planned','approved')
         ORDER BY planned_plant_date ASC"
    );
    if (!$res) {
        return $out;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $bedId = (int)$row['bed_id'];
        $days = $row['expected_days'] !== null ? (float)$row['expected_days'] : $defaultDays;
        $plantEff = rotation_effective_plant_date((string)$row['planned_plant_date']);
        $harvest = date('Y-m-d', strtotime($plantEff . ' +' . (int)round($days) . ' days'));
        // 複数予約なら最後の収穫日
        if (!isset($out[$bedId]) || $harvest > $out[$bedId]) {
            $out[$bedId] = $harvest;
        }
    }
    mysqli_free_result($res);
    return $out;
}

/**
 * 前年同週の実績収穫kg
 *
 * @return array<string,float> week_start(日曜) => kg
 */
function rotation_yoy_harvest_by_week(mysqli $link, string $fromWeek, string $toWeek): array
{
    $out = [];
    // 今年の各週に対応する前年同週
    $ts = strtotime($fromWeek);
    $end = strtotime($toWeek);
    $weekList = [];
    while ($ts <= $end) {
        $w = date('Y-m-d', $ts);
        $ly = date('Y-m-d', strtotime($w . ' -1 year'));
        // 前年の同じ曜日に揃える（日曜起点を維持）
        $ly = gcal_week_start_sunday($ly);
        $weekList[$w] = $ly;
        $ts = strtotime('+7 days', $ts);
    }
    if (!$weekList) {
        return $out;
    }

    $minLy = min($weekList);
    $maxLy = max($weekList);
    $sql = "
SELECT
  DATE_SUB(h.harvest_date, INTERVAL (DAYOFWEEK(h.harvest_date) - 1) DAY) AS week_start,
  SUM(h.harvest_kg) AS kg
FROM harvests h
WHERE h.harvest_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 7 DAY)
GROUP BY week_start
";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $minLy, $maxLy);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $lyKg = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $lyKg[$row['week_start']] = (float)$row['kg'];
    }
    mysqli_stmt_close($stmt);

    foreach ($weekList as $thisWeek => $lyWeek) {
        $out[$thisWeek] = (float)($lyKg[$lyWeek] ?? 0);
    }
    return $out;
}

/**
 * 回転込みの週次能力（オープン + 予定定植 + 仮想連続定植）
 *
 * @return array{
 *   weeks: list<array>,
 *   defaults: array{days:float,yield:float},
 *   sim_cycles: int,
 *   open_kg_total: float,
 *   rotation_kg_total: float,
 *   planned_kg_total: float,
 *   yoy_beat_weeks: int,
 *   yoy_miss_weeks: int,
 *   zero_weeks: int,
 *   plant_events: list
 * }
 */
function rotation_capacity_outlook(mysqli $link, int $weeksAhead = 16): array
{
    $defaults = plant_schedule_season_defaults($link);
    $avgDays = (float)$defaults['days'];
    $avgYield = (float)$defaults['yield'];
    $today = date('Y-m-d');
    $currentWeek = gcal_week_start_sunday($today);
    $horizonEnd = date('Y-m-d', strtotime('+' . $weeksAhead . ' weeks', strtotime($currentWeek)));

    $states = rotation_bed_states($link);
    $plannedByWeek = rotation_planned_by_week($link, $avgDays, $avgYield);
    $reservedFree = rotation_reserved_free_dates($link, $avgDays);
    $skip = [];
    $bedsForSim = [];
    foreach ($states['beds'] as $b) {
        $bedId = (int)$b['bed_id'];
        if (isset($reservedFree[$bedId])) {
            // 予約の収穫後から仮想回転を継続
            $b2 = $b;
            $b2['free_date'] = $reservedFree[$bedId];
            $b2['source'] = 'after_planned';
            $bedsForSim[] = $b2;
            continue;
        }
        $bedsForSim[] = $b;
    }

    $sim = rotation_simulate_replant(
        $bedsForSim,
        $horizonEnd,
        $avgDays,
        $avgYield,
        GF_REPLANT_GRACE_DAYS,
        $skip
    );

    // 出荷コミット（確定ライン）: GCAL のみ
    // plan はシステム計画（能力相当）。manual は使わない
    $shipByWeek = [];
    $gcalByWeek = [];
    $res = mysqli_query(
        $link,
        "SELECT week_start_date, source, committed_amount_kg
         FROM calendar_shipments
         WHERE week_start_date BETWEEN '{$currentWeek}' AND '{$horizonEnd}'
         ORDER BY week_start_date ASC, FIELD(source, 'gcal', 'manual', 'plan')"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $w = $row['week_start_date'];
            $src = $row['source'];
            $kg = (float)$row['committed_amount_kg'];
            if ($src === 'gcal' && !isset($gcalByWeek[$w])) {
                $gcalByWeek[$w] = $kg;
                $shipByWeek[$w] = $kg;
            }
        }
        mysqli_free_result($res);
    }

    $yoy = rotation_yoy_harvest_by_week($link, $currentWeek, $horizonEnd);

    $weeks = [];
    $zero = 0;
    $beat = 0;
    $miss = 0;
    $openTotal = 0.0;
    $rotTotal = 0.0;
    $planTotal = 0.0;
    $surplusTh = defined('GF_SURPLUS_WEEK_KG') ? (float)GF_SURPLUS_WEEK_KG : 50.0;
    $tightenTh = defined('GF_TIGHTEN_WEEK_KG') ? (float)GF_TIGHTEN_WEEK_KG : 50.0;
    $targetRatio = defined('GF_TARGET_SHIP_RATIO') ? (float)GF_TARGET_SHIP_RATIO : 1.0;
    $ts = strtotime($currentWeek);
    $endTs = strtotime($horizonEnd);
    while ($ts <= $endTs) {
        $w = date('Y-m-d', $ts);
        $openKg = round((float)($states['open_by_week'][$w] ?? 0), 1);
        $planKg = round((float)($plannedByWeek[$w] ?? 0), 1);
        $rotKg = round((float)($sim['by_week'][$w] ?? 0), 1);
        // 能力 = オープン予測 + 予定定植 + 仮想回転（予定と仮想の二重は reserved 起点で抑制）
        $capacity = round($openKg + $planKg + $rotKg, 1);
        $ship = round((float)($shipByWeek[$w] ?? 0), 1);
        $gcalShip = round((float)($gcalByWeek[$w] ?? 0), 1);
        $targetShip = round($capacity * $targetRatio, 1);
        $yoyKg = round((float)($yoy[$w] ?? 0), 1);
        $balance = round($capacity - $ship, 1);
        $yoyDiff = round($capacity - $yoyKg, 1);

        if ($capacity <= 0.05) {
            $zero++;
        }
        if ($yoyKg > 0) {
            if ($capacity + 1e-6 >= $yoyKg) {
                $beat++;
            } else {
                $miss++;
            }
        }

        $signal = 'flat';
        if ($balance >= $surplusTh) {
            $signal = 'expand';
        } elseif ($balance <= -$tightenTh) {
            $signal = 'tighten';
        }
        // 昨対割れは能力側の警告を優先表示用に付与
        $yoySignal = $yoyKg > 0 && $capacity < $yoyKg ? 'yoy_miss' : ($yoyKg > 0 ? 'yoy_ok' : 'yoy_na');

        $weeks[] = [
            'week' => $w,
            'open_kg' => $openKg,
            'planned_kg' => $planKg,
            'rotation_kg' => $rotKg,
            'forecast_kg' => $capacity, // 互換: 能力合計
            'capacity_kg' => $capacity,
            'ship_kg' => $ship,
            'gcal_kg' => $gcalShip,
            'target_ship_kg' => $targetShip,
            'yoy_kg' => $yoyKg,
            'yoy_diff_kg' => $yoyDiff,
            'gap_kg' => round($ship - $capacity, 1),
            'balance_kg' => $balance,
            'signal' => $signal,
            'yoy_signal' => $yoySignal,
        ];
        $openTotal += $openKg;
        $rotTotal += $rotKg;
        $planTotal += $planKg;
        $ts = strtotime('+7 days', $ts);
    }

    return [
        'weeks' => $weeks,
        'defaults' => $defaults,
        'sim_cycles' => $sim['cycles_sim'],
        'open_kg_total' => round($openTotal, 1),
        'rotation_kg_total' => round($rotTotal, 1),
        'planned_kg_total' => round($planTotal, 1),
        'yoy_beat_weeks' => $beat,
        'yoy_miss_weeks' => $miss,
        'zero_weeks' => $zero,
        'plant_events' => $sim['plant_events'],
        'grace_days' => GF_REPLANT_GRACE_DAYS,
    ];
}

/**
 * 常時回転: 次の定植を plant_schedule に自動積む（ボタン待ちにしない）
 * - 空きベッド: 収穫終了 + 猶予日（過去日は遅れとして残す）
 * - 栽培中: 予測収穫日 + 猶予日（収穫前から次が計画に載る）
 *
 * @return array{created:int,skipped_reserved:int,defaults:array}
 */
function rotation_generate_continuous_plants(mysqli $link, int $horizonWeeks = 12): array
{
    $defaults = plant_schedule_season_defaults($link);
    $avgDays = (float)$defaults['days'];
    $avgYield = (float)$defaults['yield'];
    $today = date('Y-m-d');
    $horizonEnd = date('Y-m-d', strtotime('+' . $horizonWeeks . ' weeks'));

    $states = rotation_bed_states($link);
    $reserved = plant_schedule_reserved_beds($link);
    $created = 0;
    $skipped = 0;

    $chk = mysqli_query($link, "SHOW TABLES LIKE 'plant_schedule'");
    $has = $chk && mysqli_num_rows($chk) > 0;
    if ($chk) {
        mysqli_free_result($chk);
    }
    if (!$has) {
        return ['created' => 0, 'skipped_reserved' => 0, 'defaults' => $defaults];
    }

    $stmt = mysqli_prepare(
        $link,
        "INSERT INTO plant_schedule
          (planned_plant_date, bed_id, status, target_harvest_week, gap_kg, expected_yield_kg, expected_days, note)
         VALUES (?, ?, 'planned', ?, 0, ?, ?, ?)"
    );

    foreach ($states['beds'] as $b) {
        $src = (string)($b['source'] ?? '');
        if ($src !== 'empty' && $src !== 'open_cycle') {
            continue;
        }
        $bedId = (int)$b['bed_id'];
        if (isset($reserved[$bedId])) {
            $skipped++;
            continue;
        }
        $free = $b['free_date'] ?: $today;
        $plant = date('Y-m-d', strtotime($free . ' +' . GF_REPLANT_GRACE_DAYS . ' days'));
        // 過去日は今日に繰り上げない。遅れ可視化の起点にする。
        if ($plant > $horizonEnd) {
            continue;
        }
        $target = plant_schedule_harvest_week_from_plant($plant, $avgDays);
        $note = $src === 'open_cycle'
            ? '常時回転: 収穫後' . GF_REPLANT_GRACE_DAYS . '日以内定植（自動）'
            : '常時回転: 空き後' . GF_REPLANT_GRACE_DAYS . '日以内定植';
        mysqli_stmt_bind_param(
            $stmt,
            'sisdds',
            $plant,
            $bedId,
            $target,
            $avgYield,
            $avgDays,
            $note
        );
        if (mysqli_stmt_execute($stmt)) {
            $created++;
            $reserved[$bedId] = true;
        }
    }
    mysqli_stmt_close($stmt);

    return [
        'created' => $created,
        'skipped_reserved' => $skipped,
        'defaults' => $defaults,
    ];
}
