<?php
require_once '../db.php';
require_once __DIR__ . '/../lib/nav.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>防除・追肥入力</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/mobile-ui.css">
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">防除・追肥入力</h1>
      <p class="page-sub">作業結果の記録（任意）</p>
    </div>
    <a class="btn btn-sm btn-outline-success" href="../today.php">今日の作業へ</a>
  </div>
  <form>
    <div class="mb-4">
      <label for="treat_date" class="form-label fs-5">実施日</label>
      <input type="date" id="treat_date" class="form-control form-control-lg" required>
    </div>
    <div class="mb-4">
      <label for="bed" class="form-label fs-5">ベッド名</label>
      <select id="bed" class="form-select form-select-lg" required>
        <option value="">選択してください</option>
        <?php
        $res = mysqli_query($link, "SELECT id, name FROM beds WHERE active=1 ORDER BY name");
        while ($b = mysqli_fetch_assoc($res)) {
            echo "<option value='{$b['id']}'>" . htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') . '</option>';
        }
        ?>
      </select>
    </div>
    <div class="mb-4">
      <label for="pesticide" class="form-label fs-5">使用農薬名</label>
      <input type="text" id="pesticide" class="form-control form-control-lg">
    </div>
    <div class="mb-4">
      <label for="dilution" class="form-label fs-5">希釈倍数・使用量</label>
      <input type="text" id="dilution" class="form-control form-control-lg">
    </div>
    <div class="mb-4">
      <label for="method" class="form-label fs-5">手段</label>
      <input type="text" id="method" class="form-control form-control-lg">
    </div>
    <div class="mb-4">
      <label for="status" class="form-label fs-5">作物の状況</label>
      <textarea id="status" class="form-control" rows="3"></textarea>
    </div>
    <div class="d-grid">
      <button type="submit" class="btn btn-primary btn-lg">登録</button>
    </div>
  </form>
</div>
<?php forecast_nav('today', '../'); ?>
</body>
</html>
