<?php
// Forecast & Inventory Visualization
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>予測＆在庫可視化</title>
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
  <div class="mb-3">
    <h6>差分サマリー</h6>
    <div class="border rounded p-3">
      在庫差合計：<span class="text-danger">-20kg</span><br>
      赤字週数：3
    </div>
  </div>
  <canvas id="diffChart" class="mb-4"></canvas>
  <canvas id="accChart" class="mb-4"></canvas>
  <h6>在庫不足リスト</h6>
  <ul class="list-group">
    <li class="list-group-item">ベッドA 不足5kg → 9/1収穫で補填</li>
    <li class="list-group-item">ベッドB 不足3kg → 9/8収穫で補填</li>
  </ul>
</div>
<nav class="navbar fixed-bottom bg-light border-top">
  <div class="container-fluid">
    <div class="d-flex justify-content-around w-100">
      <a href="index.php" class="text-center nav-link"><div>🏠</div><small>ホーム</small></a>
      <a href="monitor.php" class="text-center nav-link"><div>🌱</div><small>栽培状況</small></a>
      <a href="inventory.php" class="text-center nav-link active"><div>📊</div><small>在庫</small></a>
      <a href="plan.php" class="text-center nav-link"><div>📅</div><small>計画</small></a>
      <a href="settings.php" class="text-center nav-link"><div>⚙️</div><small>設定</small></a>
    </div>
  </div>
</nav>
<script>
new Chart(document.getElementById('diffChart'), {
  type:'bar',
  data:{labels:['1週','2週','3週','4週'], datasets:[{label:'在庫差', data:[-5,-8,3,-10], backgroundColor:'rgba(255,99,132,0.5)'}]}
});
new Chart(document.getElementById('accChart'), {
  type:'line',
  data:{labels:['1週','2週','3週','4週'], datasets:[{label:'累積在庫差', data:[-5,-13,-10,-20], borderColor:'rgb(54,162,235)'}]}
});
</script>
</body>
</html>
