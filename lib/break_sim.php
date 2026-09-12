<?php
/**
 * 在庫割れ回避シミュレーション — 実効収量・出荷スポット・定植前倒し・前倒し収穫（DB非破壊）
 * 正本 §0.10
 */
require_once __DIR__ . '/rotation_capacity.php';
require_once __DIR__ . '/inventory_trust.php';
require_once __DIR__ . '/plant_schedule.php';
require_once __DIR__ . '/date_display.php';

/**
 * 直近完了床の平均収量（ゴミ除く）
 * @return array{ok:bool,avg_kg:?float,n:int,window_days:int}
 */
function gf_recent_bed_yield_avg(mysqli $link, int $windowDays = 45): array
{
    $windowDays = max(14, min(120, $windowDays));
    $sql = "
SELECT AVG(t.kg) AS avg_kg, COUNT(*) AS n
FROM (
  SELECT c.id,
         COALESCE(SUM(CASE
           WHEN lt.name IN ('GOMI','ゴミ','gomi') THEN 0
           ELSE h.harvest_kg END), 0) AS kg
  FROM cycles c
  JOIN harvests h ON h.cycle_id = c.id
  LEFT JOIN loss_types lt ON lt.id = h.loss_type_id
  WHERE c.harvest_end IS NOT NULL
    AND c.harvest_end >= DATE_SUB(CURDATE(), INTERVAL {$windowDays} DAY)
  GROUP BY c.id
  HAVING kg > 0
) t
";
    $res = mysqli_query($link, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($res) {
        mysqli_free_result($res);
    }
    $n = (int)($row['n'] ?? 0);
    $avg = $row['avg_kg'] !== null ? round((float)$row['avg_kg'], 0) : null;
    return [
        'ok' => $n > 0 && $avg !== null,
        'avg_kg' => $avg,
        'n' => $n,
        'window_days' => $windowDays,
    ];
}

/**
 * 栽培中残を週次に載せる（実効収量・前倒し収穫日を任意適用）
 *
 * @return array{
 *   open_by_week:array<string,float>,
 *   beds:int,
 *   remain_total:float,
 *   early_days:int,
 *   shifted_beds:int
 * }
 */
function gf_open_by_week_scenario(mysqli $link, ?float $effYieldKg = null, int $earlyDays = 0): array
{
    $today = date('Y-m-d');
    $earlyDays = max(0, min(60, $earlyDays));
    $openByWeek = [];
    $beds = 0;
    $remainTotal = 0.0;
    $shiftedBeds = 0;

    foreach (rotation_open_cycles($link) as $oc) {
        $beds++;
        $harvested = (float)$oc['harvested_kg'];
        $remain = $effYieldKg !== null && $effYieldKg > 0
            ? max(0.0, $effYieldKg - $harvested)
            : (float)$oc['remain_kg'];
        $remainTotal += $remain;
        if ($remain <= 0.001) {
            continue;
        }

        $expected = (string)$oc['expected_harvest'];
        if ($earlyDays > 0) {
            $shifted = date('Y-m-d', strtotime($expected . ' -' . $earlyDays . ' days'));
            if ($shifted < $today) {
                $shifted = $today;
            }
            if ($shifted !== $expected) {
                $shiftedBeds++;
            }
            $expected = $shifted;
        }
        $week = gcal_week_start_sunday($expected);
        if (!isset($openByWeek[$week])) {
            $openByWeek[$week] = 0.0;
        }
        $openByWeek[$week] += $remain;
    }
    foreach ($openByWeek as $w => $kg) {
        $openByWeek[$w] = round($kg, 1);
    }
    return [
        'open_by_week' => $openByWeek,
        'beds' => $beds,
        'remain_total' => round($remainTotal, 1),
        'early_days' => $earlyDays,
        'shifted_beds' => $shiftedBeds,
    ];
}

/** @return array{open_by_week:array<string,float>,beds:int,remain_total:float} */
function gf_model_open_by_week(mysqli $link): array
{
    $o = gf_open_by_week_scenario($link, null, 0);
    return [
        'open_by_week' => $o['open_by_week'],
        'beds' => $o['beds'],
        'remain_total' => $o['remain_total'],
    ];
}

/** remain = max(0, eff_yield - harvested) */
function gf_eff_yield_open_by_week(mysqli $link, float $effYieldKg): array
{
    $o = gf_open_by_week_scenario($link, $effYieldKg, 0);
    return [
        'open_by_week' => $o['open_by_week'],
        'beds' => $o['beds'],
        'remain_total' => $o['remain_total'],
    ];
}

/**
 * 空き床を「明日定植」したと仮定した追加収穫（週次）
 * @return array{open_by_week:array<string,float>,planted:int,empty_total:int,yield_kg:float,days:float,harvest_week:?string}
 */
function gf_plant_tomorrow_extra(mysqli $link, int $plantN, ?float $yieldKg = null): array
{
    $empty = plant_schedule_empty_beds($link);
    $emptyTotal = count($empty);
    if ($plantN <= 0 || $emptyTotal === 0) {
        return [
            'open_by_week' => [],
            'planted' => 0,
            'empty_total' => $emptyTotal,
            'yield_kg' => 0.0,
            'days' => 0.0,
            'harvest_week' => null,
        ];
    }
    $n = $plantN >= 999 ? $emptyTotal : min($plantN, $emptyTotal);
    $defs = plant_schedule_season_defaults($link);
    $days = (float)($defs['days'] ?? 45);
    $y = $yieldKg !== null && $yieldKg > 0 ? $yieldKg : (float)($defs['yield'] ?? 120);
    $plantDate = date('Y-m-d', strtotime('+1 day'));
    $harvestDate = date('Y-m-d', strtotime($plantDate . ' +' . (int)round($days) . ' days'));
    $week = gcal_week_start_sunday($harvestDate);
    return [
        'open_by_week' => [$week => round($n * $y, 1)],
        'planted' => $n,
        'empty_total' => $emptyTotal,
        'yield_kg' => round($y, 0),
        'days' => round($days, 1),
        'harvest_week' => $week,
    ];
}

/**
 * @param array<string,float> $openByWeek
 * @param float|null $shipSpotKg 当週出荷へのスポット加減（1回のみ）
 * @return list<array>
 */
function gf_break_sim_cum_from_open(
    mysqli $link,
    array $openByWeek,
    int $weeksAhead = 16,
    ?float $shipSpotKg = null
): array {
    $outlook = rotation_capacity_outlook($link, $weeksAhead);
    $spot = $shipSpotKg ?? 0.0;
    $base = [];
    foreach ($outlook['weeks'] as $i => $w) {
        $week = (string)$w['week'];
        $ship = (float)($w['ship_kg'] ?? $w['gcal_kg'] ?? 0);
        if ($i === 0 && $spot !== 0.0) {
            $ship = max(0.0, $ship + $spot);
        }
        $base[] = [
            'week' => $week,
            'capacity_kg' => (float)($openByWeek[$week] ?? 0.0),
            'open_kg' => (float)($openByWeek[$week] ?? 0.0),
            'rotation_kg' => 0.0,
            'ship_kg' => $ship,
        ];
    }
    return trust_attach_cumulative($base);
}

/** @param array<string,float> $a @param array<string,float> $b */
function gf_merge_week_kg(array $a, array $b): array
{
    foreach ($b as $w => $kg) {
        if (!isset($a[$w])) {
            $a[$w] = 0.0;
        }
        $a[$w] = round((float)$a[$w] + (float)$kg, 1);
    }
    return $a;
}

/**
 * 組み合わせシミュレーション
 *
 * @param array{
 *   eff?:?float,
 *   ship_delta?:float,
 *   plant_n?:int,
 *   early_days?:int
 * } $opts
 */
function gf_break_sim_combo(mysqli $link, array $opts = [], int $weeksAhead = 16): array
{
    $recent = gf_recent_bed_yield_avg($link, 45);
    $suggested = $recent['ok'] ? (float)$recent['avg_kg'] : 160.0;

    $eff = $opts['eff'] ?? null;
    $useEff = $eff !== null && (float)$eff > 0;
    $effKg = $useEff ? (float)$eff : $suggested;

    $shipDelta = (float)($opts['ship_delta'] ?? 0);
    $plantN = (int)($opts['plant_n'] ?? 0);
    $earlyDays = max(0, min(60, (int)($opts['early_days'] ?? 0)));

    $baseOpen = gf_open_by_week_scenario($link, null, 0);
    $baseCum = gf_break_sim_cum_from_open($link, $baseOpen['open_by_week'], $weeksAhead, null);
    $baseSum = trust_break_summary($baseCum);

    $scOpen = gf_open_by_week_scenario(
        $link,
        $useEff ? $effKg : null,
        $earlyDays
    );

    $plantExtra = gf_plant_tomorrow_extra(
        $link,
        $plantN,
        $useEff ? $effKg : null
    );
    $scWeeks = gf_merge_week_kg($scOpen['open_by_week'], $plantExtra['open_by_week']);
    $scRemain = round(
        (float)$scOpen['remain_total'] + array_sum($plantExtra['open_by_week']),
        1
    );

    $shipSpot = $shipDelta !== 0.0 ? $shipDelta : null;
    $scCum = gf_break_sim_cum_from_open($link, $scWeeks, $weeksAhead, $shipSpot);
    $scSum = trust_break_summary($scCum);

    $labels = [];
    $baseSeries = [];
    $scSeries = [];
    foreach ($baseCum as $i => $row) {
        $labels[] = format_sunday_week($row['week']);
        $baseSeries[] = (float)$row['cum_surplus_kg'];
        $scSeries[] = (float)($scCum[$i]['cum_surplus_kg'] ?? 0);
    }

    $levers = [];
    if ($useEff) {
        $levers[] = '実効' . (int)$effKg . 'kg/床';
    }
    if ($shipSpot !== null) {
        $sign = $shipDelta > 0 ? '+' : '';
        $levers[] = "出荷スポット{$sign}" . (int)$shipDelta . 'kg';
    }
    if ($plantExtra['planted'] > 0) {
        $levers[] = '明日定植' . (int)$plantExtra['planted'] . '床';
    }
    if ($earlyDays > 0) {
        $levers[] = '前倒し収穫' . $earlyDays . '日'
            . ($scOpen['shifted_beds'] > 0 ? '（' . (int)$scOpen['shifted_beds'] . '床）' : '');
    }
    if (!$levers) {
        $levers[] = 'レバーなし（モデル残のまま）';
    }

    return [
        'suggested_kg' => $suggested,
        'eff_yield_kg' => round($effKg, 0),
        'use_eff' => $useEff,
        'ship_delta' => $shipDelta,
        'plant_n' => $plantN,
        'early_days' => $earlyDays,
        'early_extra' => [
            'shifted_beds' => (int)$scOpen['shifted_beds'],
        ],
        'plant_extra' => $plantExtra,
        'recent' => $recent,
        'baseline' => $baseSum,
        'scenario' => $scSum,
        'delta_runway' => (int)$scSum['runway_weeks'] - (int)$baseSum['runway_weeks'],
        'open_remain_baseline' => (float)$baseOpen['remain_total'],
        'open_remain_scenario' => $scRemain,
        'open_beds' => (int)$scOpen['beds'],
        'empty_beds' => (int)$plantExtra['empty_total'],
        'lever_label' => implode(' · ', $levers),
        'chart' => [
            'labels' => $labels,
            'baseline_cum' => $baseSeries,
            'scenario_cum' => $scSeries,
        ],
    ];
}

/** 互換: 実効収量のみ */
function gf_break_sim_eff_yield(mysqli $link, ?float $effYieldKg = null, int $weeksAhead = 16): array
{
    return gf_break_sim_combo($link, ['eff' => $effYieldKg], $weeksAhead);
}
