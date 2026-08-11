<?php
require_once '../db.php';
require_once '../api/json_utils.php';
require_once '../api/logging.php';
require_once __DIR__ . '/../lib/build_features.php';
require_once __DIR__ . '/../lib/predict_ridge.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$__stage = 'begin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cycleId = 0;
    try {
        mysqli_begin_transaction($link);

        $bedId = (int)$_POST['bed_id'];
        $sow   = trim((string)($_POST['sow_date'] ?? ''));
        $plant = $_POST['plant_date'] ?? '';
        $scheduleId = (int)($_POST['schedule_id'] ?? 0);

        if ($sow === '') {
            throw new RuntimeException('播種日は必須です。');
        }
        if ($plant === '') {
            throw new RuntimeException('定植日は必須です。');
        }

        // 同一ベッドに未完了サイクルがある場合は拒否
        $stmt = mysqli_prepare(
            $link,
            "SELECT id FROM cycles WHERE bed_id = ? AND harvest_end IS NULL LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'i', $bedId);
        mysqli_stmt_execute($stmt);
        $ores = mysqli_stmt_get_result($stmt);
        $open = mysqli_fetch_assoc($ores);
        mysqli_stmt_close($stmt);
        if ($open) {
            throw new RuntimeException('このベッドには未完了のサイクルがあります（cycle_id=' . $open['id'] . '）。');
        }

        $stmt = mysqli_prepare($link, "INSERT INTO cycles (bed_id, sow_date, plant_date) VALUES (?, ?, ?)");
        mysqli_stmt_bind_param($stmt, 'iss', $bedId, $sow, $plant);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        $cycleId = (int)mysqli_insert_id($link);
        $__stage = 'insert-cycles';

        if ($scheduleId > 0) {
            $st = mysqli_prepare(
                $link,
                "UPDATE plant_schedule SET status='done' WHERE id=? AND bed_id=? AND status IN ('planned','approved')"
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'ii', $scheduleId, $bedId);
                @mysqli_stmt_execute($st);
                mysqli_stmt_close($st);
            }
        }

        // サイクルは確定。以降の予測失敗でも残す。
        mysqli_commit($link);

        $__stage = 'build-features-predict';
        try {
            // 定植時は plant モデル・⑤なし
            $result = rebuild_and_predict_cycle($link, $cycleId, false, 'plant');
            log_error('debug log', [
                'stage' => 'ridge predict ok',
                'cycle_id' => $cycleId,
                'pred' => $result['pred'],
            ]);
            header('Location: ../cycle.php?id=' . $cycleId . '&msg=predicted');
            exit;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'temperature') !== false || stripos($msg, 'asof') !== false || stripos($msg, 'data missing') !== false) {
                $__stage = 'alert-data-missing';
                $payload = encode_json(['cycle_id' => $cycleId, 'error' => $msg]);
                $stmt = mysqli_prepare($link, "INSERT INTO alerts (date, type, payload_json, status) VALUES (CURDATE(),'data_missing', ?, 'open')");
                mysqli_stmt_bind_param($stmt, 's', $payload);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
                header('Location: ../cycle.php?id=' . $cycleId . '&msg=temp_pending');
                exit;
            }
            log_error('planting predict failed', [
                'stage' => $__stage,
                'cycle_id' => $cycleId,
                'error' => $msg,
            ]);
            header('Location: ../cycle.php?id=' . $cycleId . '&msg=predict_failed');
            exit;
        }

    } catch (Throwable $e) {
        if (isset($link) && $link instanceof mysqli) { @mysqli_rollback($link); }
        log_error('planting failed', ['stage'=>$__stage, 'error'=>$e->getMessage(), 'cycle_id'=>$cycleId]);
        header('Location: planting.php?error=1&msg=' . rawurlencode($e->getMessage()));
        exit;
    }
}

$error = isset($_GET['error']);
$errorMsg = $_GET['msg'] ?? '';
$preBedId = (int)($_GET['bed_id'] ?? 0);
$preScheduleId = (int)($_GET['schedule_id'] ?? 0);
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
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 text-primary">定植入力</h4>
    <a class="btn btn-sm btn-outline-success" href="../today.php">今日の作業へ</a>
  </div>
  <?php if ($error): ?>
    <div class="alert alert-danger">
      <?= $errorMsg !== ''
        ? htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8')
        : '定植登録に失敗しました。未完了サイクルの有無や入力値を確認してください。' ?>
    </div>
  <?php endif; ?>
  <form method="POST">
    <?php if ($preScheduleId > 0): ?>
      <input type="hidden" name="schedule_id" value="<?= $preScheduleId ?>">
    <?php endif; ?>
    <div class="mb-4">
      <label for="bed" class="form-label fs-5">ベッド名</label>
      <select id="bed" name="bed_id" class="form-select form-select-lg" required>
        <option value="">選択してください</option>
        <?php
        $result = mysqli_query($link, "SELECT id, name FROM beds WHERE active=1 ORDER BY name");
        while ($b = mysqli_fetch_assoc($result)) {
            $sel = ($preBedId > 0 && (int)$b['id'] === $preBedId) ? ' selected' : '';
            echo '<option value="' . (int)$b['id'] . '"' . $sel . '>' . htmlspecialchars($b['name']) . '</option>';
        }
        mysqli_free_result($result);
        ?>
      </select>
    </div>
    <div class="mb-4">
      <label for="sow_date" class="form-label fs-5">播種日 <span class="text-danger">必須</span></label>
      <input type="date" id="sow_date" name="sow_date" class="form-control form-control-lg" required>
      <div class="form-text">育苗日数: <span id="nursery_days">0</span>日（予測に使用）</div>
    </div>
    <div class="mb-4">
      <label for="plant_date" class="form-label fs-5">定植日 <span class="text-danger">必須</span></label>
      <input type="date" id="plant_date" name="plant_date" class="form-control form-control-lg" required>
    </div>
    <div class="d-grid">
      <button type="submit" class="btn btn-primary btn-lg">登録</button>
    </div>
  </form>
</div>
<?php
require_once __DIR__ . '/../lib/nav.php';
forecast_nav('today', '../');
?>
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
