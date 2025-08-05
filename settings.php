<?php
// Settings and Management
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>設定・運用管理</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
</head>
<body class="pb-5">
<div class="container my-4">
  <h5 class="mb-3">設定・運用管理</h5>
  <div class="list-group">
    <a href="#" class="list-group-item list-group-item-action">ベッド情報管理</a>
    <a href="#" class="list-group-item list-group-item-action">従業員情報管理</a>
    <a href="#" class="list-group-item list-group-item-action">廃棄区分管理</a>
    <a href="#" class="list-group-item list-group-item-action">通知設定</a>
    <a href="#" class="list-group-item list-group-item-action">モデルアップロード</a>
    <a href="#" class="list-group-item list-group-item-action">温度データ取込</a>
    <a href="data_entry/planting.php" class="list-group-item list-group-item-action">定植入力フォーム</a>
    <a href="data_entry/harvest.php" class="list-group-item list-group-item-action">収穫入力フォーム</a>
    <a href="data_entry/treatment.php" class="list-group-item list-group-item-action">防除・追肥入力フォーム</a>
  </div>
</div>
<nav class="navbar fixed-bottom bg-light border-top">
  <div class="container-fluid">
    <div class="d-flex justify-content-around w-100">
      <a href="index.php" class="text-center nav-link"><div>🏠</div><small>ホーム</small></a>
      <a href="monitor.php" class="text-center nav-link"><div>🌱</div><small>栽培状況</small></a>
      <a href="inventory.php" class="text-center nav-link"><div>📊</div><small>在庫</small></a>
      <a href="plan.php" class="text-center nav-link"><div>📅</div><small>計画</small></a>
      <a href="settings.php" class="text-center nav-link active"><div>⚙️</div><small>設定</small></a>
    </div>
  </div>
</nav>
</body>
</html>
