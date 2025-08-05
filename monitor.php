<?php
// Cultivation Status Monitor
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
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item"><a class="nav-link active" href="#">週別</a></li>
    <li class="nav-item"><a class="nav-link" href="#">月別</a></li>
    <li class="nav-item"><a class="nav-link" href="#">年別</a></li>
  </ul>
  <div class="row mb-3">
    <div class="col-6">
      <label class="form-label">グループ</label>
      <select class="form-select">
        <option>通常</option>
        <option>別宅</option>
      </select>
    </div>
    <div class="col-6">
      <label class="form-label">状態</label>
      <select class="form-select">
        <option>全体</option>
        <option>定植中</option>
        <option>生育中</option>
        <option>収穫中</option>
      </select>
    </div>
  </div>
  <div class="mb-4">
    <h6>栽培カレンダー</h6>
    <table class="table table-bordered text-center small">
      <thead><tr><th>ベッド\日</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th><th>Sun</th></tr></thead>
      <tbody>
        <tr><th>A1</th><td class="bg-info">定植</td><td class="bg-success">生育</td><td class="bg-success">生育</td><td class="bg-warning">収穫</td><td></td><td></td><td></td></tr>
        <tr><th>B2</th><td class="bg-info">定植</td><td class="bg-success">生育</td><td class="bg-success">生育</td><td class="bg-success">生育</td><td class="bg-warning">収穫</td><td></td><td></td></tr>
      </tbody>
    </table>
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
new Chart(document.getElementById('harvestTrend'), {
  type: 'bar',
  data: {
    labels:['1週','2週','3週','4週'],
    datasets:[
      {label:'実績', data:[30,40,35,50], backgroundColor:'rgba(75,192,192,0.5)'},
      {label:'予測', data:[32,42,38,48], backgroundColor:'rgba(54,162,235,0.5)'}
    ]
  }
});
new Chart(document.getElementById('dayTrend'), {
  type: 'line',
  data: {
    labels:['1週','2週','3週','4週'],
    datasets:[{label:'平均生育日数', data:[30,28,27,29], borderColor:'orange'}]
  }
});
</script>
</body>
</html>
