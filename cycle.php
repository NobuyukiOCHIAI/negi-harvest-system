<?php
/**
 * 定植／予測結果の簡易確認画面（旧 cycle.php 欠落の代替）
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/date_display.php';

$id = (int)($_GET['id'] ?? 0);
$msg = $_GET['msg'] ?? '';

$cycle = null;
$pred = null;
if ($id > 0) {
    $stmt = mysqli_prepare(
        $link,
        "SELECT c.id, c.sow_date, c.plant_date, c.harvest_start, c.harvest_end, b.name AS bed_name
         FROM cycles c JOIN beds b ON b.id = c.bed_id
         WHERE c.id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $cycle = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare(
        $link,
        "SELECT pred_days, pred_total_kg, postproc_total_kg, model_id, created_at
         FROM predictions WHERE cycle_id = ?
         ORDER BY created_at DESC LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $pred = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
}

$flash = [
    'predicted' => ['success', '定植を登録し、予測を保存しました。'],
    'temp_pending' => ['warning', '定植は登録しましたが、気温データ不足のため予測は保留です。'],
    'predict_failed' => ['warning', '定植は登録しましたが、予測に失敗しました。後で再予測できます。'],
    'planted' => ['success', '定植を登録しました。'],
];
[$alertType, $alertText] = $flash[$msg] ?? [null, null];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>サイクル確認</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
</head>
<body class="pb-5">
<div class="container py-4">
  <h4 class="mb-3">サイクル確認</h4>
  <?php if ($alertText): ?>
    <div class="alert alert-<?= htmlspecialchars($alertType) ?>"><?= htmlspecialchars($alertText) ?></div>
  <?php endif; ?>

  <?php if (!$cycle): ?>
    <div class="alert alert-danger">サイクルが見つかりません。</div>
  <?php else: ?>
    <div class="card mb-3">
      <div class="card-body">
        <p class="mb-1">サイクルID: <?= (int)$cycle['id'] ?></p>
        <p class="mb-1">ベッド: <?= htmlspecialchars($cycle['bed_name']) ?></p>
        <p class="mb-1">播種: <?= h_month_week($cycle['sow_date'] ?? null, '-') ?></p>
        <p class="mb-1">定植: <?= h_month_week($cycle['plant_date'] ?? null, '-') ?></p>
        <p class="mb-1">収穫開始: <?= h_month_week($cycle['harvest_start'] ?? null, '未') ?></p>
        <p class="mb-0">収穫完了: <?= h_month_week($cycle['harvest_end'] ?? null, '未') ?></p>
      </div>
    </div>
    <?php if ($pred): ?>
      <?php
        $expected = null;
        if (!empty($cycle['plant_date']) && $pred['pred_days'] !== null) {
            $expected = (new DateTime($cycle['plant_date']))
                ->modify('+' . (int)round((float)$pred['pred_days']) . ' day')
                ->format('Y-m-d');
        }
      ?>
      <div class="card mb-3">
        <div class="card-header">最新予測</div>
        <div class="card-body">
          <p class="mb-1">予測日数: <?= htmlspecialchars((string)$pred['pred_days']) ?> 日</p>
          <p class="mb-1">予測収量（モデル）: <?= htmlspecialchars((string)$pred['pred_total_kg']) ?> kg</p>
          <?php if ($pred['postproc_total_kg'] !== null && $pred['postproc_total_kg'] !== ''): ?>
            <p class="mb-1">途中収穫からの見込み: <?= htmlspecialchars((string)$pred['postproc_total_kg']) ?> kg</p>
          <?php endif; ?>
          <p class="mb-1">期待収穫（算出）: <?= h_month_week($expected ?? null, '-') ?></p>
          <p class="mb-0 text-muted small">model: <?= htmlspecialchars($pred['model_id'] ?? '-') ?></p>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-secondary">予測はまだありません。</div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="d-grid gap-2">
    <a class="btn btn-primary" href="data_entry/harvest.php">収穫入力へ</a>
    <a class="btn btn-outline-secondary" href="data_entry/planting.php">続けて定植</a>
    <a class="btn btn-outline-secondary" href="monitor.php">栽培状況</a>
  </div>
</div>
</body>
</html>
