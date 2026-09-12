<?php
/**
 * ホーム — 圃場 / 営業の入口（詳細メニューは settings.php）
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/overgrow_metrics.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/plant_schedule.php';
require_once __DIR__ . '/lib/inventory_trust.php';
require_once __DIR__ . '/lib/supply_ops.php';
require_once __DIR__ . '/lib/mgmt_alerts.php';

supply_ensure_full_rotation($link);

$open = open_cycle_progress($link);
$harvestDue = 0;
$today = date('Y-m-d');
foreach ($open as $op) {
    if (!empty($op['harvest_start']) || (!empty($op['expected_harvest']) && $op['expected_harvest'] <= $today)) {
        $harvestDue++;
    }
}
$plantN = 0;
$chk = mysqli_query($link, "SHOW TABLES LIKE 'plant_schedule'");
$hasSchedule = $chk && mysqli_num_rows($chk) > 0;
if ($chk) {
    mysqli_free_result($chk);
}
if ($hasSchedule) {
    $sql = "SELECT COUNT(DISTINCT s.bed_id) AS n
            FROM plant_schedule s
            JOIN beds b ON b.id = s.bed_id AND b.active = 1
            WHERE s.status IN ('planned','approved')
              AND s.planned_plant_date <= ?
              AND NOT EXISTS (
                SELECT 1 FROM cycles c WHERE c.bed_id = s.bed_id AND c.harvest_end IS NULL
              )";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 's', $today);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($res && ($row = mysqli_fetch_assoc($res))) {
        $plantN = (int)$row['n'];
    }
    mysqli_stmt_close($stmt);
}

$trust = trust_outlook_bundle($link, 16);
$trustSum = $trust['summary'];
$delayN = count($trust['plant_delays'] ?? []);
$alertBundle = gf_mgmt_alerts_bundle($link, $trust);
$alertN = (int)$alertBundle['counts']['total'];
$alertCrit = (int)$alertBundle['counts']['critical'];
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
  <link rel="stylesheet" href="css/mobile-ui.css?v=20260912b">
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">GreenFarm</h1>
      <p class="page-sub"><?= h_sunday_week(date('Y-m-d')) ?> · 圃場は定植の遅れを潰す、営業は計画の先行き</p>
    </div>
  </div>

  <h2 class="section-title"><?= gf_icon('harvest') ?> 圃場</h2>
  <p class="page-sub mb-2">今日の定植・収穫を入れる。ベッドの状態は栽培へ。</p>
  <a href="today.php" class="btn btn-primary btn-home-cta w-100 mb-2">
    <?= gf_icon('calendar', 'ico') ?> 今日の作業
  </a>
  <div class="stat-row mb-3">
    <a href="today.php#sec-plant" class="stat-card stat-link <?= $delayN ? 'danger' : ($plantN ? 'ok' : '') ?>">
      <div class="stat-label"><?= $delayN ? '定植遅れ' : '定植' ?></div>
      <div class="stat-value"><?= $delayN ?: $plantN ?></div>
    </a>
    <a href="today.php#sec-harvest" class="stat-card stat-link <?= $harvestDue ? 'warn' : '' ?>">
      <div class="stat-label">収穫</div>
      <div class="stat-value"><?= $harvestDue ?></div>
    </a>
    <a href="monitor.php" class="stat-card stat-link">
      <div class="stat-label">栽培</div>
      <div class="stat-value" style="font-size:1rem;padding-top:0.35rem">モニター</div>
    </a>
  </div>

  <h2 class="section-title"><?= gf_icon('chart') ?> 営業・経営</h2>
  <p class="page-sub mb-2">先行き・異常・実績。取引先非開示の経営常時も含む。</p>
  <div class="stat-row mb-2">
    <a href="inventory.php" class="stat-card stat-link <?= $trustCls ?>">
      <div class="stat-label">割れまで</div>
      <div class="stat-value"><?= (int)$trustSum['runway_weeks'] ?></div>
      <div class="stat-sub">週 · 計画</div>
    </a>
    <a href="alerts.php" class="stat-card stat-link <?= $alertCrit ? 'danger' : ($alertN ? 'warn' : 'ok') ?>">
      <div class="stat-label">アラート</div>
      <div class="stat-value"><?= $alertN ?></div>
      <div class="stat-sub"><?= $alertN ? '要確認' : '異常なし' ?></div>
    </a>
    <a href="actual.php" class="stat-card stat-link">
      <div class="stat-label">収量</div>
      <div class="stat-value" style="font-size:1rem;padding-top:0.35rem">実績</div>
      <div class="stat-sub">ゴミ込み</div>
    </a>
  </div>
  <p class="page-sub mb-0">需給・過栽培・例外は <a href="settings.php">メニュー</a>。収量は下部ナビからも。</p>
</div>
<?php forecast_nav(''); ?>
</body>
</html>
