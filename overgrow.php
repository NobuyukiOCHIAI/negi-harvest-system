<?php
/**
 * 過栽培ダッシュボード — モバイル優先
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/overgrow_metrics.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/nav.php';

$weeks = max(8, min(40, (int)($_GET['weeks'] ?? 16)));
$weekly = overgrow_weekly_stats($link, $weeks);
$open = open_cycle_progress($link);
$openRisk = array_values(array_filter($open, static fn($r) => (int)$r['risk'] === 1));
$thisWeekMon = date('Y-m-d', strtotime('monday this week'));
$lyWeek = overgrow_same_week_last_year($link, $thisWeekMon);
$riskWeeks = array_values(array_filter($weekly, static fn($w) => (int)$w['risk'] === 1));
$meanLate = $weekly ? array_sum(array_column($weekly, 'pct_judge_late')) / count($weekly) : 0;

$chartLabels = [];
$chartLate = [];
$chartDelay = [];
foreach (array_reverse(array_slice($weekly, 0, 12)) as $w) {
    $chartLabels[] = date('n/j', strtotime($w['week']));
    $chartLate[] = (float)$w['pct_judge_late'];
    $chartDelay[] = $w['mean_delay'] === null ? 0 : (float)$w['mean_delay'];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>過栽培ダッシュボード</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">過栽培</h1>
      <p class="page-sub">遅れ＝在庫バッファ / 大きすぎ＝抑制候補</p>
    </div>
  </div>

  <div class="job-card mb-3">
    <div class="job-meta">破棄してベッドを空ける判断・営業への出荷増減リコメンドは <a href="capacity.php">需給・営業</a> へ。</div>
  </div>

  <form method="get" class="mb-3">
    <select name="weeks" class="form-select" onchange="this.form.submit()">
      <?php foreach ([12, 16, 24, 32] as $w): ?>
        <option value="<?= $w ?>"<?= $weeks === $w ? ' selected' : '' ?>><?= $w ?>週表示</option>
      <?php endforeach; ?>
    </select>
  </form>

  <div class="stat-row">
    <div class="stat-card danger"><?= gf_icon('alert', 'stat-ico') ?><div class="stat-label">リスク週</div><div class="stat-value"><?= count($riskWeeks) ?></div></div>
    <div class="stat-card warn"><?= gf_icon('chart', 'stat-ico') ?><div class="stat-label">遅い%</div><div class="stat-value"><?= round($meanLate, 0) ?></div></div>
    <div class="stat-card"><?= gf_icon('bed', 'stat-ico') ?><div class="stat-label">超過ベッド</div><div class="stat-value"><?= count($openRisk) ?></div></div>
  </div>

  <div class="chart-card">
    <div class="chart-title">定植週 · 「遅い」% と平均遅延日</div>
    <div class="chart-wrap tall"><canvas id="ogChart"></canvas></div>
  </div>

  <h2 class="section-title"><?= gf_icon('alert') ?> 未完了・超過ベッド</h2>
  <?php if (!$openRisk): ?>
    <div class="job-card"><div class="job-meta">該当なし</div></div>
  <?php else: ?>
    <?php foreach ($openRisk as $op): ?>
      <a class="job-card risk" href="cycle.php?id=<?= (int)$op['cycle_id'] ?>">
        <div class="job-top">
          <div>
            <div class="job-name"><?= htmlspecialchars($op['bed_name'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="job-meta">定植 <?= h_month_week($op['plant_date'] ?? null) ?> · 予測 <?= h_month_week($op['expected_harvest'] ?? null) ?></div>
            <div class="job-meta text-danger fw-bold"><?= htmlspecialchars($op['buffer_label'], ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <div class="fw-bold"><?= $op['pred_yield'] !== null ? (int)$op['pred_yield'] . 'kg' : '' ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <p class="page-sub mt-3">定植ペース抑制は <a href="plan.php">計画</a> の自動生成で反映されます。</p>
</div>
<?php forecast_nav('settings'); ?>
<script>
new Chart(document.getElementById('ogChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
      { label: '遅い%', data: <?= json_encode($chartLate) ?>, backgroundColor: '#c62828', yAxisID: 'y' },
      { label: '平均遅延日', data: <?= json_encode($chartDelay) ?>, type: 'line', borderColor: '#c47a00', yAxisID: 'y1', tension: 0.2 }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } },
    scales: {
      y: { beginAtZero: true, position: 'left', ticks: { font: { size: 10 } } },
      y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { font: { size: 10 } } },
      x: { ticks: { font: { size: 9 }, maxRotation: 45 } }
    }
  }
});
</script>
</body>
</html>
