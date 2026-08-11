<?php
/**
 * 設定・運用 — 役割別に整理
 */
require_once __DIR__ . '/lib/nav.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>設定</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
</head>
<body>
<div class="container py-3">
  <h1 class="page-title mb-1">メニュー</h1>
  <p class="page-sub mb-3">役割ごとに使う画面が違います</p>

  <h2 class="section-title"><?= gf_icon('harvest') ?> 圃場スタッフ</h2>
  <div class="list-group quick-links rounded-3 overflow-hidden shadow-sm mb-4">
    <a class="list-group-item list-group-item-action" href="today.php"><?= gf_icon('calendar', 'ql-ico') ?>今日の作業<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="data_entry/planting.php"><?= gf_icon('plant', 'ql-ico') ?>定植入力<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="data_entry/harvest.php"><?= gf_icon('harvest', 'ql-ico') ?>収穫入力<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="monitor.php"><?= gf_icon('bed', 'ql-ico') ?>栽培モニター<?= gf_icon('arrow', 'ql-chevron') ?></a>
  </div>

  <h2 class="section-title"><?= gf_icon('chart') ?> 営業・管理</h2>
  <div class="list-group quick-links rounded-3 overflow-hidden shadow-sm mb-4">
    <a class="list-group-item list-group-item-action" href="inventory.php"><?= gf_icon('chart', 'ql-ico') ?>収穫予測（累計在庫）<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="capacity.php"><?= gf_icon('chart', 'ql-ico') ?>需給・営業調整<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="agent.php"><?= gf_icon('alert', 'ql-ico') ?>監視エージェント<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="plan.php"><?= gf_icon('calendar', 'ql-ico') ?>定植計画<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="loss.php"><?= gf_icon('chart', 'ql-ico') ?>ロス分析<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="actual.php"><?= gf_icon('harvest', 'ql-ico') ?>実収穫量<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="overgrow.php"><?= gf_icon('alert', 'ql-ico') ?>過栽培<?= gf_icon('arrow', 'ql-chevron') ?></a>
  </div>

  <h2 class="section-title"><?= gf_icon('calendar') ?> データ同期</h2>
  <div class="list-group quick-links rounded-3 overflow-hidden shadow-sm mb-3">
    <a class="list-group-item list-group-item-action" href="inventory.php?force_sync=1"><?= gf_icon('calendar', 'ql-ico') ?>出荷カレンダー再取込<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="data_browser.php"><?= gf_icon('chart', 'ql-ico') ?>取込データ閲覧<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="index.php"><?= gf_icon('bed', 'ql-ico') ?>ホーム<?= gf_icon('arrow', 'ql-chevron') ?></a>
  </div>
</div>
<?php forecast_nav('settings'); ?>
</body>
</html>
