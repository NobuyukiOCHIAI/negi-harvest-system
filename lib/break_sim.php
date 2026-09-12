<?php
/**
 * 在庫割れ回避シミュレーション — 実効収量などで「いまの畑」前提を動かす
 * 正本 §0.3.1 / §0.10。DBは書き換えない（画面上の what-if）。
 */
require_once __DIR__ . '/rotation_capacity.php';
require_once __DIR__ . '/inventory_trust.php';
require_once __DIR__ . '/date_display.php';

/**
 * 直近完了床の平均収量（ゴミ除く・出荷実力の目安）
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
 * オープン床の残量を「実効収量/床」で置き換えた週次 open_kg
 * remain = max(0, eff_yield - harvested)
 *
 * @return array{open_by_week:array<string,float>,beds:int,remain_total:float}
 */
function gf_eff_yield_open_by_week(mysqli $link, float $effYieldKg): array
{
    $effYieldKg = max(0.0, $effYieldKg);
    $openByWeek = [];
    $beds = 0;
    $remainTotal = 0.0;
    foreach (rotation_open_cycles($link) as $oc) {
        $beds++;
        $harvested = (float)$oc['harvested_kg'];
        $remain = max(0.0, $effYieldKg - $harvested);
        // 予測超過などで既に harvested > eff の床は残0
        if ($remain <= 0.001) {
            continue;
        }
        $week = (string)$oc['harvest_week'];
        if (!isset($openByWeek[$week])) {
            $openByWeek[$week] = 0.0;
        }
        $openByWeek[$week] += $remain;
        $remainTotal += $remain;
    }
    foreach ($openByWeek as $w => $kg) {
        $openByWeek[$w] = round($kg, 1);
    }
    return [
        'open_by_week' => $openByWeek,
        'beds' => $beds,
        'remain_total' => round($remainTotal, 1),
    ];
}

/**
 * 定植済ベースの累計系列を、任意の週次 open_kg で組み直す
 *
 * @param array<string,float> $openByWeek
 * @return list<array>
 */
function gf_break_sim_cum_from_open(mysqli $link, array $openByWeek, int $weeksAhead = 16): array
{
    $outlook = rotation_capacity_outlook($link, $weeksAhead);
    $base = [];
    foreach ($outlook['weeks'] as $w) {
        $week = (string)$w['week'];
        $base[] = [
            'week' => $week,
            'capacity_kg' => (float)($openByWeek[$week] ?? 0.0),
            'open_kg' => (float)($openByWeek[$week] ?? 0.0),
            'rotation_kg' => 0.0,
            'ship_kg' => (float)($w['ship_kg'] ?? $w['gcal_kg'] ?? 0),
        ];
    }
    return trust_attach_cumulative($base);
}

/**
 * 現状（モデル予測残） vs 実効収量シナリオ の割れ比較
 *
 * @return array{
 *   suggested_kg:float,
 *   recent:array,
 *   baseline:array,
 *   scenario:array,
 *   delta_runway:int,
 *   open_remain_baseline:float,
 *   open_remain_scenario:float
 * }
 */
function gf_break_sim_eff_yield(mysqli $link, ?float $effYieldKg = null, int $weeksAhead = 16): array
{
    $recent = gf_recent_bed_yield_avg($link, 45);
    $suggested = $recent['ok'] ? (float)$recent['avg_kg'] : 160.0;
    if ($effYieldKg === null || $effYieldKg <= 0) {
        $effYieldKg = $suggested;
    }

    $baselineOpen = [];
    $baseRemain = 0.0;
    foreach (rotation_open_cycles($link) as $oc) {
        $r = (float)$oc['remain_kg'];
        $baseRemain += $r;
        if ($r <= 0.001) {
            continue;
        }
        $w = (string)$oc['harvest_week'];
        if (!isset($baselineOpen[$w])) {
            $baselineOpen[$w] = 0.0;
        }
        $baselineOpen[$w] += $r;
    }

    $baseCum = gf_break_sim_cum_from_open($link, $baselineOpen, $weeksAhead);
    $baseSum = trust_break_summary($baseCum);

    $sc = gf_eff_yield_open_by_week($link, $effYieldKg);
    $scCum = gf_break_sim_cum_from_open($link, $sc['open_by_week'], $weeksAhead);
    $scSum = trust_break_summary($scCum);

    $labels = [];
    $baseSeries = [];
    $scSeries = [];
    $shipSeries = [];
    foreach ($baseCum as $i => $row) {
        $labels[] = format_sunday_week($row['week']);
        $baseSeries[] = (float)$row['cum_surplus_kg'];
        $scSeries[] = (float)($scCum[$i]['cum_surplus_kg'] ?? 0);
        $shipSeries[] = (float)$row['ship_kg'];
    }

    return [
        'suggested_kg' => $suggested,
        'eff_yield_kg' => round($effYieldKg, 0),
        'recent' => $recent,
        'baseline' => $baseSum,
        'scenario' => $scSum,
        'delta_runway' => (int)$scSum['runway_weeks'] - (int)$baseSum['runway_weeks'],
        'open_remain_baseline' => round($baseRemain, 1),
        'open_remain_scenario' => (float)$sc['remain_total'],
        'open_beds' => (int)$sc['beds'],
        'chart' => [
            'labels' => $labels,
            'baseline_cum' => $baseSeries,
            'scenario_cum' => $scSeries,
            'ship' => $shipSeries,
        ],
    ];
}
