<?php
/**
 * 取込データ閲覧 — GCal出荷 / 栽培履歴（Excel・Web入力）
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/gcal_shipments.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/nav.php';

$today = date('Y-m-d');
$currentWeek = gcal_week_start_sunday($today);
$tab = $_GET['tab'] ?? 'ship';
if (!in_array($tab, ['ship', 'cycles', 'commit'], true)) {
    $tab = 'ship';
}

$week = trim((string)($_GET['week'] ?? $currentWeek));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)) {
    $week = $currentWeek;
}
$weekTs = strtotime($week . ' 12:00:00');
$weekLabel = $weekTs ? date('Y年n月j日週', $weekTs) : $week;
$prevWeek = date('Y-m-d', strtotime('-7 days', $weekTs));
$nextWeek = date('Y-m-d', strtotime('+7 days', $weekTs));

// sync state
$syncAt = null;
$syncStatus = null;
$syncMsg = null;
$res = mysqli_query($link, "SELECT last_synced_at, last_status, last_message FROM sync_state WHERE sync_key='gcal_shipments' LIMIT 1");
if ($res && ($row = mysqli_fetch_assoc($res))) {
    $syncAt = $row['last_synced_at'];
    $syncStatus = $row['last_status'];
    $syncMsg = $row['last_message'];
}
if ($res) {
    mysqli_free_result($res);
}

// —— 出荷イベント ——
$shipEvents = [];
$shipDoneKg = 0.0;
$shipRemainKg = 0.0;
$stmt = mysqli_prepare(
    $link,
    "SELECT ship_date, summary, amount_kg, source, gcal_event_id
     FROM calendar_shipment_events
     WHERE week_start_date = ?
     ORDER BY ship_date ASC, summary ASC"
);
mysqli_stmt_bind_param($stmt, 's', $week);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $done = $row['ship_date'] <= $today;
    $row['is_done'] = $done;
    $shipEvents[] = $row;
    if ($done) {
        $shipDoneKg += (float)$row['amount_kg'];
    } else {
        $shipRemainKg += (float)$row['amount_kg'];
    }
}
mysqli_stmt_close($stmt);

// —— 週次コミット ——
$commits = [];
$stmt = mysqli_prepare(
    $link,
    "SELECT week_start_date, committed_amount_kg, source, gcal_event_id
     FROM calendar_shipments
     WHERE week_start_date BETWEEN DATE_SUB(?, INTERVAL 14 DAY) AND DATE_ADD(?, INTERVAL 56 DAY)
     ORDER BY week_start_date ASC, FIELD(source,'manual','plan','gcal')"
);
mysqli_stmt_bind_param($stmt, 'ss', $week, $week);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($res)) {
    $commits[] = $row;
}
mysqli_stmt_close($stmt);

// —— 栽培サイクル ——
$cycleQ = trim((string)($_GET['q'] ?? ''));
$cycleStatus = $_GET['cstatus'] ?? 'open';
if (!in_array($cycleStatus, ['open', 'closed', 'all'], true)) {
    $cycleStatus = 'open';
}
$cycles = [];
$sql = "
SELECT c.id, b.name AS bed_name, b.group_type, c.sow_date, c.plant_date,
       c.harvest_start, c.harvest_end,
       (SELECT COUNT(*) FROM harvests h WHERE h.cycle_id=c.id) AS harvest_n,
       (SELECT COALESCE(SUM(h.harvest_kg),0) FROM harvests h WHERE h.cycle_id=c.id) AS harvest_kg,
       (SELECT GROUP_CONCAT(CONCAT(h.harvest_date, ' ', ROUND(h.harvest_kg,1), 'kg',
          IF(h.harvest_ratio IS NULL,'', CONCAT('×', h.harvest_ratio)))
          ORDER BY h.harvest_date SEPARATOR ' / ')
        FROM harvests h WHERE h.cycle_id=c.id) AS harvest_detail
FROM cycles c
JOIN beds b ON b.id = c.bed_id
WHERE 1=1
";
if ($cycleStatus === 'open') {
    $sql .= " AND c.harvest_end IS NULL";
} elseif ($cycleStatus === 'closed') {
    $sql .= " AND c.harvest_end IS NOT NULL";
}
if ($cycleQ !== '') {
    $esc = mysqli_real_escape_string($link, $cycleQ);
    $sql .= " AND b.name LIKE '%{$esc}%'";
}
$sql .= " ORDER BY c.plant_date DESC, b.name ASC LIMIT 80";
$res = mysqli_query($link, $sql);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $cycles[] = $row;
    }
    mysqli_free_result($res);
}

$counts = ['open' => 0, 'closed' => 0];
$res = mysqli_query($link, "SELECT SUM(harvest_end IS NULL) o, SUM(harvest_end IS NOT NULL) c FROM cycles");
if ($res && ($row = mysqli_fetch_assoc($res))) {
    $counts['open'] = (int)$row['o'];
    $counts['closed'] = (int)$row['c'];
}
if ($res) {
    mysqli_free_result($res);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>取込データ</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
  <style>
    .tab-row { display:flex; gap:0.4rem; margin-bottom:1rem; flex-wrap:wrap; }
    .tab-row a {
      flex:1; text-align:center; padding:0.55rem 0.4rem; border-radius:10px;
      border:1px solid var(--gf-line); background:var(--gf-card); color:var(--gf-ink);
      text-decoration:none; font-weight:700; font-size:0.85rem;
    }
    .tab-row a.active { background:var(--gf-green); color:#fff; border-color:var(--gf-green); }
    .week-nav { display:flex; gap:0.5rem; align-items:center; margin-bottom:0.85rem; }
    .week-nav .wk { flex:1; text-align:center; font-weight:800; }
    .ev-card {
      background:var(--gf-card); border:1px solid var(--gf-line); border-radius:var(--gf-radius);
      padding:0.75rem 0.85rem; margin-bottom:0.55rem; box-shadow:var(--gf-shadow);
    }
    .ev-card.done { opacity:0.72; border-left:4px solid #9aa5b1; }
    .ev-card.remain { border-left:4px solid var(--gf-amber); }
    .ev-card .ev-top { display:flex; justify-content:space-between; gap:0.5rem; align-items:flex-start; }
    .ev-card .ev-name { font-weight:800; font-size:0.95rem; }
    .ev-card .ev-meta { font-size:0.8rem; color:var(--gf-muted); margin-top:0.2rem; }
    .pill {
      display:inline-block; padding:0.1rem 0.45rem; border-radius:999px;
      font-size:0.68rem; font-weight:800;
    }
    .pill.done { background:#e8edf2; color:#5a6570; }
    .pill.remain { background:var(--gf-amber-soft); color:var(--gf-amber); }
    .filter-row { display:flex; gap:0.4rem; margin-bottom:0.75rem; flex-wrap:wrap; }
    .filter-row a, .filter-row span {
      padding:0.35rem 0.65rem; border-radius:999px; border:1px solid var(--gf-line);
      font-size:0.78rem; font-weight:700; text-decoration:none; color:var(--gf-ink); background:#fff;
    }
    .filter-row a.active { background:var(--gf-green); color:#fff; border-color:var(--gf-green); }
  </style>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">取込データ</h1>
      <p class="page-sub">
        GCal出荷 · 栽培履歴
        <?php if ($syncAt): ?>
          · 同期 <?= htmlspecialchars($syncAt, ENT_QUOTES, 'UTF-8') ?>
          (<?= htmlspecialchars((string)$syncStatus, ENT_QUOTES, 'UTF-8') ?>)
        <?php endif; ?>
      </p>
    </div>
  </div>

  <div class="tab-row">
    <a class="<?= $tab === 'ship' ? 'active' : '' ?>" href="?tab=ship&week=<?= urlencode($week) ?>">出荷明細</a>
    <a class="<?= $tab === 'commit' ? 'active' : '' ?>" href="?tab=commit&week=<?= urlencode($week) ?>">週次コミット</a>
    <a class="<?= $tab === 'cycles' ? 'active' : '' ?>" href="?tab=cycles&cstatus=<?= urlencode($cycleStatus) ?>&q=<?= urlencode($cycleQ) ?>">栽培履歴</a>
  </div>

  <?php if ($tab === 'ship'): ?>
    <div class="week-nav">
      <a class="btn btn-outline-secondary btn-sm" href="?tab=ship&week=<?= urlencode($prevWeek) ?>">‹ 前週</a>
      <div class="wk"><?= htmlspecialchars($weekLabel, ENT_QUOTES, 'UTF-8') ?><?php if ($week === $currentWeek): ?> <span class="badge-this-week">当週</span><?php endif; ?></div>
      <a class="btn btn-outline-secondary btn-sm" href="?tab=ship&week=<?= urlencode($nextWeek) ?>">次週 ›</a>
    </div>

    <div class="stat-row cols-2">
      <div class="stat-card">
        <div class="stat-label">当日まで（済）</div>
        <div class="stat-value" style="font-size:1.25rem"><?= number_format($shipDoneKg, 0) ?></div>
        <div class="stat-sub">残出荷から除外</div>
      </div>
      <div class="stat-card warn">
        <div class="stat-label">明日以降（残）</div>
        <div class="stat-value" style="font-size:1.25rem"><?= number_format($shipRemainKg, 0) ?></div>
        <div class="stat-sub">予測の残出荷に使用</div>
      </div>
    </div>

    <?php if (!$shipEvents): ?>
      <div class="job-card"><div class="job-meta">この週の日次出荷イベントはありません</div></div>
    <?php else: ?>
      <?php foreach ($shipEvents as $ev): ?>
        <div class="ev-card <?= $ev['is_done'] ? 'done' : 'remain' ?>">
          <div class="ev-top">
            <div>
              <div class="ev-name"><?= htmlspecialchars($ev['summary'] ?: '(無題)', ENT_QUOTES, 'UTF-8') ?></div>
              <div class="ev-meta">
                <?= htmlspecialchars($ev['ship_date'], ENT_QUOTES, 'UTF-8') ?>
                · <?= htmlspecialchars((string)$ev['source'], ENT_QUOTES, 'UTF-8') ?>
              </div>
            </div>
            <div class="text-end">
              <div class="fw-bold"><?= number_format((float)$ev['amount_kg'], 1) ?>kg</div>
              <span class="pill <?= $ev['is_done'] ? 'done' : 'remain' ?>">
                <?= $ev['is_done'] ? '済' : '残' ?>
              </span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <p class="page-sub mt-2">出荷当日は収穫完了扱い（残に含めない）</p>
    <a class="btn btn-outline-primary w-100 mb-3" href="inventory.php?force_sync=1">カレンダー再取込して予測へ</a>

  <?php elseif ($tab === 'commit'): ?>
    <div class="week-nav">
      <a class="btn btn-outline-secondary btn-sm" href="?tab=commit&week=<?= urlencode($prevWeek) ?>">‹ 前週</a>
      <div class="wk"><?= htmlspecialchars($weekLabel, ENT_QUOTES, 'UTF-8') ?></div>
      <a class="btn btn-outline-secondary btn-sm" href="?tab=commit&week=<?= urlencode($nextWeek) ?>">次週 ›</a>
    </div>
    <p class="page-sub mb-2">週次コミット（gcal / plan / manual）。予測の残は日次イベント優先。</p>
    <?php if (!$commits): ?>
      <div class="job-card"><div class="job-meta">コミットがありません</div></div>
    <?php else: ?>
      <?php foreach ($commits as $c):
        $isCur = $c['week_start_date'] === $week;
        ?>
        <div class="ev-card <?= $isCur ? 'remain' : '' ?>">
          <div class="ev-top">
            <div>
              <div class="ev-name"><?= htmlspecialchars(date('Y年n月j日週', strtotime($c['week_start_date'])), ENT_QUOTES, 'UTF-8') ?></div>
              <div class="ev-meta">source=<?= htmlspecialchars($c['source'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>
            <div class="fw-bold"><?= number_format((float)$c['committed_amount_kg'], 1) ?>kg</div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  <?php else: ?>
    <div class="filter-row">
      <a class="<?= $cycleStatus === 'open' ? 'active' : '' ?>" href="?tab=cycles&cstatus=open&q=<?= urlencode($cycleQ) ?>">未完了 <?= $counts['open'] ?></a>
      <a class="<?= $cycleStatus === 'closed' ? 'active' : '' ?>" href="?tab=cycles&cstatus=closed&q=<?= urlencode($cycleQ) ?>">完了 <?= $counts['closed'] ?></a>
      <a class="<?= $cycleStatus === 'all' ? 'active' : '' ?>" href="?tab=cycles&cstatus=all&q=<?= urlencode($cycleQ) ?>">すべて</a>
    </div>
    <form method="get" class="mb-3">
      <input type="hidden" name="tab" value="cycles">
      <input type="hidden" name="cstatus" value="<?= htmlspecialchars($cycleStatus, ENT_QUOTES, 'UTF-8') ?>">
      <div class="input-group">
        <input type="search" name="q" class="form-control" placeholder="ベッド名（例: N-4-3）" value="<?= htmlspecialchars($cycleQ, ENT_QUOTES, 'UTF-8') ?>">
        <button class="btn btn-primary" type="submit">検索</button>
      </div>
    </form>
    <?php if (!$cycles): ?>
      <div class="job-card"><div class="job-meta">該当なし</div></div>
    <?php else: ?>
      <?php foreach ($cycles as $c):
        $open = $c['harvest_end'] === null;
        ?>
        <a class="ev-card <?= $open ? 'remain' : 'done' ?> d-block text-decoration-none text-dark" href="cycle.php?id=<?= (int)$c['id'] ?>">
          <div class="ev-top">
            <div>
              <div class="ev-name"><?= htmlspecialchars($c['bed_name'], ENT_QUOTES, 'UTF-8') ?></div>
              <div class="ev-meta">
                定植 <?= htmlspecialchars((string)$c['plant_date'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ($c['sow_date']): ?> · 播種 <?= htmlspecialchars($c['sow_date'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
              </div>
              <?php if ($c['harvest_detail']): ?>
                <div class="ev-meta"><?= htmlspecialchars($c['harvest_detail'], ENT_QUOTES, 'UTF-8') ?></div>
              <?php endif; ?>
            </div>
            <div class="text-end">
              <span class="pill <?= $open ? 'remain' : 'done' ?>"><?= $open ? '未完了' : '完了' ?></span>
              <div class="ev-meta mt-1"><?= number_format((float)$c['harvest_kg'], 0) ?>kg</div>
            </div>
          </div>
        </a>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php forecast_nav('settings'); ?>
</body>
</html>
