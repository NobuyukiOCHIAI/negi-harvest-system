<?php
/**
 * 正本§2 / §9 の不変条件チェック。
 * 予測モデルや見た目の「直し」のあと、必ず走らせる。
 *
 * 失敗時 exit 1。成功時 exit 0。
 *
 * php sql/_assert_inventory_canon.php
 */
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/supply_ops.php';
require_once dirname(__DIR__) . '/lib/break_sim.php';

$fail = [];
$ok = [];

$today = date('Y-m-d');
$currentWeek = gcal_week_start_sunday($today);

$split = supply_open_remain_for_inventory($link, $today);
$fcByWeek = $split['fc_by_week'] ?? [];
$pastFc = 0.0;
$pastWeeksWithFc = [];
foreach ($fcByWeek as $w => $kg) {
    if ($w < $currentWeek && (float)$kg > 0) {
        $pastFc += (float)$kg;
        $pastWeeksWithFc[] = $w;
    }
}

$rows = supply_inventory_surplus_rows($link);
$elapsed = array_values(array_filter($rows, static fn($r) => !empty($r['is_elapsed'])));
$current = null;
foreach ($rows as $r) {
    if (!empty($r['is_current'])) {
        $current = $r;
        break;
    }
}

// 不変1: 過去週に未収穫残があるなら、週次明細に過去週行が1行以上ある
if ($pastFc > 0.05) {
    if (count($elapsed) === 0) {
        $fail[] = sprintf(
            'INV: past unharvest %.1fkg exists (%s) but no elapsed week rows (hidden past weeks)',
            $pastFc,
            implode(',', $pastWeeksWithFc)
        );
    } else {
        $ok[] = sprintf('INV: past weeks listed (%d rows, pastFc=%.1f)', count($elapsed), $pastFc);
    }
} else {
    $ok[] = 'INV: no past unharvest (skip past-week listing check)';
}

// 不変2: 累計はゼロ起算しない（過去残があれば当週余剰 ≠ 当週delta）
if ($current !== null && $pastFc > 0.05) {
    $delta = (float)$current['week_delta_kg'];
    $surplus = (float)$current['surplus_kg'];
    if (abs($surplus - $delta) < 0.2) {
        $fail[] = sprintf(
            'INV: current surplus=%.1f equals week_delta=%.1f despite pastFc=%.1f (zero-start smell)',
            $surplus,
            $delta,
            $pastFc
        );
    } else {
        $ok[] = sprintf('INV: current surplus=%.1f != delta=%.1f (carry included)', $surplus, $delta);
    }
}

// 不変3: 需給 break_sim のチャート起点 = 予測の当週累計余剰
if ($current !== null) {
    $bs = gf_break_sim_combo($link, []);
    $cum0 = null;
    $labels = $bs['chart']['labels'] ?? [];
    $cum = $bs['chart']['baseline_cum'] ?? [];
    if ($labels && $cum) {
        $cum0 = (float)$cum[0];
    }
    $want = (float)$current['surplus_kg'];
    if ($cum0 === null) {
        $fail[] = 'BREAK: chart baseline_cum missing';
    } elseif (abs($cum0 - $want) > 1.0) {
        $fail[] = sprintf(
            'BREAK: chart[0]=%.1f != inventory current surplus=%.1f (different base)',
            $cum0,
            $want
        );
    } else {
        $ok[] = sprintf('BREAK: chart[0]=%.1f matches inventory surplus', $cum0);
    }
}

echo "=== inventory canon assert ===\n";
foreach ($ok as $m) {
    echo "OK  $m\n";
}
foreach ($fail as $m) {
    echo "FAIL $m\n";
}

if ($fail) {
    echo "RESULT: FAIL (" . count($fail) . ")\n";
    exit(1);
}
echo "RESULT: PASS\n";
exit(0);
