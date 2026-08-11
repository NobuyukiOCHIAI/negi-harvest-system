<?php
/**
 * 今日の作業（スタッフ指示・Web正本）— モバイル優先
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/overgrow_metrics.php';
require_once __DIR__ . '/lib/capacity_outlook.php';
require_once __DIR__ . '/lib/staff_recommend.php';
require_once __DIR__ . '/lib/supply_ops.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/nav.php';

$ensure = supply_ensure_full_rotation($link);
$today = date('Y-m-d');
$autoRecs = staff_auto_recommendations($link);

$plantJobs = [];
$chk = mysqli_query($link, "SHOW TABLES LIKE 'plant_schedule'");
$hasSchedule = $chk && mysqli_num_rows($chk) > 0;
if ($chk) {
    mysqli_free_result($chk);
}
if ($hasSchedule) {
    $sql = "
SELECT s.*, b.name AS bed_name, b.group_type
FROM plant_schedule s
JOIN beds b ON b.id = s.bed_id
WHERE s.status IN ('planned','approved')
  AND s.planned_plant_date <= ?
  AND NOT EXISTS (
    SELECT 1 FROM cycles c WHERE c.bed_id = s.bed_id AND c.harvest_end IS NULL
  )
ORDER BY s.planned_plant_date ASC, b.name ASC
";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 's', $today);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($res)) {
        $plantJobs[] = $row;
    }
    mysqli_stmt_close($stmt);
}

$harvestJobs = [];
$open = open_cycle_progress($link);
foreach ($open as $op) {
    $due = false;
    if (!empty($op['harvest_start'])) {
        $due = true;
    } elseif (!empty($op['expected_harvest']) && $op['expected_harvest'] <= $today) {
        $due = true;
    }
    if ($due) {
        $harvestJobs[] = $op;
    }
}

$emptyBeds = [];
$eres = mysqli_query(
    $link,
    "SELECT b.id, b.name, b.group_type FROM beds b
     WHERE b.active=1
       AND NOT EXISTS (SELECT 1 FROM cycles c WHERE c.bed_id=b.id AND c.harvest_end IS NULL)
     ORDER BY b.name LIMIT 20"
);
if ($eres) {
    while ($row = mysqli_fetch_assoc($eres)) {
        $emptyBeds[] = $row;
    }
    mysqli_free_result($eres);
}

$nPlant = count($plantJobs);
$nHarvest = count($harvestJobs);
$discardJobs = capacity_discard_candidates($link);
$nDiscard = count($discardJobs);
$wd = ['日','月','火','水','木','金','土'][(int)date('w')];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>今日の作業</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">今日の作業</h1>
      <p class="page-sub"><?= htmlspecialchars(date('n月j日', strtotime($today)) . "（{$wd}）", ENT_QUOTES, 'UTF-8') ?> · 現場指示</p>
    </div>
  </div>

  <?php if (!empty($ensure['created'])): ?>
    <div class="alert alert-success py-2">常時フル稼働: 空きベッドへ定植計画を <?= (int)$ensure['created'] ?> 件自動追加しました</div>
  <?php endif; ?>

  <div class="stat-row">
    <a href="#sec-plant" class="stat-card stat-link <?= $nPlant > 0 ? 'ok' : '' ?>">
      <?= gf_icon('plant', 'stat-ico') ?>
      <div class="stat-label">定植</div>
      <div class="stat-value"><?= $nPlant ?></div>
    </a>
    <a href="#sec-harvest" class="stat-card stat-link <?= $nHarvest > 0 ? 'warn' : '' ?>">
      <?= gf_icon('harvest', 'stat-ico') ?>
      <div class="stat-label">収穫候補</div>
      <div class="stat-value"><?= $nHarvest ?></div>
    </a>
    <a href="capacity.php" class="stat-card stat-link <?= $nDiscard > 0 ? 'danger' : '' ?>">
      <?= gf_icon('alert', 'stat-ico') ?>
      <div class="stat-label">破棄候補</div>
      <div class="stat-value"><?= $nDiscard ?></div>
    </a>
  </div>

  <h2 class="section-title"><?= gf_icon('alert') ?> 自動リコメンド（優先順）</h2>
  <p class="page-sub mb-2">空き<?= (int)GF_REPLANT_GRACE_DAYS ?>日ルール・計画定植・収穫・破棄を統合</p>
  <?php if (!$autoRecs): ?>
    <div class="job-card"><div class="job-meta">緊急案件なし</div></div>
  <?php else: ?>
    <?php foreach (array_slice($autoRecs, 0, 8) as $rec): ?>
      <?php
        $cardCls = $rec['urgency'] === 'critical' ? 'risk' : ($rec['urgency'] === 'high' ? 'risk' : '');
      ?>
      <a class="job-card <?= $cardCls ?>" href="<?= htmlspecialchars($rec['href'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="job-top">
          <div>
            <div class="job-name"><?= htmlspecialchars($rec['title'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="job-meta"><?= htmlspecialchars($rec['detail'], ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <?= gf_icon('arrow', 'job-chevron') ?>
        </div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>

  <h2 id="sec-plant" class="section-title"><?= gf_icon('plant') ?> 今日の定植</h2>
  <?php if (!$hasSchedule): ?>
    <p class="text-muted small">定植計画未設定。<a href="plan.php">計画画面</a>へ</p>
  <?php elseif (!$plantJobs): ?>
    <div class="job-card"><div class="job-meta">本日までの未実施定植はありません</div></div>
  <?php else: ?>
    <div class="job-grid">
      <?php foreach ($plantJobs as $j):
        $late = $j['planned_plant_date'] < $today;
        ?>
        <div class="job-card compact <?= $late ? 'risk' : '' ?>">
          <div class="job-name"><?= htmlspecialchars($j['bed_name'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="job-meta">
            <?= htmlspecialchars($j['group_type'], ENT_QUOTES, 'UTF-8') ?>
            · <?= htmlspecialchars($j['planned_plant_date'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($late): ?><span class="badge-status late">遅れ</span><?php endif; ?>
          </div>
          <a class="btn btn-success btn-sm w-100 mt-2"
             href="data_entry/planting.php?bed_id=<?= (int)$j['bed_id'] ?>&schedule_id=<?= (int)$j['id'] ?>">定植入力</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 id="sec-harvest" class="section-title"><?= gf_icon('harvest') ?> 今日の収穫候補</h2>
  <?php if (!$harvestJobs): ?>
    <div class="job-card"><div class="job-meta">予測日到達・収穫中のベッドはありません</div></div>
  <?php else: ?>
    <div class="job-grid">
      <?php foreach ($harvestJobs as $h): ?>
        <div class="job-card compact <?= $h['risk'] ? 'risk' : '' ?>">
          <div class="job-name"><?= htmlspecialchars($h['bed_name'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="job-meta">
            <?= $h['harvest_start'] ? '収穫中' : '初回候補' ?>
            · <?= h_month_week($h['expected_harvest'] ?? null) ?>
          </div>
          <div class="job-meta <?= $h['risk'] ? 'text-danger fw-bold' : '' ?>">
            <?= htmlspecialchars($h['buffer_label'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($h['pred_yield'] !== null): ?>
              · <?= (int)round($h['pred_yield']) ?>kg
            <?php endif; ?>
          </div>
          <a class="btn btn-warning btn-sm w-100 mt-2"
             href="data_entry/harvest.php?cycle_id=<?= (int)$h['cycle_id'] ?>">収穫を登録</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($nDiscard > 0): ?>
  <h2 class="section-title"><?= gf_icon('alert') ?> 破棄検討（ベッド回転）</h2>
  <p class="page-sub mb-2">予測超過が長く、次定植を逃す候補。実行は需給・営業画面。</p>
  <div class="job-grid">
    <?php foreach (array_slice($discardJobs, 0, 5) as $d): ?>
      <div class="job-card compact risk">
        <div class="job-name"><?= htmlspecialchars($d['bed_name'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="job-meta text-danger fw-bold"><?= htmlspecialchars($d['reason'], ENT_QUOTES, 'UTF-8') ?></div>
        <a class="btn btn-outline-danger btn-sm w-100 mt-2" href="capacity.php">需給・営業で判断</a>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <h2 class="section-title"><?= gf_icon('empty') ?> 空きベッド</h2>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($emptyBeds as $b): ?>
      <a class="chip" href="data_entry/planting.php?bed_id=<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
    <?php if (!$emptyBeds): ?>
      <span class="text-muted small">空きなし</span>
    <?php endif; ?>
  </div>
</div>
<?php forecast_nav('today'); ?>
</body>
</html>
