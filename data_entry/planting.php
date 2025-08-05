<?php
require_once '../db.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>定植入力</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/mobile-ui.css">
</head>
<body class="pb-5">
<div class="container py-4">
  <h4 class="mb-4 text-primary">🌱 定植入力</h4>
  <form>
    <div class="mb-4">
      <label for="plant_date" class="form-label fs-5">定植日</label>
      <input type="date" id="plant_date" class="form-control form-control-lg" required>
    </div>
    <div class="mb-4">
      <label for="bed" class="form-label fs-5">ベッド名</label>
      <select id="bed" class="form-select form-select-lg" required>
        <option value="">選択してください</option>
        <?php
        $res = mysqli_query($link, "SELECT id, name FROM beds WHERE active=1 ORDER BY name");
        while ($b = mysqli_fetch_assoc($res)) {
            echo "<option value='{$b['id']}'>{$b['name']}</option>";
        }
        ?>
      </select>
    </div>
    <div class="mb-4">
      <label for="sow_date" class="form-label fs-5">播種日</label>
      <input type="date" id="sow_date" class="form-control form-control-lg" required>
      <div class="form-text">育苗日数: <span id="nursery_days">0</span>日</div>
    </div>
    <div class="d-grid">
      <button type="submit" class="btn btn-primary btn-lg">登録</button>
    </div>
  </form>
</div>
<nav class="navbar fixed-bottom bg-light border-top">
  <div class="container-fluid">
    <div class="d-flex justify-content-around w-100">
      <a href="../index.php" class="text-center nav-link"><div>🏠</div><small>ホーム</small></a>
      <a href="../monitor.php" class="text-center nav-link"><div>🌱</div><small>栽培状況</small></a>
      <a href="../inventory.php" class="text-center nav-link"><div>📊</div><small>在庫</small></a>
      <a href="../plan.php" class="text-center nav-link"><div>📅</div><small>計画</small></a>
      <a href="../settings.php" class="text-center nav-link"><div>⚙️</div><small>設定</small></a>
    </div>
  </div>
</nav>
<script>
function calcDays(){
  const plant = new Date(document.getElementById('plant_date').value);
  const sow = new Date(document.getElementById('sow_date').value);
  if(!isNaN(plant) && !isNaN(sow)){
    const diff = (plant - sow)/(1000*60*60*24);
    document.getElementById('nursery_days').innerText = diff;
  }
}
document.getElementById('plant_date').addEventListener('change', calcDays);
document.getElementById('sow_date').addEventListener('change', calcDays);
</script>
</body>
</html>
