<?php
// Cultivation Plan Visualization
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>栽培計画可視化</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="pb-5">
<div class="container my-4">
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" href="#">週別</a></li>
    <li class="nav-item"><a class="nav-link" href="#">月別</a></li>
  </ul>
  <div class="row text-center mb-4">
    <div class="col-4">
      <div class="border rounded p-2">
        <small>必要定植数</small>
        <div class="fs-5">12</div>
      </div>
    </div>
    <div class="col-4">
      <div class="border rounded p-2">
        <small>実績定植数</small>
        <div class="fs-5">8</div>
      </div>
    </div>
    <div class="col-4">
      <div class="border rounded p-2">
        <small>達成率</small>
        <div class="fs-5">67%</div>
      </div>
    </div>
  </div>
  <canvas id="plantChart" class="mb-4"></canvas>
  <canvas id="supplyChart" class="mb-4"></canvas>
  <div class="mb-3">
    <label for="scenario" class="form-label">シナリオ：定植数</label>
    <input type="range" class="form-range" min="0" max="20" value="8" id="scenario" oninput="updateScenario(this.value)">
    <div>選択値: <span id="scenarioVal">8</span> ベッド</div>
  </div>
</div>
<nav class="navbar fixed-bottom bg-light border-top">
  <div class="container-fluid">
    <div class="d-flex justify-content-around w-100">
      <a href="index.php" class="text-center nav-link"><div>🏠</div><small>ホーム</small></a>
      <a href="monitor.php" class="text-center nav-link"><div>🌱</div><small>栽培状況</small></a>
      <a href="inventory.php" class="text-center nav-link"><div>📊</div><small>在庫</small></a>
      <a href="plan.php" class="text-center nav-link active"><div>📅</div><small>計画</small></a>
      <a href="settings.php" class="text-center nav-link"><div>⚙️</div><small>設定</small></a>
    </div>
  </div>
</nav>
<script>
new Chart(document.getElementById('plantChart'), {
  type:'bar',
  data:{labels:['1週','2週','3週','4週'], datasets:[{label:'必要', data:[3,4,3,2], backgroundColor:'rgba(54,162,235,0.5)'},{label:'実績', data:[2,3,2,1], backgroundColor:'rgba(75,192,192,0.5)'}]}
});
new Chart(document.getElementById('supplyChart'), {
  type:'line',
  data:{labels:['1週','2週','3週','4週'], datasets:[{label:'予測収穫量充足率', data:[80,70,90,85], borderColor:'green'}]}
});
function updateScenario(val){
  document.getElementById('scenarioVal').innerText = val;
}
</script>
</body>
</html>
