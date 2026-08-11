<?php
/**
 * 監視エージェント — 栽培健全性 / 予測精度 / 需給アラートを外部視点で監視
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/supply_ops.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/nav.php';

$snap = supply_agent_snapshot($link);
$k = $snap['kpis'];
$healthCls = [
    'ok' => 'ok',
    'warn' => 'warn',
    'critical' => 'danger',
][$snap['health']] ?? 'warn';
$healthLabel = [
    'ok' => '健全',
    'warn' => '要注意',
    'critical' => '要対応',
][$snap['health']] ?? $snap['health'];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>監視エージェント</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">監視エージェント</h1>
      <p class="page-sub">栽培状態 · 予測精度 · 需給KPI · <?= htmlspecialchars($snap['checked_at'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </div>

  <div class="stat-card <?= $healthCls ?> mb-3">
    <div class="stat-label">総合判定</div>
    <div class="stat-value" style="font-size:1.6rem"><?= htmlspecialchars($healthLabel, ENT_QUOTES, 'UTF-8') ?></div>
  </div>

  <div class="stat-row mb-3">
    <div class="stat-card">
      <div class="stat-label">割れまで</div>
      <div class="stat-value"><?= (int)$k['runway_weeks'] ?></div>
      <div class="stat-sub">週</div>
    </div>
    <div class="stat-card <?= $k['empty_beds'] ? 'warn' : 'ok' ?>">
      <div class="stat-label">空き床</div>
      <div class="stat-value"><?= (int)$k['empty_beds'] ?></div>
    </div>
    <div class="stat-card <?= ($k['pred_mape_pct'] ?? 0) > 35 ? 'warn' : '' ?>">
      <div class="stat-label">予測MAPE</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $k['pred_mape_pct'] === null ? '—' : $k['pred_mape_pct'] . '%' ?></div>
    </div>
  </div>

  <div class="stat-row mb-3">
    <div class="stat-card">
      <div class="stat-label">MAE</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $k['pred_mae_kg'] === null ? '—' : number_format($k['pred_mae_kg'], 0) ?></div>
      <div class="stat-sub">kg · n=<?= (int)$k['pred_eval_n'] ?></div>
    </div>
    <div class="stat-card <?= $k['overgrow_beds'] ? 'danger' : '' ?>">
      <div class="stat-label">過栽培</div>
      <div class="stat-value"><?= (int)$k['overgrow_beds'] ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">現場指示</div>
      <div class="stat-value"><?= (int)$k['staff_alerts'] ?></div>
    </div>
  </div>

  <h2 class="section-title"><?= gf_icon('alert') ?> 注目ポイント</h2>
  <?php if (!$snap['notes']): ?>
    <div class="job-card"><div class="job-meta">特記事項なし</div></div>
  <?php else: ?>
    <?php foreach ($snap['notes'] as $note): ?>
      <div class="job-card"><div class="job-meta fw-bold"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></div></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h2 class="section-title"><?= gf_icon('calendar') ?> トレンド / 一時</h2>
  <?php if (!$snap['actions']): ?>
    <div class="job-card"><div class="job-meta">アラートなし</div></div>
  <?php else: ?>
    <?php foreach ($snap['actions'] as $a):
      $isSpot = ($a['kind'] ?? '') === 'spot';
      $isTighten = in_array(($a['type'] ?? ''), ['commit_tighten', 'trend_tighten'], true);
      $line = $a['short_line'] ?? supply_alert_short_line($a);
    ?>
      <div class="job-card py-2 <?= ($a['urgency'] ?? '') === 'critical' || $isSpot ? 'risk' : '' ?>">
        <div class="fw-bold">
          <span class="badge <?= $isSpot ? 'bg-warning text-dark' : ($isTighten ? 'bg-danger' : 'bg-success') ?> me-1">
            <?= $isSpot ? '一時' : 'トレンド' ?>
          </span>
          <?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h2 class="section-title"><?= gf_icon('chart') ?> 季節ベース（直近）</h2>
  <div class="table-responsive">
    <table class="table table-sm bg-white shadow-sm">
      <thead><tr><th>月</th><th class="text-end">昨対</th><th class="text-end">計画</th><th class="text-end">GCAL</th></tr></thead>
      <tbody>
        <?php foreach ($snap['seasonal'] as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-end"><?= number_format($s['yoy_kg'], 0) ?></td>
            <td class="text-end"><?= number_format($s['cap_kg'], 0) ?></td>
            <td class="text-end"><?= number_format($s['commit_kg'], 0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="mt-3 d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-success" href="capacity.php">需給・営業へ</a>
    <a class="btn btn-sm btn-outline-success" href="today.php">今日の作業へ</a>
    <a class="btn btn-sm btn-outline-secondary" href="agent.php">再チェック</a>
  </div>
</div>
<?php forecast_nav('settings'); ?>
</body>
</html>
