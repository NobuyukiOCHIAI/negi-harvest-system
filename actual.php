<?php
/**
 * 実収穫量 — 年次比較・月次推移
 * ① 月ごとのベッドあたり収量・収穫ベッド数の年次比較
 * ② 1年のなかの月次推移
 * ③ 月合計収量の年次比較
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/actual_advice.php';

$focusYear = (int)($_GET['year'] ?? date('Y'));
if ($focusYear < 2020 || $focusYear > 2100) {
    $focusYear = (int)date('Y');
}

$loaded = gf_actual_load_monthly($link);
$viewOk = $loaded['ok'];
$matrix = $loaded['matrix'];
$years = $loaded['years'];

if (!$viewOk) {
    ?><!DOCTYPE html><html lang="ja"><head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>実収穫量</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
</head><body>
<div class="container py-3">
  <div class="alert alert-danger">ビュー未作成です。sql/_apply_harvest_actual_views.py を実行してください。</div>
</div>
<?php forecast_nav('inventory'); ?>
</body></html><?php
    exit;
}

if (!$years) {
    $years = [$focusYear];
}
if (!in_array($focusYear, $years, true)) {
    // 選択年にデータが無くても一覧に含めて表示（空系列）
    $years[] = $focusYear;
    rsort($years);
}

// 比較対象年: フォーカス年と直近最大3年（フォーカス含む）
$compareYears = [];
foreach ($years as $y) {
    if ($y <= $focusYear) {
        $compareYears[] = $y;
    }
    if (count($compareYears) >= 3) {
        break;
    }
}
sort($compareYears); // 古い→新しい

$monthLabels = [];
for ($m = 1; $m <= 12; $m++) {
    $monthLabels[] = $m . '月';
}

/** 基準年=赤+マーカー。他年=マーカーなし・赤と対比しやすい色 */
$otherYearColors = ['#1565c0', '#00897b', '#6a4c93']; // 青 / ティール /（予備）

function series_for(array $matrix, array $compareYears, string $key): array
{
    $out = [];
    foreach ($compareYears as $y) {
        $vals = [];
        for ($m = 1; $m <= 12; $m++) {
            $cell = $matrix[$y][$m] ?? null;
            if ($cell === null) {
                $vals[] = null;
            } elseif ($key === 'kg_per_bed') {
                $vals[] = $cell['kg_per_bed'];
            } elseif ($key === 'bed_count') {
                $vals[] = $cell['bed_count'];
            } else {
                $vals[] = $cell['total_kg'];
            }
        }
        $out[$y] = $vals;
    }
    return $out;
}

$sPerBed = series_for($matrix, $compareYears, 'kg_per_bed');
$sBeds = series_for($matrix, $compareYears, 'bed_count');
$sTotal = series_for($matrix, $compareYears, 'total_kg');

$focusPerBed = $sPerBed[$focusYear] ?? array_fill(0, 12, null);
$focusBeds = $sBeds[$focusYear] ?? array_fill(0, 12, null);
$focusTotal = $sTotal[$focusYear] ?? array_fill(0, 12, null);

$avgYield = 120.0;
if (is_file(__DIR__ . '/lib/plant_schedule.php')) {
    require_once __DIR__ . '/lib/plant_schedule.php';
    if (function_exists('plant_schedule_season_defaults')) {
        $defs = plant_schedule_season_defaults($link);
        $avgYield = (float)($defs['yield'] ?? 120.0);
    }
}

$advice = gf_actual_build_advice($matrix, $years, $focusYear, $avgYield);

// 数値ボード用: 比較年のベッドあたり最大（バー幅）
$maxPerBed = 1.0;
foreach ($compareYears as $y) {
    for ($m = 1; $m <= 12; $m++) {
        $v = $matrix[$y][$m]['kg_per_bed'] ?? null;
        if ($v !== null && (float)$v > $maxPerBed) {
            $maxPerBed = (float)$v;
        }
    }
}

$openMonth = (int)date('n'); // アコーディオン既定: 当月

function chart_datasets(array $seriesByYear, array $compareYears, int $focusYear, array $otherYearColors, string $prefix = ''): array
{
    $ds = [];
    $otherIdx = 0;
    foreach ($compareYears as $y) {
        $isFocus = ((int)$y === $focusYear);
        if ($isFocus) {
            $color = '#c62828';
            $radius = 5;
            $borderWidth = 3;
        } else {
            $color = $otherYearColors[$otherIdx] ?? '#555555';
            $otherIdx++;
            $radius = 0;
            $borderWidth = 2;
        }
        $ds[] = [
            'label' => (string)$y . $prefix,
            'data' => array_values($seriesByYear[$y]),
            'borderColor' => $color,
            'backgroundColor' => $color,
            'tension' => 0.25,
            'spanGaps' => false,
            'pointRadius' => $radius,
            'pointHoverRadius' => $isFocus ? 7 : 3,
            'pointStyle' => 'circle',
            'borderWidth' => $borderWidth,
        ];
    }
    return $ds;
}

$isCalendarYear = ($focusYear === (int)date('Y'));
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>実収穫量</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    .filter-bar { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:0.85rem; align-items:center; }
    .filter-bar label { font-size:0.8rem; font-weight:700; color:var(--gf-muted); margin:0; }
    .filter-bar select {
      border-radius:10px; border:1px solid var(--gf-line); padding:0.4rem 0.65rem;
      font-weight:700; font-size:0.85rem; background:#fff;
    }
    .purpose {
      background: var(--gf-bg); border-radius: 10px; padding: 0.65rem 0.85rem;
      font-size: 0.82rem; color: var(--gf-muted); margin-bottom: 1rem; line-height: 1.45;
    }
    .purpose strong { color: var(--gf-ink); }
    .advice-box {
      border-radius: 12px; padding: 0.7rem 0.85rem; margin: 0.35rem 0 1.1rem;
      border: 1px solid var(--gf-line); background: #fff;
    }
    .advice-ok { background: var(--gf-green-soft); border-color: #b7dfc8; }
    .advice-warn { background: var(--gf-amber-soft); border-color: #f0d9a0; }
    .advice-danger { background: var(--gf-red-soft); border-color: #f0b8b8; }
    .advice-title { font-weight: 800; font-size: 0.88rem; margin-bottom: 0.35rem; color: var(--gf-ink); }
    .advice-label {
      font-size: 0.72rem; font-weight: 800; letter-spacing: 0.02em;
      color: var(--gf-muted); margin: 0.45rem 0 0.2rem;
    }
    .advice-list { margin: 0; padding-left: 1.1rem; font-size: 0.8rem; color: var(--gf-ink); line-height: 1.45; }
    .advice-list li { margin-bottom: 0.2rem; }
    .advice-link {
      display: inline-block; margin-top: 0.45rem; font-size: 0.82rem; font-weight: 800;
      color: var(--gf-green-dark); text-decoration: none;
    }
    .advice-link:hover { text-decoration: underline; }
    .delta-pos { color: var(--gf-green); font-weight: 800; }
    .delta-neg { color: var(--gf-red); font-weight: 800; }
    .sec-block {
      background: var(--gf-card);
      border: 1px solid var(--gf-line);
      border-radius: var(--gf-radius);
      padding: 0.75rem 0.75rem 0.35rem;
      margin-bottom: 1.15rem;
      box-shadow: var(--gf-shadow);
    }
    .sec-block > .section-title { margin-top: 0; }
    .sec-block .chart-card {
      box-shadow: none;
      margin-bottom: 0.65rem;
    }
    .sec-block .advice-box { margin-bottom: 0.75rem; }
    .num-board { margin: 0.15rem 0 0.35rem; }
    .num-month {
      background: #fff; border: 1px solid var(--gf-line); border-radius: 10px;
      margin-bottom: 0.45rem; overflow: hidden;
    }
    .num-month[open] { border-color: #c5d6cc; }
    .num-month-h {
      display: flex; justify-content: space-between; align-items: center; gap: 0.5rem;
      padding: 0.55rem 0.75rem; background: var(--gf-bg);
      font-weight: 800; font-size: 0.92rem; cursor: pointer; list-style: none;
    }
    .num-month-h::-webkit-details-marker { display: none; }
    .num-month-h::after {
      content: '▸'; color: var(--gf-muted); font-size: 0.85rem; flex-shrink: 0;
    }
    .num-month[open] > .num-month-h::after { content: '▾'; }
    .num-month-h .sub { font-size: 0.72rem; font-weight: 600; color: var(--gf-muted); text-align: right; }
    .num-month-h .left { display: flex; align-items: baseline; gap: 0.4rem; min-width: 0; }
    .num-month.current > .num-month-h { background: #fff5f5; }
    .num-month.current > .num-month-h .left > span:first-child { color: #c62828; }
    .num-row {
      display: grid;
      grid-template-columns: 4.2rem 1fr;
      gap: 0.35rem 0.55rem;
      padding: 0.55rem 0.75rem;
      border-top: 1px solid var(--gf-line);
      align-items: center;
    }
    .num-row.focus { background: #fff5f5; }
    .num-row .yr { font-weight: 800; font-size: 0.82rem; }
    .num-row.focus .yr { color: #c62828; }
    .num-metrics {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 0.25rem 0.4rem;
      font-size: 0.72rem;
    }
    .num-metrics .k { color: var(--gf-muted); font-weight: 600; }
    .num-metrics .v { font-weight: 800; font-size: 0.88rem; color: var(--gf-ink); }
    .num-kpi-wrap { grid-column: 1 / -1; margin-top: 0.15rem; }
    .num-kpi-label {
      display: flex; justify-content: space-between; align-items: baseline;
      font-size: 0.72rem; color: var(--gf-muted); font-weight: 700; margin-bottom: 0.15rem;
    }
    .num-kpi-val {
      font-size: 1.35rem; font-weight: 800; color: var(--gf-ink); line-height: 1;
      font-variant-numeric: tabular-nums;
    }
    .num-row.focus .num-kpi-val { color: #c62828; }
    .num-bar {
      height: 8px; border-radius: 999px; background: #e8eef2; overflow: hidden;
    }
    .num-bar > i {
      display: block; height: 100%; border-radius: 999px; background: #5b8db8;
    }
    .num-row.focus .num-bar > i { background: #c62828; }
    .spec-note {
      font-size: 0.78rem; color: var(--gf-muted); line-height: 1.45;
      margin: 0.35rem 0 0.85rem; padding: 0.55rem 0.7rem;
      background: #fff8e8; border: 1px solid #f0d9a0; border-radius: 10px;
    }
  </style>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">実収穫量</h1>
      <p class="page-sub">年次比較 · 月次推移</p>
    </div>
  </div>

  <form class="filter-bar" method="get" action="actual.php">
    <label for="year">基準年</label>
    <select id="year" name="year" onchange="this.form.submit()">
      <?php foreach ($years as $y): ?>
        <option value="<?= (int)$y ?>" <?= ((int)$y === $focusYear) ? 'selected' : '' ?>><?= (int)$y ?>年</option>
      <?php endforeach; ?>
    </select>
  </form>

  <div class="purpose">
    <strong>①</strong> 年次比較（グラフ＋月別年次比較）　
    <strong>②</strong> <?= $focusYear ?>年のなかの月次推移　
    <strong>③</strong> 月合計収量の年次比較
    <br>比較年: <?= htmlspecialchars(implode(' / ', $compareYears), ENT_QUOTES, 'UTF-8') ?>
    （<span style="color:#c62828;font-weight:800"><?= $focusYear ?><?= $isCalendarYear ? '＝当年' : '＝基準年' ?></span>）
  </div>
  <div class="spec-note">
    収量の単純アップは難しい前提。改善余地は<strong>過栽培ロス（ゴミ化）の抑制</strong>。
    定植計画でペースを調整し、過栽培にならない立案を優先します。
  </div>

  <!-- ① 年次比較 -->
  <section class="sec-block" id="sec1">
  <h2 class="section-title"><?= gf_icon('chart') ?> ① 年次比較（ベッドあたり・収穫ベッド数）</h2>
  <div class="chart-card">
    <div class="chart-title">ベッドあたり収量（kg/ベッド）</div>
    <div class="chart-wrap tall"><canvas id="yoyPerBed"></canvas></div>
  </div>
  <div class="chart-card">
    <div class="chart-title">収穫ベッド数</div>
    <div class="chart-wrap tall"><canvas id="yoyBeds"></canvas></div>
  </div>

  <div class="chart-card">
    <div class="chart-title">月別年次比較</div>
    <p class="page-sub" style="margin:0 0 0.55rem">強調＝ベッドあたり（合計÷個数）。1週あたり＝月合計÷4。当月のみ開いて表示</p>
    <div class="num-board">
      <?php for ($m = 1; $m <= 12; $m++):
        $yearCells = [];
        $sumCount = 0;
        $sumKg = 0.0;
        foreach ($compareYears as $y) {
          $c = $matrix[$y][$m] ?? null;
          if ($c === null) {
            continue;
          }
          $yearCells[] = ['y' => (int)$y, 'c' => $c];
          $sumCount += (int)$c['cycle_count'];
          $sumKg += (float)$c['total_kg'];
        }
        if (!$yearCells) {
          continue;
        }
        $sumPer = $sumCount > 0 ? round($sumKg / $sumCount, 0) : null;
        $isOpen = ($m === $openMonth);
      ?>
        <details class="num-month<?= $isOpen ? ' current' : '' ?>"<?= $isOpen ? ' open' : '' ?>>
          <summary class="num-month-h">
            <span class="left">
              <span><?= $m ?>月<?= $isOpen ? '（当月）' : '' ?></span>
            </span>
            <span class="sub">
              計 個数<?= (int)$sumCount ?> · 合計<?= number_format($sumKg, 0) ?>kg
              <?php if ($sumPer !== null): ?> · ベッドあたり<?= (int)$sumPer ?><?php endif; ?>
            </span>
          </summary>
          <?php foreach ($yearCells as $yc):
            $y = $yc['y'];
            $c = $yc['c'];
            $per = $c['kg_per_bed'];
            $pct = ($per !== null && $maxPerBed > 0) ? max(4, min(100, round(100 * (float)$per / $maxPerBed))) : 0;
            $isFocus = ($y === $focusYear);
          ?>
            <div class="num-row<?= $isFocus ? ' focus' : '' ?>">
              <div class="yr"><?= $y ?>年</div>
              <div>
                <div class="num-metrics">
                  <div>
                    <div class="k">個数</div>
                    <div class="v"><?= (int)$c['cycle_count'] ?></div>
                  </div>
                  <div>
                    <div class="k">合計kg</div>
                    <div class="v"><?= number_format((float)$c['total_kg'], 0) ?></div>
                  </div>
                  <div>
                    <div class="k">1週あたり</div>
                    <div class="v"><?= number_format((float)$c['kg_per_week'], 0) ?></div>
                  </div>
                </div>
                <div class="num-kpi-wrap">
                  <div class="num-kpi-label">
                    <span>ベッドあたり収穫量</span>
                    <span class="num-kpi-val"><?= $per !== null ? number_format((float)$per, 0) : '—' ?></span>
                  </div>
                  <div class="num-bar" aria-hidden="true"><i style="width:<?= (int)$pct ?>%"></i></div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </details>
      <?php endfor; ?>
    </div>
  </div>
  <?php gf_render_advice_box($advice['s1']); ?>
  </section>

  <!-- ② 基準年の月次推移 -->
  <section class="sec-block" id="sec2">
  <h2 class="section-title"><?= gf_icon('calendar') ?> ② <?= $focusYear ?>年の月次推移</h2>
  <div class="chart-card">
    <div class="chart-title">合計kg</div>
    <div class="chart-wrap tall"><canvas id="trendTotal"></canvas></div>
  </div>
  <div class="chart-card">
    <div class="chart-title">ベッドあたり / 収穫ベッド数</div>
    <div class="chart-wrap tall"><canvas id="trendPerBed"></canvas></div>
  </div>
  <?php gf_render_advice_box($advice['s2']); ?>
  </section>

  <!-- ③ 合計の年次比較 -->
  <section class="sec-block" id="sec3">
  <h2 class="section-title"><?= gf_icon('chart') ?> ③ 月合計収量の年次比較</h2>
  <div class="chart-card">
    <div class="chart-title">月合計収量（kg）</div>
    <div class="chart-wrap tall"><canvas id="yoyTotal"></canvas></div>
  </div>
  <?php gf_render_advice_box($advice['s3']); ?>
  </section>

  <h2 class="section-title"><?= gf_icon('chart') ?> 一覧表</h2>
  <div class="table-responsive desktop-only mb-3">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>月</th>
          <?php foreach ($compareYears as $y): ?>
            <th class="text-end"><?= $y ?> ベッドあたり</th>
            <th class="text-end"><?= $y ?> ベッド数</th>
            <th class="text-end"><?= $y ?> 合計</th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <tr>
          <td><?= $m ?>月</td>
          <?php foreach ($compareYears as $y):
            $c = $matrix[$y][$m] ?? null;
          ?>
            <td class="text-end"><?= $c && $c['kg_per_bed'] !== null ? number_format($c['kg_per_bed'], 1) : '—' ?></td>
            <td class="text-end"><?= $c ? (int)$c['bed_count'] : '—' ?></td>
            <td class="text-end"><?= $c ? number_format($c['total_kg'], 0) : '—' ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
  </div>
</div>
<?php forecast_nav('inventory'); ?>
<script>
const labels = <?= json_encode($monthLabels, JSON_UNESCAPED_UNICODE) ?>;
const opt = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom',
      labels: { boxWidth: 12, font: { size: 10 }, usePointStyle: true }
    }
  },
  scales: {
    y: { beginAtZero: true, ticks: { font: { size: 10 } } },
    x: { ticks: { font: { size: 10 } } }
  }
};

new Chart(document.getElementById('trendTotal'), {
  type: 'bar',
  data: {
    labels,
    datasets: [{
      label: '<?= $focusYear ?> 合計kg',
      data: <?= json_encode(array_values($focusTotal)) ?>,
      backgroundColor: '#c62828'
    }]
  },
  options: opt
});

new Chart(document.getElementById('trendPerBed'), {
  type: 'line',
  data: {
    labels,
    datasets: [
      {
        label: 'ベッドあたりkg',
        data: <?= json_encode(array_values($focusPerBed)) ?>,
        borderColor: '#c62828',
        backgroundColor: 'rgba(198,40,40,0.12)',
        yAxisID: 'y',
        tension: 0.25,
        fill: true,
        pointRadius: 5,
        borderWidth: 3
      },
      {
        label: '収穫ベッド数',
        data: <?= json_encode(array_values($focusBeds)) ?>,
        borderColor: '#1565c0',
        backgroundColor: '#1565c0',
        yAxisID: 'y1',
        tension: 0.25,
        borderDash: [4, 3],
        pointRadius: 0,
        borderWidth: 2
      }
    ]
  },
  options: {
    ...opt,
    scales: {
      y: { beginAtZero: true, position: 'left', title: { display: true, text: 'kg/ベッド', font: { size: 10 } }, ticks: { font: { size: 10 } } },
      y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'ベッド数', font: { size: 10 } }, ticks: { font: { size: 10 } } },
      x: { ticks: { font: { size: 10 } } }
    }
  }
});

new Chart(document.getElementById('yoyPerBed'), {
  type: 'line',
  data: {
    labels,
    datasets: <?= json_encode(chart_datasets($sPerBed, $compareYears, $focusYear, $otherYearColors, ' kg/ベッド'), JSON_UNESCAPED_UNICODE) ?>
  },
  options: opt
});

new Chart(document.getElementById('yoyBeds'), {
  type: 'line',
  data: {
    labels,
    datasets: <?= json_encode(chart_datasets($sBeds, $compareYears, $focusYear, $otherYearColors, ' ベッド'), JSON_UNESCAPED_UNICODE) ?>
  },
  options: opt
});

new Chart(document.getElementById('yoyTotal'), {
  type: 'line',
  data: {
    labels,
    datasets: <?= json_encode(chart_datasets($sTotal, $compareYears, $focusYear, $otherYearColors, ' kg'), JSON_UNESCAPED_UNICODE) ?>
  },
  options: opt
});
</script>
</body>
</html>
