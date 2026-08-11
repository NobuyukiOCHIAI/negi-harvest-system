<?php
/**
 * 実収穫量 → 過去最高到達向けアドバイス（定植計画と共有）
 *
 * 経過月: どのKPIが悪化して差がついたか
 * 今後  : 最高に戻すためにどのKPIを改善するか
 */

/**
 * @return array{ok:bool, matrix:array<int,array<int,array>>, years:list<int>}
 */
function gf_actual_load_monthly(mysqli $link): array
{
    $chk = mysqli_query(
        $link,
        "SELECT 1 FROM information_schema.views
         WHERE table_schema = DATABASE() AND table_name = 'harvest_actual_monthly_v' LIMIT 1"
    );
    $ok = $chk && mysqli_num_rows($chk) > 0;
    if ($chk) {
        mysqli_free_result($chk);
    }
    if (!$ok) {
        return ['ok' => false, 'matrix' => [], 'years' => []];
    }

    $matrix = [];
    $years = [];
    $res = mysqli_query(
        $link,
        "SELECT harvest_year, harvest_month, event_count, cycle_count, bed_count,
                total_kg, gomi_kg, kg_per_cycle, kg_per_week_of_month
         FROM harvest_actual_monthly_v
         ORDER BY harvest_year ASC, harvest_month ASC"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $y = (int)$row['harvest_year'];
            $m = (int)$row['harvest_month'];
            $cycles = (int)$row['cycle_count'];
            $total = (float)$row['total_kg'];
            if (!isset($matrix[$y])) {
                $matrix[$y] = [];
                $years[] = $y;
            }
            // Excel「実収穫量」: 個数=cycle, ベッドあたり=合計/個数
            $matrix[$y][$m] = [
                'event_count' => (int)$row['event_count'],
                'cycle_count' => $cycles,
                'bed_count' => (int)$row['bed_count'],
                'total_kg' => $total,
                'gomi_kg' => (float)$row['gomi_kg'],
                'kg_per_bed' => $row['kg_per_cycle'] !== null
                    ? (float)$row['kg_per_cycle']
                    : ($cycles > 0 ? round($total / $cycles, 1) : null),
                'kg_per_week' => $row['kg_per_week_of_month'] !== null
                    ? (float)$row['kg_per_week_of_month']
                    : round($total / 4.0, 1),
            ];
        }
        mysqli_free_result($res);
    }
    rsort($years);
    return ['ok' => true, 'matrix' => $matrix, 'years' => $years];
}

/**
 * 月ごとの過去最高（基準年以外）。
 * @return array<int, array{value:float, year:int}>
 */
function gf_actual_peak_by_month(array $matrix, array $years, int $focusYear, string $key): array
{
    $peak = [];
    foreach ($years as $y) {
        $y = (int)$y;
        if ($y === $focusYear) {
            continue;
        }
        for ($m = 1; $m <= 12; $m++) {
            $cell = $matrix[$y][$m] ?? null;
            if ($cell === null) {
                continue;
            }
            $v = $cell[$key] ?? null;
            if ($v === null) {
                continue;
            }
            $v = (float)$v;
            if (!isset($peak[$m]) || $v > $peak[$m]['value']) {
                $peak[$m] = ['value' => $v, 'year' => $y];
            }
        }
    }
    return $peak;
}

/**
 * 1か月のKPI診断（過去最高比）。
 *
 * @return array{
 *   m:int,
 *   total_gap:float,
 *   bed_gap:int,
 *   yield_gap:float,
 *   primary:list<string>,
 *   ok:list<string>,
 *   has_shortfall:bool
 * }|null
 */
function gf_actual_diagnose_month(
    int $m,
    ?array $cur,
    array $peakPerBed,
    array $peakBeds,
    array $peakTotal
): ?array {
    if ($cur === null) {
        return null;
    }

    $yieldGap = 0.0;
    $bedGap = 0;
    $totalGap = 0.0;
    $primary = [];
    $ok = [];

    if ($cur['kg_per_bed'] !== null && isset($peakPerBed[$m])) {
        $yieldGap = round($peakPerBed[$m]['value'] - (float)$cur['kg_per_bed'], 1);
    }
    if (isset($peakBeds[$m])) {
        $bedGap = (int)$peakBeds[$m]['value'] - (int)$cur['bed_count'];
    }
    if (isset($peakTotal[$m])) {
        $totalGap = round($peakTotal[$m]['value'] - (float)$cur['total_kg'], 0);
    }

    $yieldBad = $yieldGap >= 5.0;
    $bedBad = $bedGap >= 2;
    $totalBad = $totalGap >= 50.0;

    if ($yieldBad) {
        $primary[] = 'kg/ベッド';
    } else {
        $ok[] = 'kg/ベッド';
    }
    if ($bedBad) {
        $primary[] = '収穫ベッド数';
    } else {
        $ok[] = '収穫ベッド数';
    }
    if ($totalBad && !$yieldBad && !$bedBad) {
        // 稀: 合計だけ足りない（ピーク年の組み合わせ差）
        $primary[] = '月合計';
    }

    // 合計不足の主因をベッド数 vs ベッドあたりで寄与分解
    $drivers = [];
    if ($totalBad || $yieldBad || $bedBad) {
        $curY = (float)($cur['kg_per_bed'] ?? 0);
        $curB = (int)$cur['bed_count'];
        $peakY = isset($peakPerBed[$m]) ? (float)$peakPerBed[$m]['value'] : $curY;
        $peakB = isset($peakBeds[$m]) ? (int)$peakBeds[$m]['value'] : $curB;
        $bedEffect = max(0.0, ($peakB - $curB) * $curY);
        $yieldEffect = max(0.0, ($peakY - $curY) * $peakB);
        if ($bedEffect + $yieldEffect > 0) {
            if ($bedEffect >= $yieldEffect * 1.15) {
                $drivers = ['収穫ベッド数'];
            } elseif ($yieldEffect >= $bedEffect * 1.15) {
                $drivers = ['kg/ベッド'];
            } else {
                $drivers = ['収穫ベッド数', 'kg/ベッド'];
            }
        }
    }
    if ($drivers) {
        $primary = $drivers;
    }

    return [
        'm' => $m,
        'total_gap' => $totalGap,
        'bed_gap' => $bedGap,
        'yield_gap' => $yieldGap,
        'peak_bed' => isset($peakBeds[$m]) ? (int)$peakBeds[$m]['value'] : null,
        'peak_yield' => isset($peakPerBed[$m]) ? (float)$peakPerBed[$m]['value'] : null,
        'peak_total' => isset($peakTotal[$m]) ? (float)$peakTotal[$m]['value'] : null,
        'peak_bed_year' => $peakBeds[$m]['year'] ?? null,
        'peak_yield_year' => $peakPerBed[$m]['year'] ?? null,
        'peak_total_year' => $peakTotal[$m]['year'] ?? null,
        'cur_bed' => (int)$cur['bed_count'],
        'cur_yield' => $cur['kg_per_bed'],
        'cur_total' => (float)$cur['total_kg'],
        'primary' => $primary,
        'ok' => $ok,
        'has_shortfall' => ($totalBad || $yieldBad || $bedBad),
    ];
}

/**
 * @param list<array> $diags
 * @return array{bed:int, yield:int, total:int, months:list<int>}
 */
function gf_actual_tally_drivers(array $diags): array
{
    $t = ['bed' => 0, 'yield' => 0, 'total' => 0, 'months' => []];
    foreach ($diags as $d) {
        if (empty($d['has_shortfall'])) {
            continue;
        }
        $t['months'][] = (int)$d['m'];
        foreach ($d['primary'] as $p) {
            if ($p === '収穫ベッド数') {
                $t['bed']++;
            } elseif ($p === 'kg/ベッド') {
                $t['yield']++;
            } else {
                $t['total']++;
            }
        }
    }
    return $t;
}

/**
 * 優先改善KPIを文言化。
 * @param array{bed:int, yield:int, total:int} $tally
 */
function gf_actual_priority_kpi_text(array $tally): string
{
    $parts = [];
    if ($tally['bed'] > 0) {
        $parts[] = ['k' => '収穫ベッド数', 'n' => $tally['bed']];
    }
    if ($tally['yield'] > 0) {
        $parts[] = ['k' => 'kg/ベッド', 'n' => $tally['yield']];
    }
    if ($tally['total'] > 0 && !$parts) {
        $parts[] = ['k' => '月合計', 'n' => $tally['total']];
    }
    usort($parts, static fn($a, $b) => $b['n'] <=> $a['n']);
    if (!$parts) {
        return '現状KPIの維持';
    }
    return implode(' → ', array_map(static fn($p) => $p['k'], $parts));
}

/**
 * @return array{
 *   s1: array{level:string, title:string, elapsed:list<string>, future:list<string>, plan:bool},
 *   s2: array{level:string, title:string, elapsed:list<string>, future:list<string>, plan:bool},
 *   s3: array{level:string, title:string, elapsed:list<string>, future:list<string>, plan:bool},
 *   plan_summary: string
 * }
 */
function gf_actual_build_advice(
    array $matrix,
    array $years,
    int $focusYear,
    float $avgYieldKg = 120.0,
    ?int $asOfMonth = null
): array {
    $avgYieldKg = max(1.0, $avgYieldKg);
    $calYear = (int)date('Y');
    $calMonth = (int)date('n');
    if ($asOfMonth === null) {
        $asOfMonth = ($focusYear === $calYear) ? $calMonth : (($focusYear < $calYear) ? 12 : 0);
    }

    $peakPerBed = gf_actual_peak_by_month($matrix, $years, $focusYear, 'kg_per_bed');
    $peakBeds = gf_actual_peak_by_month($matrix, $years, $focusYear, 'bed_count');
    $peakTotal = gf_actual_peak_by_month($matrix, $years, $focusYear, 'total_kg');

    $elapsedDiags = [];
    $futurePeaks = []; // remaining months' peak targets
    $ytdCur = 0.0;
    $ytdPeak = 0.0;

    for ($m = 1; $m <= 12; $m++) {
        $cur = $matrix[$focusYear][$m] ?? null;
        if ($m <= $asOfMonth && $cur !== null) {
            $d = gf_actual_diagnose_month($m, $cur, $peakPerBed, $peakBeds, $peakTotal);
            if ($d) {
                $elapsedDiags[] = $d;
                $ytdCur += (float)$d['cur_total'];
                if ($d['peak_total'] !== null) {
                    $ytdPeak += (float)$d['peak_total'];
                }
            }
        }
        if ($m > $asOfMonth) {
            $futurePeaks[$m] = [
                'peak_bed' => isset($peakBeds[$m]) ? (int)$peakBeds[$m]['value'] : null,
                'peak_yield' => isset($peakPerBed[$m]) ? (float)$peakPerBed[$m]['value'] : null,
                'peak_total' => isset($peakTotal[$m]) ? (float)$peakTotal[$m]['value'] : null,
            ];
        }
    }

    $shortfalls = array_values(array_filter($elapsedDiags, static fn($d) => $d['has_shortfall']));
    usort($shortfalls, static fn($a, $b) => $b['total_gap'] <=> $a['total_gap']);
    $tally = gf_actual_tally_drivers($shortfalls);
    $priority = gf_actual_priority_kpi_text($tally);

    $hasFuture = $asOfMonth < 12 && $futurePeaks !== [];

    // ---------- ① kg/ベッド・収穫ベッド数 ----------
    $s1Elapsed = [];
    $s1Future = [];
    $s1Plan = false;
    if (!$elapsedDiags) {
        $s1Level = 'ok';
        $s1Title = '比較待機';
        $s1Elapsed[] = '経過月のデータがまだありません。';
        $s1Future[] = '収穫が進んだら、悪化KPI（kg/ベッド or 収穫ベッド数）を特定します。';
    } elseif (!$shortfalls) {
        $s1Level = 'ok';
        $s1Title = '経過月は過去最高水準';
        $s1Elapsed[] = sprintf('1〜%d月: kg/ベッド・収穫ベッド数とも過去最高と同水準以上。', $asOfMonth);
        if ($hasFuture) {
            $s1Future[] = sprintf('今後（%d月〜）: 両KPIをピーク月並みに維持。', $asOfMonth + 1);
            $s1Future[] = '優先KPI: 現状維持。落ち込み時はベッド数→定植、過栽培兆候→定植ペース抑制。';
        } else {
            $s1Future[] = '年内の改善余地はなし。翌年同月のKPI維持が課題。';
        }
    } else {
        $s1Level = 'warn';
        $s1Title = '経過月でKPI悪化あり';
        foreach (array_slice($shortfalls, 0, 3) as $d) {
            $bits = [];
            if (in_array('kg/ベッド', $d['primary'], true)) {
                $bits[] = sprintf(
                    'kg/ベッド %.0f（最高%.0f / %d年 · -%.0f）',
                    (float)$d['cur_yield'],
                    (float)$d['peak_yield'],
                    (int)$d['peak_yield_year'],
                    (float)$d['yield_gap']
                );
            }
            if (in_array('収穫ベッド数', $d['primary'], true)) {
                $bits[] = sprintf(
                    '収穫ベッド数 %dベッド（最高%dベッド / %d年 · -%d）',
                    (int)$d['cur_bed'],
                    (int)$d['peak_bed'],
                    (int)$d['peak_bed_year'],
                    (int)$d['bed_gap']
                );
            }
            $cause = $d['primary'] ? implode('・', $d['primary']) : '複合';
            $s1Elapsed[] = sprintf('%d月: 悪化KPIは「%s」— %s', $d['m'], $cause, implode(' / ', $bits));
        }
        if ($tally['bed'] > 0) {
            $s1Plan = true;
        }
        if ($hasFuture) {
            $s1Future[] = sprintf('今後の改善優先KPI: %s', $priority);
            if ($tally['bed'] >= $tally['yield']) {
                $s1Future[] = '収穫ベッド数を過去最高月並みに戻す（定植計画で不足週のベッド数確保）。';
                $s1Plan = true;
            }
            if ($tally['yield'] > 0) {
                $s1Future[] = 'kg/ベッドの単純アップは難しい。改善余地は過栽培ロス抑制（ゴミ化を減らす）。';
                $s1Future[] = '定植計画でペースを抑え、過栽培にならない立案を優先。';
                $s1Plan = true;
            }
        } else {
            $s1Future[] = sprintf('年内は完了。翌年は優先KPI「%s」を同月ピークまで上げる。', $priority);
        }
    }

    // ---------- ② 年内推移（累計） ----------
    $s2Elapsed = [];
    $s2Future = [];
    $s2Plan = false;
    if (!$elapsedDiags) {
        $s2Level = 'ok';
        $s2Title = '比較待機';
        $s2Elapsed[] = '累計比較には経過月が必要です。';
        $s2Future[] = 'データ蓄積後に、累計不足の主因KPIを示します。';
    } elseif ($ytdPeak <= 0) {
        $s2Level = 'ok';
        $s2Title = '過去比較データ不足';
        $s2Elapsed[] = '比較できる過去年が少ないため、前年同月を目安に。';
        $s2Future[] = '過去年が揃い次第、累計ギャップの主因KPIを特定します。';
    } else {
        $ratio = $ytdCur / $ytdPeak;
        $gapYtd = $ytdPeak - $ytdCur;
        if ($ratio >= 1.0) {
            $s2Level = 'ok';
            $s2Title = '累計は過去最高ペース以上';
            $s2Elapsed[] = sprintf(
                '1〜%d月累計 %.0fkg（過去最高同月累計の %.0f%%）。経過月の主因悪化なし。',
                $asOfMonth,
                $ytdCur,
                $ratio * 100
            );
            if ($hasFuture) {
                $s2Future[] = '今後: 月合計をピーク水準で維持（優先KPIは現状維持）。';
                $s2Future[] = '落ち込み兆候: まず収穫ベッド数、次に過栽培（遅延・ゴミ）を点検。収量の無理な上積みはしない。';
            } else {
                $s2Future[] = '年間ペース達成。翌年も同KPI水準を維持。';
            }
        } else {
            $s2Level = $ratio < 0.85 ? 'danger' : 'warn';
            $s2Title = '累計不足 — 経過の悪化KPIあり';
            $causeTxt = $priority !== '現状KPIの維持' ? $priority : '月合計';
            $s2Elapsed[] = sprintf(
                '1〜%d月累計は過去最高同月累計より %.0fkg 不足（%.0f%%）。',
                $asOfMonth,
                $gapYtd,
                $ratio * 100
            );
            $s2Elapsed[] = sprintf('不足の主因KPI（経過月の集計）: %s', $causeTxt);
            if ($shortfalls) {
                $ex = $shortfalls[0];
                $s2Elapsed[] = sprintf(
                    '代表月%d月: 悪化は「%s」（合計 -%.0fkg）',
                    $ex['m'],
                    implode('・', $ex['primary']),
                    $ex['total_gap']
                );
            }
            if ($hasFuture) {
                $remain = 12 - $asOfMonth;
                $needPer = $remain > 0 ? ($gapYtd / $remain) : $gapYtd;
                $beds = (int)ceil(max(0, $needPer) / $avgYieldKg);
                $s2Future[] = sprintf('今後の改善優先KPI: %s', $causeTxt);
                $s2Future[] = sprintf(
                    '残り%dヶ月で追いつく目安: 月あたり +%.0fkg（≈%dベッド分 @%.0fkg/ベッド）。',
                    $remain,
                    max(0, $needPer),
                    max(1, $beds),
                    $avgYieldKg
                );
                if ($tally['bed'] >= $tally['yield']) {
                    $s2Future[] = 'ベッド数KPIを優先して定植計画の不足週を埋める。';
                    $s2Plan = true;
                } else {
                    $s2Future[] = 'kg/ベッド不足は収量アップより過栽培ロス回避が現実的。定植計画で過密・遅延を防ぐ。';
                    $s2Plan = true;
                }
            } else {
                $s2Future[] = sprintf('年内完了。翌年は優先KPI「%s」で同月累計を最高以上へ。', $causeTxt);
            }
        }
    }

    // ---------- ③ 月合計 ----------
    $s3Elapsed = [];
    $s3Future = [];
    $s3Plan = false;
    $totalShort = array_values(array_filter($shortfalls, static fn($d) => $d['total_gap'] >= 50));
    if (!$elapsedDiags) {
        $s3Level = 'ok';
        $s3Title = '比較待機';
        $s3Elapsed[] = '月合計の比較には経過月が必要です。';
        $s3Future[] = '蓄積後に、合計不足の主因KPI（ベッド数 / kg/ベッド）を示します。';
    } elseif (!$totalShort) {
        $s3Level = 'ok';
        $s3Title = '経過月の合計は過去最高水準';
        $s3Elapsed[] = sprintf('1〜%d月: 月合計kgは過去最高と同水準以上。', $asOfMonth);
        if ($hasFuture) {
            $s3Future[] = '今後の改善優先KPI: 月合計の維持（内訳は収穫ベッド数・kg/ベッド）。';
            // 今後ピークが高い月を提示
            $hi = null;
            foreach ($futurePeaks as $m => $fp) {
                if ($fp['peak_total'] === null) {
                    continue;
                }
                if ($hi === null || $fp['peak_total'] > $hi['peak_total']) {
                    $hi = $fp + ['m' => $m];
                }
            }
            if ($hi) {
                $s3Future[] = sprintf(
                    '%d月の過去最高合計は約%.0fkg — ベッド数≈%s・kg/ベッド≈%sを目安に。',
                    $hi['m'],
                    $hi['peak_total'],
                    $hi['peak_bed'] !== null ? (string)$hi['peak_bed'] . 'ベッド' : '—',
                    $hi['peak_yield'] !== null ? number_format($hi['peak_yield'], 0) : '—'
                );
            }
        } else {
            $s3Future[] = '年間の月合計は達成圏。翌年同月のKPI維持。';
        }
    } else {
        $s3Level = 'warn';
        $s3Title = '経過月の合計不足 — 主因KPIあり';
        foreach (array_slice($totalShort, 0, 3) as $d) {
            $s3Elapsed[] = sprintf(
                '%d月: 合計 %.0fkg（最高%.0f / %d年 · -%.0fkg）— 悪化KPIは「%s」',
                $d['m'],
                $d['cur_total'],
                (float)$d['peak_total'],
                (int)$d['peak_total_year'],
                $d['total_gap'],
                implode('・', $d['primary'])
            );
        }
        $s3Elapsed[] = sprintf('経過の主因KPI集計: %s', $priority);
        if ($hasFuture) {
            $s3Future[] = sprintf('今後の改善優先KPI: %s', $priority);
            $sumGap = array_sum(array_map(static fn($d) => $d['total_gap'], $totalShort));
            $needBeds = (int)ceil($sumGap / $avgYieldKg);
            if ($tally['bed'] >= $tally['yield']) {
                $s3Future[] = sprintf(
                    '収穫ベッド数を優先。経過不足合計≈%.0fkg（約%dベッド分）を定植計画で回収。',
                    $sumGap,
                    $needBeds
                );
                $s3Plan = true;
            } else {
                $s3Future[] = 'kg/ベッド不足への打ち手は、収量の無理上げではなく過栽培ロス抑制。';
                $s3Future[] = '定植計画でピーク月に過密にならないペースを確保（ゴミ化を減らす）。';
                $s3Plan = true;
                if ($tally['bed'] > 0) {
                    $s3Future[] = sprintf('ベッド数も不足月あり → 定植計画で約%dベッド分を補強。', $needBeds);
                }
            }
        } else {
            $s3Future[] = sprintf('年内完了。翌年は優先KPI「%s」で月合計を最高以上へ。', $priority);
        }
    }

    // plan.php 要約
    if ($shortfalls) {
        $top = $shortfalls[0];
        $planSummary = sprintf(
            '実収穫量: 経過%d月の悪化KPIは「%s」。優先は「%s」。',
            $top['m'],
            implode('・', $top['primary']),
            $priority
        );
        if ($tally['yield'] > 0) {
            $planSummary .= ' 収量アップより過栽培にならない定植ペースを。';
        } elseif ($tally['bed'] > 0) {
            $planSummary .= ' 定植計画で収穫ベッド数を確保。';
        }
    } else {
        $planSummary = '実収穫量: 経過月は過去最高ペース。定植は過栽培を避けつつベッド数を維持。';
    }

    return [
        's1' => [
            'level' => $s1Level,
            'title' => $s1Title,
            'elapsed' => $s1Elapsed,
            'future' => $s1Future,
            'plan' => $s1Plan,
        ],
        's2' => [
            'level' => $s2Level,
            'title' => $s2Title,
            'elapsed' => $s2Elapsed,
            'future' => $s2Future,
            'plan' => $s2Plan,
        ],
        's3' => [
            'level' => $s3Level,
            'title' => $s3Title,
            'elapsed' => $s3Elapsed,
            'future' => $s3Future,
            'plan' => $s3Plan,
        ],
        'plan_summary' => $planSummary,
    ];
}

/**
 * @param array{level:string, title:string, elapsed?:list<string>, future?:list<string>, bullets?:list<string>, plan:bool} $block
 */
function gf_render_advice_box(array $block, string $planHref = 'plan.php'): void
{
    $level = $block['level'] ?? 'ok';
    $cls = 'advice-box advice-' . preg_replace('/[^a-z]/', '', $level);
    echo '<div class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">';
    echo '<div class="advice-title">' . htmlspecialchars((string)($block['title'] ?? '打ち手'), ENT_QUOTES, 'UTF-8') . '</div>';

    $elapsed = $block['elapsed'] ?? [];
    $future = $block['future'] ?? [];
    // 後方互換: bullets のみの場合
    if (!$elapsed && !$future && !empty($block['bullets'])) {
        $elapsed = $block['bullets'];
    }

    if ($elapsed) {
        echo '<div class="advice-label">経過月 — 悪化したKPI</div>';
        echo '<ul class="advice-list">';
        foreach ($elapsed as $b) {
            echo '<li>' . htmlspecialchars((string)$b, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }
    if ($future) {
        echo '<div class="advice-label">今後 — 最高へ向け改善するKPI</div>';
        echo '<ul class="advice-list">';
        foreach ($future as $b) {
            echo '<li>' . htmlspecialchars((string)$b, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        echo '</ul>';
    }

    if (!empty($block['plan'])) {
        echo '<a class="advice-link" href="' . htmlspecialchars($planHref, ENT_QUOTES, 'UTF-8') . '">定植計画で過栽培を避けて調整 →</a>';
    }
    echo '</div>';
}
