<?php
// Home Dashboard
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ホームダッシュボード</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="pb-5">
<div class="container my-4">
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" href="#">週別</a></li>
    <li class="nav-item"><a class="nav-link" href="#">月別</a></li>
    <li class="nav-item"><a class="nav-link" href="#">年別</a></li>
  </ul>
  <h5 class="mb-3">2025-08-04週</h5>
  <div class="row text-center mb-4">
    <div class="col-4 mb-3">
      <div class="border rounded p-2">
        <small>収穫予定 vs 実績</small>
        <div class="fs-5">120kg / 110kg</div>
        <div class="text-success">+10kg</div>
      </div>
    </div>
    <div class="col-4 mb-3">
      <div class="border rounded p-2">
        <small>在庫差</small>
        <div class="fs-5">-8kg</div>
      </div>
    </div>
    <div class="col-4 mb-3">
      <div class="border rounded p-2">
        <small>廃棄リスク数</small>
        <div class="fs-5">2</div>
      </div>
    </div>
  </div>
  <canvas id="harvestChart" class="mb-4"></canvas>
  <canvas id="inventoryChart" class="mb-4"></canvas>
  <div class="card">
    <div class="card-header">アラート</div>
    <ul class="list-group list-group-flush">
      <li class="list-group-item">在庫不足：9/1週</li>
      <li class="list-group-item">廃棄リスク：ベッドA</li>
      <li class="list-group-item">定植計画未達：2ベッド</li>
    </ul>
  </div>
</div>
<nav class="navbar fixed-bottom bg-light border-top">
  <div class="container-fluid">
    <div class="d-flex justify-content-around w-100">
      <a href="index.php" class="text-center nav-link active"><div>🏠</div><small>ホーム</small></a>
      <a href="monitor.php" class="text-center nav-link"><div>🌱</div><small>栽培状況</small></a>
      <a href="inventory.php" class="text-center nav-link"><div>📊</div><small>在庫</small></a>
      <a href="plan.php" class="text-center nav-link"><div>📅</div><small>計画</small></a>
      <a href="settings.php" class="text-center nav-link"><div>⚙️</div><small>設定</small></a>
    </div>
  </div>
</nav>
<script>
const ctx1 = document.getElementById('harvestChart');
new Chart(ctx1, {
  type: 'bar',
  data: {
    labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
    datasets: [
      {label: '予定', data: [20,20,20,20,20,10,10], backgroundColor:'rgba(54,162,235,0.5)'},
      {label: '実績', data: [18,22,19,17,25,8,12], backgroundColor:'rgba(75,192,192,0.5)'}
    ]
  }
});
const ctx2 = document.getElementById('inventoryChart');
new Chart(ctx2, {
  type: 'line',
  data: {
    labels: ['先週','今週','来週'],
    datasets: [{label:'在庫差', data:[-5,10,-8], borderColor:'rgb(255,99,132)', tension:0.3}]
  }
});
</script>
</body>
</html>
