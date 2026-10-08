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
require_once dirname(__DIR__) . '/lib/overgrow_metrics.php';

$fail = [];
$ok = [];

$today = date('Y-m-d');
$currentWeek = gcal_week_start_sunday($today);

$split = supply_open_remain_for_effective($link, $today);
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

// 不変3/3b: レバーなし break_sim = ②実効系列（計画ビュー①と一致しなくてよい）
$effRows = supply_effective_surplus_rows($link);
$effCurrent = null;
foreach ($effRows as $r) {
    if (!empty($r['is_current'])) {
        $effCurrent = $r;
        break;
    }
}
if ($effCurrent !== null) {
    $bs = gf_break_sim_combo($link, []);
    $cum0 = null;
    $labels = $bs['chart']['labels'] ?? [];
    $cum = $bs['chart']['baseline_cum'] ?? [];
    if ($labels && $cum) {
        $cum0 = (float)$cum[0];
    }
    $want = (float)$effCurrent['surplus_kg'];
    if ($cum0 === null) {
        $fail[] = 'BREAK: chart baseline_cum missing';
    } elseif (abs($cum0 - $want) > 1.0) {
        $fail[] = sprintf(
            'BREAK: chart[0]=%.1f != effective current surplus=%.1f (different base)',
            $cum0,
            $want
        );
    } else {
        $ok[] = sprintf('BREAK: chart[0]=%.1f matches effective surplus', $cum0);
    }

    $mismatch = 0;
    $checkedW = 0;
    $fromCurrentEff = array_values(array_filter($effRows, static fn($r) => empty($r['is_elapsed'])));
    foreach ($fromCurrentEff as $i => $r) {
        if (!isset($cum[$i])) {
            break;
        }
        $checkedW++;
        $bc = (float)$cum[$i];
        $is = (float)$r['surplus_kg'];
        if (abs($bc - $is) > 1.5) {
            $mismatch++;
            if ($mismatch <= 5) {
                $fail[] = sprintf(
                    'BREAK: week %s break=%.1f eff=%.1f (delta %+.1f)',
                    $r['week_start_date'],
                    $bc,
                    $is,
                    $bc - $is
                );
            }
        }
    }
    if ($checkedW > 0 && $mismatch === 0) {
        $ok[] = sprintf('BREAK: %d weeks match effective surplus series', $checkedW);
    }

    // 不変6: 初回割れは余剰＜残出荷。需給 = 実効系列
    $effFirst = null;
    foreach ($fromCurrentEff as $r) {
        if (supply_week_is_stockout((float)$r['surplus_kg'], $r['ship_kg'] === null ? null : (float)$r['ship_kg'])) {
            $effFirst = (string)$r['week_start_date'];
            break;
        }
    }
    $brkFirst = $bs['baseline']['first_break_week'] ?? null;
    if ($effFirst !== $brkFirst) {
        $fail[] = sprintf(
            'BREAK: first stockout eff=%s break=%s (must both be surplus<ship on effective)',
            $effFirst ?? 'null',
            $brkFirst ?? 'null'
        );
    } else {
        $ok[] = sprintf('BREAK: first stockout week=%s (effective, surplus<ship)', $effFirst ?? 'none');
    }
}

// 不変5: 意図在庫の残を未来週へ逃がさない（当週）
$effCycles = supply_open_effective_cycle_rows($link, $today);
$leaked = 0;
$holdN = 0;
foreach ($effCycles as $r) {
    if (empty($r['is_intentional_hold']) && ($r['delay_kind'] ?? '') !== 'hold') {
        continue;
    }
    $holdN++;
    if ($r['week_start_date'] > $currentWeek) {
        $leaked++;
        $fail[] = sprintf('WEEK: hold %s parked in future week %s', $r['bed_name'], $r['week_start_date']);
    }
}
if ($leaked === 0) {
    $ok[] = sprintf('WEEK: intentional holds stay on current week (n=%d)', $holdN);
}
// 予測本線の累計と実効系列が一致
$invRows = supply_inventory_surplus_rows($link);
$invCur = null;
foreach ($invRows as $r) {
    if (!empty($r['is_current'])) {
        $invCur = $r;
        break;
    }
}
if ($invCur !== null && $effCurrent !== null) {
    if (abs((float)$invCur['surplus_kg'] - (float)$effCurrent['surplus_kg']) > 1.0) {
        $fail[] = sprintf(
            'INV: forecast surplus=%.1f != effective=%.1f',
            (float)$invCur['surplus_kg'],
            (float)$effCurrent['surplus_kg']
        );
    } else {
        $ok[] = 'INV: forecast main line matches effective surplus';
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
