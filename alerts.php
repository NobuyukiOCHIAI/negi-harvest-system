<?php
/**
 * 経営アラート — 異常・要注意の一覧（取引先非開示）
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/mgmt_alerts.php';

$bundle = gf_mgmt_alerts_bundle($link);
$items = $bundle['items'];
$counts = $bundle['counts'];
$total = (int)$counts['total'];

$catLabel = [
    'weather' => '気温',
    'inventory_break' => '在庫',
    'plant_delay' => '定植',
    'discard' => '破棄',
];
$sevClass = [
    'critical' => 'danger',
    'warn' => 'warn',
    'info' => 'ok',
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>経営アラート</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css?v=20260912b">
  <style>
    .alert-card {
      display: block;
      text-decoration: none;
      color: inherit;
      background: #fff;
      border: 1px solid var(--gf-line);
      border-radius: 12px;
      padding: 0.85rem 0.95rem;
      margin-bottom: 0.65rem;
      box-shadow: var(--gf-shadow);
    }
    .alert-card:active { background: var(--gf-green-soft); }
    .alert-card .row1 {
      display: flex; align-items: flex-start; justify-content: space-between; gap: 0.5rem;
    }
    .alert-card .title { font-weight: 800; font-size: 0.98rem; line-height: 1.3; }
    .alert-card .detail {
      margin-top: 0.35rem; font-size: 0.82rem; color: var(--gf-muted); line-height: 1.4;
    }
    .alert-card .meta {
      display: flex; flex-wrap: wrap; gap: 0.35rem; margin-top: 0.45rem; align-items: center;
    }
    .sev-pill {
      display: inline-block; font-size: 0.68rem; font-weight: 800;
      padding: 0.12rem 0.45rem; border-radius: 999px;
    }
    .sev-pill.critical { background: #ffebee; color: #c62828; }
    .sev-pill.warn { background: #fff8e1; color: #ef6c00; }
    .sev-pill.info { background: #e8f5e9; color: #2e7d32; }
    .cat-pill {
      display: inline-block; font-size: 0.68rem; font-weight: 700;
      padding: 0.12rem 0.45rem; border-radius: 999px;
      background: var(--gf-bg); color: var(--gf-muted);
    }
    .badge-soft {
      margin-left: auto; font-size: 0.72rem; font-weight: 800; color: var(--gf-muted);
      white-space: nowrap;
    }
    .empty-ok {
      text-align: center; padding: 2rem 1rem;
      background: #e8f5e9; border-radius: 12px; color: #1b5e20;
      font-weight: 700;
    }
  </style>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">経営アラート</h1>
      <p class="page-sub">遅れ・破棄・在庫割れ・気温鮮度。社内用（取引先非開示）</p>
    </div>
  </div>

  <div class="stat-row mb-3">
    <div class="stat-card <?= $counts['critical'] ? 'danger' : '' ?>">
      <div class="stat-label">重大</div>
      <div class="stat-value"><?= (int)$counts['critical'] ?></div>
    </div>
    <div class="stat-card <?= $counts['warn'] ? 'warn' : '' ?>">
      <div class="stat-label">注意</div>
      <div class="stat-value"><?= (int)$counts['warn'] ?></div>
    </div>
    <div class="stat-card <?= $total === 0 ? 'ok' : '' ?>">
      <div class="stat-label">合計</div>
      <div class="stat-value"><?= $total ?></div>
    </div>
  </div>

  <?php if (!$items): ?>
    <div class="empty-ok mb-3">いま注意すべき異常はありません</div>
    <p class="page-sub">先行きは <a href="inventory.php">予測</a> / <a href="capacity.php">需給</a>、実績は <a href="actual.php">収量</a>。</p>
  <?php else: ?>
    <?php foreach ($items as $it): ?>
      <?php
        $sev = (string)$it['severity'];
        $cat = (string)$it['category'];
      ?>
      <a class="alert-card" href="<?= htmlspecialchars((string)$it['href'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="row1">
          <div class="title"><?= htmlspecialchars((string)$it['title'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php if (!empty($it['badge'])): ?>
            <div class="badge-soft"><?= htmlspecialchars((string)$it['badge'], ENT_QUOTES, 'UTF-8') ?></div>
          <?php endif; ?>
        </div>
        <div class="detail"><?= htmlspecialchars((string)$it['detail'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="meta">
          <span class="sev-pill <?= htmlspecialchars($sev, ENT_QUOTES, 'UTF-8') ?>">
            <?= $sev === 'critical' ? '重大' : ($sev === 'warn' ? '注意' : '監視') ?>
          </span>
          <span class="cat-pill"><?= htmlspecialchars($catLabel[$cat] ?? $cat, ENT_QUOTES, 'UTF-8') ?></span>
          <span class="cat-pill"><?= gf_icon('arrow', 'ql-chevron') ?> 開く</span>
        </div>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
<?php forecast_nav('settings'); ?>
</body>
</html>
