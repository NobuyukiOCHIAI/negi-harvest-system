<?php
require_once '../db.php';
require_once '../api/json_utils.php';
require_once '../api/logging.php'; // log_error($message, array $ctx=[])
require_once __DIR__ . '/../lib/build_features.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$__stage = 'begin';

function getEnvOrDefault($key, $default = null) {
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        mysqli_begin_transaction($link);

        $bedId = (int)$_POST['bed_id'];
        $sow   = $_POST['sow_date'] ?? null;
        $plant = $_POST['plant_date'];

        $stmt = mysqli_prepare($link, "INSERT INTO cycles (bed_id, sow_date, plant_date) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'iss', $bedId, $sow, $plant);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $cycleId = (int)mysqli_insert_id($link);
        $__stage = 'insert-cycles';

        // 特徴量の作成・保存
        $__stage = 'build-features';
        try {
            $features = rebuild_features_for_cycle($link, $cycleId);
        } catch (Throwable $e) {
            $__stage = 'alert-data-missing';
            $payload = encode_json(['cycle_id' => $cycleId]);
            $stmt = mysqli_prepare($link, "INSERT INTO alerts (date, type, payload_json, status) VALUES (CURDATE(),'data_missing', ?, 'open')");
            mysqli_stmt_bind_param($stmt, 's', $payload);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            mysqli_commit($link);
            header('Location: ../cycle.php?id=' . $cycleId . '&msg=temp_pending');
            exit;
        }

        $__stage = 'call-api';
        $apiUrl = getEnvOrDefault('XGB_API_URL', 'http://tk2-118-59530.vs.sakura.ne.jp/xgbapi/api/predict_both');
        $apiKey = getEnvOrDefault('XGB_API_KEY', '');
        $payload = json_encode(['data' => [['features' => $features]]], JSON_UNESCAPED_UNICODE);

        // debug log for features before API call
        log_error('debug log', [
            'stage' => 'XGBAPI送信前:features',
            'cycle_id' => $cycleId,
            'features' => $features,
        ]);

        $attempt = 0;
        $json = null;
        $delay = 1;
        while ($attempt < 3) {
            $attempt++;
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ["Content-Type: application/json", "x-api-key: " . $apiKey],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            if ($res !== false) {
                $json = json_decode($res, true);
                if (($json['ok'] ?? false)) { break; }
            }
            if ($attempt < 3) { sleep($delay); $delay *= 2; }
        }
        if (!($json['ok'] ?? false)) {
            throw new Exception('xgbapi response not ok');
        }

        $pred = $json['predictions'][0] ?? null;
        if (!$pred) { throw new Exception('no predictions'); }

        $__stage = 'insert-predictions';
        $stmt = mysqli_prepare($link, "INSERT INTO predictions (cycle_id, model_id, pred_days, pred_total_kg) VALUES (?, ?, ?, ?)");
        $modelId = basename(dirname($json['model_path_days'] ?? 'current'));
        mysqli_stmt_bind_param($stmt, 'isdd', $cycleId, $modelId, $pred['days'], $pred['yield']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);

        // 期待収穫日は DB 保存しない（列無し）。必要なら画面側で：
        // $expectedDate = (new DateTime($plant))->modify('+' . round($pred['days']) . ' day')->format('Y-m-d');

        mysqli_commit($link);
        header('Location: ../cycle.php?id=' . $cycleId . '&msg=predicted');
        exit;

    } catch (Throwable $e) {
        if (isset($link) && $link instanceof mysqli) { @mysqli_rollback($link); }
        log_error('planting failed', ['stage'=>$__stage, 'error'=>$e->getMessage()]);
        header('Location: planting.php?error=1'); exit;
    }
}
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
  <form method="POST">
    <div class="mb-4">
      <label for="bed" class="form-label fs-5">ベッド名</label>
      <select id="bed" name="bed_id" class="form-select form-select-lg" required>
        <option value="">選択してください</option>
        <?php
        $result = mysqli_query($link, "SELECT id, name FROM beds WHERE active=1 ORDER BY name");
        while ($b = mysqli_fetch_assoc($result)) {
            echo "<option value='{$b['id']}'>{$b['name']}</option>";
        }
        mysqli_free_result($result);
        ?>
      </select>
    </div>
    <div class="mb-4">
      <label for="sow_date" class="form-label fs-5">播種日</label>
      <input type="date" id="sow_date" name="sow_date" class="form-control form-control-lg">
      <div class="form-text">育苗日数: <span id="nursery_days">0</span>日</div>
    </div>
    <div class="mb-4">
      <label for="plant_date" class="form-label fs-5">定植日</label>
      <input type="date" id="plant_date" name="plant_date" class="form-control form-control-lg" required>
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

