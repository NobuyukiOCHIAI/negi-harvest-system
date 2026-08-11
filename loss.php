<?php
/**
 * 収穫 vs 実出荷のロス可視化
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/gcal_shipments.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/nav.php';

$weeksBack = max(4, min(26, (int)($_GET['weeks'] ?? 12)));
$today = date('Y-m-d');
$currentWeek = gcal_week_start_sunday($today);
$fromWeek = date('Y-m-d', strtotime('-' . $weeksBack . ' weeks', strtotime($currentWeek)));

$harvestByWeek = [];
$res = mysqli_query(
    $link,
    "SELECT DATE_SUB(h.harvest_date, INTERVAL (DAYOFWEEK(h.harvest_date)-1) DAY) AS week_start,
            SUM(h.harvest_kg) AS harvest_kg,
            SUM(CASE WHEN h.loss_type_id = 2 THEN h.harvest_kg ELSE 0 END) AS gomi_kg
     FROM harvests h
     WHERE h.harvest_date >= DATE_SUB('{$fromWeek}', INTERVAL 0 DAY)
       AND h.harvest_date < DATE_ADD('{$currentWeek}', INTERVAL 7 DAY)
     GROUP BY week_start
     ORDER BY week_start"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $harvestByWeek[$row['week_start']] = [
            'harvest_kg' => (float)$row['harvest_kg'],
            'gomi_kg' => (float)$row['gomi_kg'],
        ];
    }
    mysqli_free_result($res);
}

$shipByWeek = [];
$chk = mysqli_query($link, "SHOW TABLES LIKE 'calendar_shipment_events'");
$hasEvents = $chk && mysqli_num_rows($chk) > 0;
if ($chk) {
    mysqli_free_result($chk);
}
if ($hasEvents) {
    $res = mysqli_query(
        $link,
        "SELECT week_start_date, SUM(amount_kg) AS ship_kg
         FROM calendar_shipment_events
         WHERE ship_date >= '{$fromWeek}' AND ship_date < DATE_ADD('{$currentWeek}', INTERVAL 7 DAY)
         GROUP BY week_start_date"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $shipByWeek[$row['week_start_date']] = (float)$row['ship_kg'];
        }
        mysqli_free_result($res);
    }
}

$rows = [];
$ts = strtotime($fromWeek);
$end = strtotime($currentWeek);
$sumH = 0.0;
$sumS = 0.0;
$sumG = 0.0;
$sumLoss = 0.0;
while ($ts <= $end) {
    $w = date('Y-m-d', $ts);
    $h = (float)($harvestByWeek[$w]['harvest_kg'] ?? 0);
    $g = (float)($harvestByWeek[$w]['gomi_kg'] ?? 0);
    $s = (float)($shipByWeek[$w] ?? 0);
    // ロス候補 = 収穫(ゴミ含む) − 実出荷。正=圃場で余った／記録差、負=出荷が収穫を上回る（在庫取崩し等）
    $loss = round($h - $s, 1);
    $lossRate = $h > 0 ? round(100.0 * ($h - $s) / $h, 1) : null;
    $rows[] = [
        'week' => $w,
        'harvest_kg' => round($h, 1),
        'gomi_kg' => round($g, 1),
        'ship_kg' => round($s, 1),
        'loss_kg' => $loss,
        'loss_rate' => $lossRate,
    ];
    $sumH += $h;
    $sumS += $s;
    $sumG += $g;
    $sumLoss += $loss;
    $ts = strtotime('+7 days', $ts);
}

$chartLabels = array_map(static fn($r) => date('n/j', strtotime($r['week'])), $rows);
$chartH = array_column($rows, 'harvest_kg');
$chartS = array_column($rows, 'ship_kg');
$chartL = array_column($rows, 'loss_kg');
$overallRate = $sumH > 0 ? round(100.0 * ($sumH - $sumS) / $sumH, 1) : null;
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>ロス分析</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">ロス分析</h1>
      <p class="page-sub">収穫量 − 実出荷（GCal明細）。ベッド別出荷は無いため週次突合</p>
    </div>
  </div>

  <form method="get" class="mb-3">
    <select name="weeks" class="form-select" onchange="this.form.submit()">
      <?php foreach ([8, 12, 16, 26] as $w): ?>
        <option value="<?= $w ?>"<?= $weeksBack === $w ? ' selected' : '' ?>><?= $w ?>週</option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if (!$hasEvents): ?>
    <div class="alert alert-warning">calendar_shipment_events がありません。GCal同期後に実出荷が入ります。</div>
  <?php endif; ?>

  <div class="stat-row">
    <div class="stat-card">
      <div class="stat-label">収穫計</div>
      <div class="stat-value" style="font-size:1.1rem"><?= number_format($sumH, 0) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">実出荷計</div>
      <div class="stat-value" style="font-size:1.1rem"><?= number_format($sumS, 0) ?></div>
    </div>
    <div class="stat-card <?= $sumLoss > 50 ? 'warn' : '' ?>">
      <div class="stat-label">差(ロス候補)</div>
      <div class="stat-value" style="font-size:1.1rem"><?= number_format($sumLoss, 0) ?></div>
    </div>
  </div>
  <p class="page-sub mt-2">
    期間ロス率 <?= $overallRate === null ? '—' : $overallRate . '%' ?>
    · うち記録上ゴミ <?= number_format($sumG, 0) ?>kg
    · <a href="capacity.php">需給・営業</a>
  </p>

  <div class="chart-card">
    <div class="chart-title">週次: 収穫 / 実出荷 / 差</div>
    <div class="chart-wrap tall"><canvas id="lossChart"></canvas></div>
  </div>

  <div class="table-responsive mt-3">
    <table class="table table-sm bg-white shadow-sm">
      <thead>
        <tr>
          <th>週</th>
          <th class="text-end">収穫</th>
          <th class="text-end">ゴミ</th>
          <th class="text-end">実出荷</th>
          <th class="text-end">差</th>
          <th class="text-end">%</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_reverse($rows) as $r): ?>
          <tr class="<?= $r['loss_kg'] > 80 ? 'table-warning' : '' ?>">
            <td><?= htmlspecialchars(h_month_week($r['week']), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-end"><?= number_format($r['harvest_kg'], 0) ?></td>
            <td class="text-end"><?= number_format($r['gomi_kg'], 0) ?></td>
            <td class="text-end"><?= number_format($r['ship_kg'], 0) ?></td>
            <td class="text-end fw-bold"><?= number_format($r['loss_kg'], 0) ?></td>
            <td class="text-end"><?= $r['loss_rate'] === null ? '—' : $r['loss_rate'] . '%' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="page-sub">差が正=収穫が多い（選別ロス・在庫・過栽培・未出荷）。負=出荷が収穫超（在庫取崩し・記録漏れ）。</p>
</div>
<?php forecast_nav('inventory'); ?>
<script>
(() => {
  const el = document.getElementById('lossChart');
  if (!el || !window.Chart) return;
  new Chart(el, {
    type: 'bar',
    data: {
      labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [
        { type: 'line', label: '収穫', data: <?= json_encode($chartH) ?>, borderColor: '#1b7a4a', tension: 0.2, yAxisID: 'y' },
        { type: 'line', label: '実出荷', data: <?= json_encode($chartS) ?>, borderColor: '#1976d2', tension: 0.2, yAxisID: 'y' },
        { type: 'bar', label: '差', data: <?= json_encode($chartL) ?>, backgroundColor: 'rgba(196,122,0,0.35)', yAxisID: 'y' }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
      scales: { y: { ticks: { callback: v => v + 'kg' } } }
    }
  });
})();
</script>
</body>
</html>
