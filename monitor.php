<?php
require_once 'db.php';

$group = $_GET['group'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$today = date('Y-m-d');

$beds = [];
$bedSql = "SELECT id, name, group_type FROM beds";
if ($group && $group !== '') {
    $grp = mysqli_real_escape_string($link, $group);
    $bedSql .= " WHERE group_type='{$grp}'";
}
$bedSql .= ' ORDER BY name';
$bedRes = mysqli_query($link, $bedSql);
if ($bedRes) {
    while ($b = mysqli_fetch_assoc($bedRes)) {
        $cycleRes = mysqli_query($link, "SELECT * FROM cycles WHERE bed_id={$b['id']} ORDER BY id DESC LIMIT 1");
        $cycle = $cycleRes ? mysqli_fetch_assoc($cycleRes) : null;
        $bedStatus = 'empty';
        if ($cycle) {
            if (!empty($cycle['harvest_start']) && !empty($cycle['harvest_end']) && $today >= $cycle['harvest_start'] && $today <= $cycle['harvest_end']) {
                $bedStatus = 'harvesting';
            } elseif (!empty($cycle['plant_date']) && $today >= $cycle['plant_date'] && ($cycle['harvest_start'] === null || $today < $cycle['harvest_start'])) {
                $bedStatus = 'growing';
            }
        }
        if ($statusFilter && $statusFilter !== $bedStatus) {
            continue;
        }
        $b['cycle'] = $cycle;
        $beds[] = $b;
    }
}

function week_status($cycle, $weekStart) {
    $weekEnd = strtotime('+6 day', $weekStart);
    if (!$cycle) return ['', ''];
    $plant = $cycle['plant_date'] ? strtotime($cycle['plant_date']) : null;
    $harvestStart = $cycle['harvest_start'] ? strtotime($cycle['harvest_start']) : null;
    $harvestEnd = $cycle['harvest_end'] ? strtotime($cycle['harvest_end']) : null;

    if ($plant && $plant >= $weekStart && $plant <= $weekEnd) {
        return ['bg-info text-white', '定植'];
    }
    if ($harvestStart && $harvestEnd && $harvestStart <= $weekEnd && $harvestEnd >= $weekStart) {
        return ['bg-warning', '収穫'];
    }
    if ($plant && $weekStart >= $plant && (!$harvestStart || $weekEnd < $harvestStart)) {
        return ['bg-success text-white', '栽培'];
    }
    return ['', ''];
}

$weekStart = strtotime('monday this week');
$weekLabels = [];
for ($i = 0; $i < 7; $i++) {
    $weekLabels[] = date('n/j', strtotime("+$i week", $weekStart));
}

$harvestLabels = [];
$harvestActual = [];
$res = mysqli_query($link, "SELECT DATE_FORMAT(harvest_date,'%Y-%u') AS wk, SUM(harvest_kg) AS total FROM harvests GROUP BY wk ORDER BY wk DESC LIMIT 7");
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $harvestLabels[] = $row['wk'];
        $harvestActual[] = (float)$row['total'];
    }
}
$harvestLabels = array_reverse($harvestLabels);
$harvestActual = array_reverse($harvestActual);
$forecastData = array_map(fn($v) => round($v * 1.1, 1), $harvestActual);
if (!$harvestLabels) {
    $harvestLabels = ['1週', '2週', '3週', '4週'];
    $harvestActual = [0, 0, 0, 0];
    $forecastData = [0, 0, 0, 0];
}

$dayLabels = [];
$dayData = [];
$dRes = mysqli_query($link, "SELECT DATE_FORMAT(harvest_start,'%Y-%u') AS wk, AVG(DATEDIFF(harvest_start, plant_date)) AS avg_days FROM cycles WHERE harvest_start IS NOT NULL GROUP BY wk ORDER BY wk DESC LIMIT 7");
if ($dRes) {
    while ($row = mysqli_fetch_assoc($dRes)) {
        $dayLabels[] = $row['wk'];
        $dayData[] = (float)$row['avg_days'];
    }
}
$dayLabels = array_reverse($dayLabels);
$dayData = array_reverse($dayData);
if (!$dayLabels) {
    $dayLabels = ['1週', '2週', '3週', '4週'];
    $dayData = [30, 28, 27, 29];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>栽培状況モニター</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="pb-5">
<div class="container my-4">
  <h4 class="mb-3 text-primary">🌱 栽培状況モニター</h4>
  <form method="get" class="row mb-3">
    <div class="col-6">
      <label class="form-label">ベッド区分</label>
      <select name="group" class="form-select" onchange="this.form.submit()">
        <option value=""<?= $group === '' ? ' selected' : '' ?>>全体</option>
        <option value="通常"<?= $group === '通常' ? ' selected' : '' ?>>通常</option>
        <option value="別宅"<?= $group === '別宅' ? ' selected' : '' ?>>別宅</option>
      </select>
    </div>
    <div class="col-6">
      <label class="form-label">状態</label>
      <select name="status" class="form-select" onchange="this.form.submit()">
        <option value=""<?= $statusFilter === '' ? ' selected' : '' ?>>全体</option>
        <option value="growing"<?= $statusFilter === 'growing' ? ' selected' : '' ?>>栽培中</option>
        <option value="harvesting"<?= $statusFilter === 'harvesting' ? ' selected' : '' ?>>収穫中</option>
        <option value="empty"<?= $statusFilter === 'empty' ? ' selected' : '' ?>>空き</option>
      </select>
    </div>
  </form>
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" href="#">週別</a></li>
    <li class="nav-item"><a class="nav-link" href="#">月別</a></li>
    <li class="nav-item"><a class="nav-link" href="#">年別</a></li>
  </ul>
  <div class="mb-4">
    <h6>栽培カレンダー</h6>
    <div class="table-responsive" style="max-height:400px;">
      <table class="table table-bordered text-center small align-middle">
        <thead class="table-light">
          <tr>
            <th class="text-nowrap">ベッド\\週</th>
            <?php foreach ($weekLabels as $wl): ?>
              <th><?= htmlspecialchars($wl, ENT_QUOTES, 'UTF-8') ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($beds as $bed): ?>
            <tr>
              <th class="text-nowrap"><?= htmlspecialchars($bed['name'], ENT_QUOTES, 'UTF-8') ?></th>
              <?php for ($i = 0; $i < 7; $i++): $ws = strtotime("+$i week", $weekStart); [$cls, $label] = week_status($bed['cycle'], $ws); ?>
                <td class="<?= $cls ?>"><?= $label ?></td>
              <?php endfor; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <canvas id="harvestTrend" class="mb-4"></canvas>
  <canvas id="dayTrend" class="mb-4"></canvas>
  <div class="card mb-3">
    <div class="card-header">詳細</div>
    <div class="card-body">
      <p>温度推移や予測収量を表示します。</p>
    </div>
  </div>
  <div class="card">
    <div class="card-header">防除・追肥</div>
    <ul class="list-group list-group-flush">
      <li class="list-group-item">8/1 防除：薬剤A</li>
      <li class="list-group-item">8/3 追肥：液肥B</li>
    </ul>
  </div>
</div>
<nav class="navbar fixed-bottom bg-light border-top">
  <div class="container-fluid">
    <div class="d-flex justify-content-around w-100">
      <a href="index.php" class="text-center nav-link"><div>🏠</div><small>ホーム</small></a>
      <a href="monitor.php" class="text-center nav-link active"><div>🌱</div><small>栽培状況</small></a>
      <a href="inventory.php" class="text-center nav-link"><div>📊</div><small>在庫</small></a>
      <a href="plan.php" class="text-center nav-link"><div>📅</div><small>計画</small></a>
      <a href="settings.php" class="text-center nav-link"><div>⚙️</div><small>設定</small></a>
    </div>
  </div>
</nav>
<script>
const harvestLabels = <?= json_encode($harvestLabels, JSON_UNESCAPED_UNICODE) ?>;
const actualData = <?= json_encode($harvestActual, JSON_NUMERIC_CHECK) ?>;
const forecastData = <?= json_encode($forecastData, JSON_NUMERIC_CHECK) ?>;
new Chart(document.getElementById('harvestTrend'), {
  type: 'bar',
  data: {
    labels: harvestLabels,
    datasets: [
      {label: '実績', data: actualData, backgroundColor: 'rgba(75,192,192,0.5)'},
      {label: '予測', data: forecastData, backgroundColor: 'rgba(54,162,235,0.5)'}
    ]
  }
});
const dayLabels = <?= json_encode($dayLabels, JSON_UNESCAPED_UNICODE) ?>;
const dayData = <?= json_encode($dayData, JSON_NUMERIC_CHECK) ?>;
new Chart(document.getElementById('dayTrend'), {
  type: 'line',
  data: {
    labels: dayLabels,
    datasets: [{label: '平均生育日数', data: dayData, borderColor: 'orange'}]
  }
});
</script>
</body>
</html>
