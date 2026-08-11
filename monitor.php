<?php
/**
 * 栽培状況モニター（ベッドボード + カレンダー）— モバイル優先
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/overgrow_metrics.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/nav.php';

$group = $_GET['group'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$openByBed = [];
foreach (open_cycle_progress($link) as $op) {
    $openByBed[$op['bed_name']] = $op;
}

$beds = [];
$bedSql = "SELECT id, name, group_type FROM beds WHERE active = 1";
if ($group !== '') {
    $grp = mysqli_real_escape_string($link, $group);
    $bedSql .= " AND group_type='{$grp}'";
}
$bedSql .= ' ORDER BY group_type ASC, name ASC';
$bedRes = mysqli_query($link, $bedSql);
if ($bedRes) {
    while ($b = mysqli_fetch_assoc($bedRes)) {
        $cycleRes = mysqli_query(
            $link,
            "SELECT * FROM cycles WHERE bed_id=" . (int)$b['id'] . " ORDER BY plant_date DESC, id DESC LIMIT 1"
        );
        $cycle = $cycleRes ? mysqli_fetch_assoc($cycleRes) : null;
        if ($cycleRes) {
            mysqli_free_result($cycleRes);
        }
        $bedStatus = 'empty';
        if ($cycle) {
            if ($cycle['harvest_end'] !== null) {
                $bedStatus = 'empty';
            } elseif ($cycle['harvest_start'] !== null) {
                $bedStatus = 'harvesting';
            } elseif ($cycle['plant_date'] !== null) {
                $bedStatus = 'growing';
            }
        }
        if ($statusFilter !== '' && $statusFilter !== $bedStatus) {
            continue;
        }
        $b['cycle'] = $cycle;
        $b['status'] = $bedStatus;
        $b['progress'] = $openByBed[$b['name']] ?? null;
        $beds[] = $b;
    }
}

function week_status($cycle, $weekStart) {
    $weekEnd = strtotime('+6 day', $weekStart);
    if (!$cycle) {
        return ['', ''];
    }
    $plant = $cycle['plant_date'] ? strtotime($cycle['plant_date']) : null;
    $harvestStart = $cycle['harvest_start'] ? strtotime($cycle['harvest_start']) : null;
    $harvestEnd = $cycle['harvest_end'] ? strtotime($cycle['harvest_end']) : null;

    if ($plant && $plant >= $weekStart && $plant <= $weekEnd) {
        return ['bg-info text-white', '定植'];
    }
    if ($harvestStart && $harvestEnd && $harvestStart <= $weekEnd && $harvestEnd >= $weekStart) {
        return ['bg-warning', '収穫'];
    }
    if ($harvestStart && !$harvestEnd && $harvestStart <= $weekEnd && $weekStart <= time()) {
        return ['bg-warning', '収穫中'];
    }
    if ($plant && $weekStart >= $plant && (!$harvestStart || $weekEnd < $harvestStart)) {
        return ['bg-success text-white', '栽培'];
    }
    return ['', ''];
}

$statusLabel = [
    'empty' => '空き',
    'growing' => '栽培中',
    'harvesting' => '収穫中',
];

$weekStart = strtotime('monday this week');
$weekLabels = [];
for ($i = 0; $i < 7; $i++) {
    $wsYmd = date('Y-m-d', strtotime("+$i week", $weekStart));
    $weekLabels[] = format_month_week($wsYmd);
}

$openProgress = open_cycle_progress($link);
if ($group !== '') {
    $openProgress = array_values(array_filter(
        $openProgress,
        static fn($r) => $r['group_type'] === $group
    ));
}
$openRiskN = count(array_filter($openProgress, static fn($r) => $r['risk'] === 1));
$counts = ['empty' => 0, 'growing' => 0, 'harvesting' => 0];
foreach ($beds as $bb) {
    $counts[$bb['status']] = ($counts[$bb['status']] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>栽培状況モニター</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">栽培モニター</h1>
      <p class="page-sub">ベッド状態 · 予測 · 過栽培</p>
    </div>
    <div class="actions">
      <a href="overgrow.php" class="btn btn-sm btn-outline-warning">過栽培</a>
    </div>
  </div>

  <form method="get" class="row g-2 mb-3">
    <div class="col-6">
      <select name="group" class="form-select" onchange="this.form.submit()">
        <option value=""<?= $group === '' ? ' selected' : '' ?>>区分: 全体</option>
        <option value="通常"<?= $group === '通常' ? ' selected' : '' ?>>通常</option>
        <option value="別宅"<?= $group === '別宅' ? ' selected' : '' ?>>別宅</option>
      </select>
    </div>
    <div class="col-6">
      <select name="status" class="form-select" onchange="this.form.submit()">
        <option value=""<?= $statusFilter === '' ? ' selected' : '' ?>>状態: 全体</option>
        <option value="growing"<?= $statusFilter === 'growing' ? ' selected' : '' ?>>栽培中</option>
        <option value="harvesting"<?= $statusFilter === 'harvesting' ? ' selected' : '' ?>>収穫中</option>
        <option value="empty"<?= $statusFilter === 'empty' ? ' selected' : '' ?>>空き</option>
      </select>
    </div>
  </form>

  <div class="stat-row">
    <div class="stat-card ok">
      <?= gf_icon('plant', 'stat-ico') ?>
      <div class="stat-label">栽培中</div>
      <div class="stat-value"><?= (int)$counts['growing'] ?></div>
    </div>
    <div class="stat-card warn">
      <?= gf_icon('harvest', 'stat-ico') ?>
      <div class="stat-label">収穫中</div>
      <div class="stat-value"><?= (int)$counts['harvesting'] ?></div>
    </div>
    <div class="stat-card <?= $openRiskN ? 'danger' : '' ?>">
      <?= gf_icon('alert', 'stat-ico') ?>
      <div class="stat-label">過栽培</div>
      <div class="stat-value"><?= (int)$openRiskN ?></div>
    </div>
  </div>

  <div class="chart-card">
    <div class="chart-title">ベッド状態の内訳</div>
    <div class="chart-wrap doughnut"><canvas id="monStatus"></canvas></div>
  </div>

  <h2 class="section-title"><?= gf_icon('bed') ?> ベッドボード</h2>
  <div class="row g-2 mb-4">
    <?php foreach ($beds as $bed):
      $st = $bed['status'];
      $prog = $bed['progress'];
      $cycle = $bed['cycle'];
      $href = ($st !== 'empty' && $cycle)
        ? 'cycle.php?id=' . (int)$cycle['id']
        : 'data_entry/planting.php?bed_id=' . (int)$bed['id'];
      ?>
      <div class="col-6 col-md-4">
        <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="bed-tile <?= ($prog && $prog['risk']) ? 'risk' : '' ?>">
          <div class="d-flex justify-content-between">
            <span class="bed-name"><?= htmlspecialchars($bed['name'], ENT_QUOTES, 'UTF-8') ?></span>
            <span class="badge-status <?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>"><?= $statusLabel[$st] ?></span>
          </div>
          <div class="bed-line"><?= htmlspecialchars($bed['group_type'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php if ($st === 'empty'): ?>
            <div class="bed-line mt-2">定植可</div>
          <?php else: ?>
            <div class="bed-line">定植 <?= h_month_week($cycle['plant_date'] ?? null) ?></div>
            <div class="bed-line">予測 <?= h_month_week($prog['expected_harvest'] ?? null) ?></div>
            <div class="bed-kg">
              <?php
              $kg = $prog['postproc_yield'] ?? $prog['pred_yield'] ?? null;
              echo $kg !== null ? htmlspecialchars((string)round((float)$kg), ENT_QUOTES, 'UTF-8') . 'kg' : '—';
              ?>
            </div>
            <?php if ($prog): ?>
              <div class="bed-line <?= $prog['risk'] ? 'text-danger fw-bold' : '' ?>">
                <?= htmlspecialchars($prog['buffer_label'], ENT_QUOTES, 'UTF-8') ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </a>
      </div>
    <?php endforeach; ?>
    <?php if (!$beds): ?>
      <div class="col-12"><p class="text-muted small">条件に合うベッドがありません。</p></div>
    <?php endif; ?>
  </div>

  <h2 class="section-title"><?= gf_icon('calendar') ?> 7週カレンダー</h2>
  <div class="table-responsive mb-3" style="max-height:320px;">
    <table class="table table-bordered text-center small align-middle mb-0">
      <thead>
        <tr>
          <th>ベッド</th>
          <?php foreach ($weekLabels as $wl): ?>
            <th><?= htmlspecialchars($wl, ENT_QUOTES, 'UTF-8') ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($beds as $bed): ?>
          <tr>
            <th class="text-nowrap">
              <?php if ($bed['status'] !== 'empty' && $bed['cycle']): ?>
                <a href="cycle.php?id=<?= (int)$bed['cycle']['id'] ?>"><?= htmlspecialchars($bed['name'], ENT_QUOTES, 'UTF-8') ?></a>
              <?php else: ?>
                <?= htmlspecialchars($bed['name'], ENT_QUOTES, 'UTF-8') ?>
              <?php endif; ?>
            </th>
            <?php for ($i = 0; $i < 7; $i++): $ws = strtotime("+$i week", $weekStart); [$cls, $label] = week_status($bed['cycle'], $ws); ?>
              <td class="<?= $cls ?>"><?= $label ?></td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php forecast_nav('monitor'); ?>
<script>
new Chart(document.getElementById('monStatus'), {
  type: 'doughnut',
  data: {
    labels: ['栽培中', '収穫中', '空き'],
    datasets: [{
      data: [<?= (int)$counts['growing'] ?>, <?= (int)$counts['harvesting'] ?>, <?= (int)$counts['empty'] ?>],
      backgroundColor: ['#1b7a4a', '#c47a00', '#9aa59e'],
      borderWidth: 0
    }]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } },
    cutout: '58%'
  }
});
</script>
</body>
</html>
