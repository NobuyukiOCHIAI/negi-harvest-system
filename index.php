<?php
/**
 * ホーム — 役割別入口（圃場 / 営業）
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/overgrow_metrics.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/plant_schedule.php';
require_once __DIR__ . '/lib/inventory_trust.php';
require_once __DIR__ . '/lib/supply_ops.php';
require_once __DIR__ . '/lib/staff_recommend.php';

supply_ensure_full_rotation($link);

$open = open_cycle_progress($link);
$riskN = count(array_filter($open, static fn($r) => (int)$r['risk'] === 1));
$harvestDue = 0;
$today = date('Y-m-d');
foreach ($open as $op) {
    if (!empty($op['harvest_start']) || (!empty($op['expected_harvest']) && $op['expected_harvest'] <= $today)) {
        $harvestDue++;
    }
}
$plantN = 0;
$chk = mysqli_query($link, "SHOW TABLES LIKE 'plant_schedule'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $r = mysqli_query(
        $link,
        "SELECT COUNT(*) AS n FROM plant_schedule
         WHERE status IN ('planned','approved') AND planned_plant_date <= CURDATE()"
    );
    if ($r && ($row = mysqli_fetch_assoc($r))) {
        $plantN = (int)$row['n'];
    }
}
if ($chk) {
    mysqli_free_result($chk);
}

$trust = trust_outlook_bundle($link, 16);
$trustSum = $trust['summary'];
$autoN = count(staff_auto_recommendations($link));
$emptyN = count(plant_schedule_empty_beds($link));
$growing = count(array_filter($open, static fn($o) => empty($o['harvest_start'])));
$harvesting = count(array_filter($open, static fn($o) => !empty($o['harvest_start'])));
$trustCls = [
    'ok' => 'ok',
    'watch' => 'warn',
    'warn' => 'warn',
    'critical' => 'danger',
][$trustSum['status']] ?? 'warn';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>栽培予測ホーム</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">GreenFarm 栽培予測</h1>
      <p class="page-sub"><?= h_month_week(date('Y-m-d')) ?> · 役割別メニュー</p>
    </div>
  </div>

  <div class="job-card mb-3" style="border-left:4px solid <?= $trustSum['status'] === 'ok' ? 'var(--gf-green)' : 'var(--gf-amber)' ?>">
    <div class="job-meta fw-bold">需給の状態（営業の見るべき一点）</div>
    <div class="job-meta fw-bold mt-1"><?= htmlspecialchars($trustSum['status_label'], ENT_QUOTES, 'UTF-8') ?></div>
    <div class="job-meta">
      累計在庫の割れまで <strong><?= (int)$trustSum['runway_weeks'] ?>週</strong>
      <?php if ($trustSum['first_break_week']): ?>
        · 初回 <?= h_sunday_week($trustSum['first_break_week']) ?>
      <?php endif; ?>
    </div>
    <a class="btn btn-sm btn-outline-success mt-2" href="inventory.php">収穫予測で詳しく見る</a>
  </div>

  <h2 class="section-title"><?= gf_icon('harvest') ?> 圃場スタッフ</h2>
  <p class="page-sub mb-2">作業結果の入力がメイン。まず「今日の作業」へ。</p>
  <a href="today.php" class="btn btn-primary btn-cta w-100 mb-2">
    <?= gf_icon('calendar', 'ico') ?> 今日の作業（リコメンド付き）
  </a>
  <div class="stat-row mb-2">
    <a href="today.php#sec-plant" class="stat-card stat-link <?= $plantN ? 'ok' : '' ?>">
      <div class="stat-label">定植</div>
      <div class="stat-value"><?= $plantN ?></div>
    </a>
    <a href="today.php#sec-harvest" class="stat-card stat-link <?= $harvestDue ? 'warn' : '' ?>">
      <div class="stat-label">収穫</div>
      <div class="stat-value"><?= $harvestDue ?></div>
    </a>
    <a href="today.php" class="stat-card stat-link <?= $autoN ? 'danger' : '' ?>">
      <div class="stat-label">指示</div>
      <div class="stat-value"><?= $autoN ?></div>
    </a>
  </div>
  <div class="list-group quick-links rounded-3 overflow-hidden shadow-sm mb-4">
    <a class="list-group-item list-group-item-action" href="data_entry/planting.php"><?= gf_icon('plant', 'ql-ico') ?>定植入力<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="data_entry/harvest.php"><?= gf_icon('harvest', 'ql-ico') ?>収穫入力<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="monitor.php"><?= gf_icon('bed', 'ql-ico') ?>栽培モニター<?= gf_icon('arrow', 'ql-chevron') ?></a>
  </div>

  <h2 class="section-title"><?= gf_icon('chart') ?> 営業・管理</h2>
  <p class="page-sub mb-2">常時フル稼働がデフォルト。一時余剰はスポット、数か月トレンドのみベース交渉（減少を優先）。</p>
  <div class="stat-row mb-2">
    <a href="inventory.php" class="stat-card stat-link <?= $trustCls ?>">
      <div class="stat-label">割れまで</div>
      <div class="stat-value"><?= (int)$trustSum['runway_weeks'] ?></div>
      <div class="stat-sub">週</div>
    </a>
    <a href="capacity.php" class="stat-card stat-link">
      <div class="stat-label">空きベッド</div>
      <div class="stat-value"><?= $emptyN ?></div>
    </a>
    <a href="overgrow.php" class="stat-card stat-link <?= $riskN ? 'danger' : '' ?>">
      <div class="stat-label">過栽培</div>
      <div class="stat-value"><?= $riskN ?></div>
    </a>
  </div>
  <div class="list-group quick-links rounded-3 overflow-hidden shadow-sm mb-3">
    <a class="list-group-item list-group-item-action" href="inventory.php"><?= gf_icon('chart', 'ql-ico') ?>① 収穫予測（累計在庫・信頼の核）<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="capacity.php"><?= gf_icon('chart', 'ql-ico') ?>② 需給・営業（トレンド/一時アラート）<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="agent.php"><?= gf_icon('alert', 'ql-ico') ?>監視エージェント<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="plan.php"><?= gf_icon('calendar', 'ql-ico') ?>③ 定植計画（常時回転は自動）<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="loss.php"><?= gf_icon('chart', 'ql-ico') ?>ロス分析（収穫−実出荷）<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="actual.php"><?= gf_icon('harvest', 'ql-ico') ?>実収穫量（昨対）<?= gf_icon('arrow', 'ql-chevron') ?></a>
  </div>

  <div class="chart-card">
    <div class="chart-title">未完了ベッドの状態</div>
    <div class="chart-wrap doughnut">
      <canvas id="homeStatus"></canvas>
    </div>
  </div>
</div>
<?php forecast_nav(''); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('homeStatus'), {
  type: 'doughnut',
  data: {
    labels: ['栽培中', '収穫中', '過栽培候補'],
    datasets: [{
      data: [<?= $growing ?>, <?= $harvesting ?>, <?= $riskN ?>],
      backgroundColor: ['#1b7a4a', '#c47a00', '#c62828'],
      borderWidth: 0
    }]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
    cutout: '60%'
  }
});
</script>
</body>
</html>
