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
// 不変3b: レバーなし baseline は予測の累計余剰と週次一致（回転寄せを混ぜない）
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

    $mismatch = 0;
    $checkedW = 0;
    $fromCurrent = array_values(array_filter($rows, static fn($r) => empty($r['is_elapsed'])));
    foreach ($fromCurrent as $i => $r) {
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
                    'BREAK: week %s break=%.1f inv=%.1f (delta %+.1f)',
                    $r['week_start_date'],
                    $bc,
                    $is,
                    $bc - $is
                );
            }
        }
    }
    if ($checkedW > 0 && $mismatch === 0) {
        $ok[] = sprintf('BREAK: %d weeks match inventory surplus series', $checkedW);
    }

    // 不変6: 初回割れは余剰＜残出荷。予測と需給で一致
    $invFirst = null;
    foreach ($fromCurrent as $r) {
        if (supply_week_is_stockout((float)$r['surplus_kg'], $r['ship_kg'] === null ? null : (float)$r['ship_kg'])) {
            $invFirst = (string)$r['week_start_date'];
            break;
        }
    }
    $brkFirst = $bs['baseline']['first_break_week'] ?? null;
    if ($invFirst !== $brkFirst) {
        $fail[] = sprintf(
            'BREAK: first stockout inv=%s break=%s (must both be surplus<ship)',
            $invFirst ?? 'null',
            $brkFirst ?? 'null'
        );
    } else {
        $ok[] = sprintf('BREAK: first stockout week=%s (surplus<ship, both pages)', $invFirst ?? 'none');
    }
}

// 不変5: 今日の収穫候補の残は、定植時週の余剰に載る（mid日数で未来へ逃がさない）
$invCycles = supply_open_inventory_cycle_rows($link, $today);
$byCycle = [];
foreach ($invCycles as $r) {
    $byCycle[(int)$r['cycle_id']] = $r;
}
$missing = 0;
$checked = 0;
foreach (open_cycle_progress($link) as $op) {
    $due = !empty($op['harvest_start'])
        || (!empty($op['expected_harvest']) && $op['expected_harvest'] <= $today);
    if (!$due) {
        continue;
    }
    $cid = (int)$op['cycle_id'];
    $inv = $byCycle[$cid] ?? null;
    if ($inv === null) {
        // remain 0 なら候補に出ても余剰0は許容
        continue;
    }
    $checked++;
    $plantExp = (string)($op['expected_harvest'] ?? '');
    $plantWk = $plantExp !== '' ? gcal_week_start_sunday($plantExp) : '';
    if ($plantWk !== '' && $inv['week_start_date'] !== $plantWk) {
        $missing++;
        $fail[] = sprintf(
            'WEEK: %s cycle=%d todayWk=%s invWk=%s (mid drift)',
            $op['bed_name'],
            $cid,
            $plantWk,
            $inv['week_start_date']
        );
    }
}
if ($checked > 0 && $missing === 0) {
    $ok[] = sprintf('WEEK: %d harvest-due beds match plant weeks', $checked);
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
