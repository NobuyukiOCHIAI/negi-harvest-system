<?php
/**
 * 今日の作業（スタッフ指示・Web正本）— モバイル優先
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/overgrow_metrics.php';
require_once __DIR__ . '/lib/capacity_outlook.php';
require_once __DIR__ . '/lib/supply_ops.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/weather_ops.php';
require_once __DIR__ . '/lib/cycle_photos.php';

$ensure = supply_ensure_full_rotation($link);
$today = date('Y-m-d');

$plantJobs = [];
$chk = mysqli_query($link, "SHOW TABLES LIKE 'plant_schedule'");
$hasSchedule = $chk && mysqli_num_rows($chk) > 0;
if ($chk) {
    mysqli_free_result($chk);
}
if ($hasSchedule) {
    $sql = "
SELECT s.*, b.name AS bed_name, b.group_type,
       (SELECT MAX(c.harvest_end) FROM cycles c WHERE c.bed_id = s.bed_id) AS last_end
FROM plant_schedule s
JOIN beds b ON b.id = s.bed_id AND b.active = 1
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
        $bedId = (int)$row['bed_id'];
        if (!isset($plantJobs[$bedId])) {
            $lastEnd = $row['last_end'] ?? null;
            $emptyDays = null;
            if ($lastEnd) {
                $emptyDays = (int)floor((strtotime($today) - strtotime($lastEnd)) / 86400);
                if ($emptyDays < 0) {
                    $emptyDays = 0;
                }
            }
            $row['empty_days'] = $emptyDays;
            $row['late_empty'] = ($emptyDays !== null && $emptyDays > GF_REPLANT_GRACE_DAYS);
            $plantJobs[$bedId] = $row;
        }
    }
    mysqli_stmt_close($stmt);
    $plantJobs = array_values($plantJobs);
    usort($plantJobs, static function ($a, $b) {
        $ae = $a['empty_days'];
        $be = $b['empty_days'];
        if ($ae === null && $be === null) {
            return strcmp((string)$a['bed_name'], (string)$b['bed_name']);
        }
        if ($ae === null) {
            return 1;
        }
        if ($be === null) {
            return -1;
        }
        if ($ae !== $be) {
            return $be <=> $ae;
        }
        return strcmp((string)$a['bed_name'], (string)$b['bed_name']);
    });
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
$plantBedIds = [];
foreach ($plantJobs as $j) {
    $plantBedIds[(int)$j['bed_id']] = true;
}
$eres = mysqli_query(
    $link,
    "SELECT b.id, b.name, b.group_type FROM beds b
     WHERE b.active=1
       AND NOT EXISTS (SELECT 1 FROM cycles c WHERE c.bed_id=b.id AND c.harvest_end IS NULL)
     ORDER BY b.name LIMIT 20"
);
if ($eres) {
    while ($row = mysqli_fetch_assoc($eres)) {
        if (!isset($plantBedIds[(int)$row['id']])) {
            $emptyBeds[] = $row;
        }
    }
    mysqli_free_result($eres);
}

$nPlant = count($plantJobs);
$nHarvest = count($harvestJobs);
$nLate = 0;
foreach ($plantJobs as $j) {
    if (!empty($j['late_empty'])) {
        $nLate++;
    }
}
$harvestCycleIds = [];
foreach ($harvestJobs as $h) {
    $harvestCycleIds[(int)$h['cycle_id']] = true;
}
$discardJobs = array_values(array_filter(
    capacity_discard_candidates($link),
    static fn($d) => !isset($harvestCycleIds[(int)$d['cycle_id']])
));
$nDiscard = count($discardJobs);
$discardPhotoCounts = cycle_photo_counts(
    $link,
    array_map(static fn($d) => (int)$d['cycle_id'], $discardJobs)
);
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
  <link rel="stylesheet" href="css/mobile-ui.css?v=20260815a">
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">今日の作業</h1>
      <p class="page-sub"><?= htmlspecialchars(date('n月j日', strtotime($today)) . "（{$wd}）", ENT_QUOTES, 'UTF-8') ?> · 今日やる定植・収穫・破棄。空きは<?= (int)GF_REPLANT_GRACE_DAYS ?>日以内に次定植</p>
    </div>
  </div>

  <?= gf_weather_stale_banner_html($link) ?>
  <?php if (!empty($ensure['created'])): ?>
    <div class="alert alert-success py-2">常時フル稼働: 空きベッドへ定植計画を <?= (int)$ensure['created'] ?> 件自動追加しました</div>
  <?php endif; ?>

  <div class="stat-row">
    <a href="#sec-plant" class="stat-card stat-link <?= $nLate ? 'danger' : ($nPlant > 0 ? 'ok' : '') ?>">
      <?= gf_icon('plant', 'stat-ico') ?>
      <div class="stat-label"><?= $nLate ? '定植遅れ' : '定植' ?></div>
      <div class="stat-value"><?= $nLate ?: $nPlant ?></div>
    </a>
    <a href="#sec-harvest" class="stat-card stat-link <?= $nHarvest > 0 ? 'warn' : '' ?>">
      <?= gf_icon('harvest', 'stat-ico') ?>
      <div class="stat-label">収穫候補</div>
      <div class="stat-value"><?= $nHarvest ?></div>
    </a>
    <a href="#sec-discard" class="stat-card stat-link <?= $nDiscard > 0 ? 'danger' : '' ?>">
      <?= gf_icon('alert', 'stat-ico') ?>
      <div class="stat-label">破棄候補</div>
      <div class="stat-value"><?= $nDiscard ?></div>
    </a>
  </div>

  <h2 id="sec-plant" class="section-title"><?= gf_icon('plant') ?> 今日の定植</h2>
  <p class="page-sub mb-2">基準は空き日数。<?= (int)GF_REPLANT_GRACE_DAYS ?>日超は遅れ。</p>
  <?php if (!$hasSchedule): ?>
    <p class="text-muted small">定植計画未設定。<a href="plan.php">計画画面</a>へ</p>
  <?php elseif (!$plantJobs): ?>
    <div class="job-card"><div class="job-meta">本日までの未実施定植はありません</div></div>
  <?php else: ?>
    <div class="job-grid">
      <?php foreach ($plantJobs as $j):
        $emptyDays = $j['empty_days'];
        $late = !empty($j['late_empty']);
        $graceLeft = ($emptyDays !== null)
          ? max(0, GF_REPLANT_GRACE_DAYS - (int)$emptyDays)
          : null;
        ?>
        <div class="job-card compact <?= $late ? 'risk' : '' ?>">
          <div class="job-name"><?= htmlspecialchars($j['bed_name'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="job-meta">
            <?= htmlspecialchars($j['group_type'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($emptyDays !== null): ?>
              · 空き<?= (int)$emptyDays ?>日
              <?php if ($late): ?>
                <span class="badge-status late">猶予<?= (int)GF_REPLANT_GRACE_DAYS ?>日超過</span>
              <?php else: ?>
                · あと<?= (int)$graceLeft ?>日以内
              <?php endif; ?>
            <?php else: ?>
              · 空き日不明
            <?php endif; ?>
          </div>
          <a class="btn btn-success btn-sm w-100 mt-2"
             href="data_entry/planting.php?bed_id=<?= (int)$j['bed_id'] ?>&schedule_id=<?= (int)$j['id'] ?>">定植入力</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 id="sec-harvest" class="section-title"><?= gf_icon('harvest') ?> 今日の収穫候補</h2>
  <p class="page-sub mb-2">予測日到達または収穫中。入力して実績を残す。</p>
  <?php if (!$harvestJobs): ?>
    <div class="job-card"><div class="job-meta">予測日到達・収穫中のベッドはありません</div></div>
  <?php else: ?>
    <div class="job-grid">
      <?php foreach ($harvestJobs as $h): ?>
        <div class="job-card compact <?= $h['risk'] ? 'risk' : '' ?>">
          <div class="job-name"><?= htmlspecialchars($h['bed_name'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="job-meta">
            <?= $h['harvest_start'] ? '収穫中' : '初回候補' ?>
            · <?= h_sunday_week($h['expected_harvest'] ?? null) ?>
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
  <h2 id="sec-discard" class="section-title"><?= gf_icon('alert') ?> 破棄検討</h2>
  <p class="page-sub mb-2">床を空けて次定植へ。異常一覧は <a href="alerts.php">経営アラート</a>。</p>
  <p class="page-sub mb-2">判断・実行は需給へ。</p>
  <div class="job-grid">
    <?php foreach (array_slice($discardJobs, 0, 5) as $d): ?>
      <div class="job-card compact risk">
        <div class="job-name"><?= htmlspecialchars($d['bed_name'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="job-meta text-danger fw-bold"><?= htmlspecialchars($d['reason'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php $photoN = $discardPhotoCounts[(int)$d['cycle_id']] ?? 0; ?>
        <a class="btn btn-outline-primary btn-sm w-100 mt-2" href="cycle_photos.php?cycle_id=<?= (int)$d['cycle_id'] ?>">
          写真<?= $photoN > 0 ? ' (' . (int)$photoN . ')' : 'を追加' ?>
        </a>
        <a class="btn btn-outline-danger btn-sm w-100 mt-2" href="capacity.php">需給で判断</a>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($emptyBeds): ?>
  <h2 class="section-title"><?= gf_icon('empty') ?> その他の空き</h2>
  <p class="page-sub mb-2">上の定植リストに無いベッド。</p>
  <div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach ($emptyBeds as $b): ?>
      <a class="chip" href="data_entry/planting.php?bed_id=<?= (int)$b['id'] ?>"><?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <p class="page-sub mb-0">ベッド一覧は <a href="monitor.php">栽培モニター</a>。</p>
</div>
<?php forecast_nav('today'); ?>
</body>
</html>
