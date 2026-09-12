<?php
/**
 * メニュー — 役割別の入口（予測・需給・収量は下部ナビからも入れる）
 */
require_once __DIR__ . '/lib/nav.php';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>メニュー</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css?v=20260912a">
</head>
<body>
<div class="container py-3">
  <h1 class="page-title mb-1">メニュー</h1>
  <p class="page-sub mb-3">圃場は「今日 → 栽培」。営業は「予測 → 需給」。収量は下部ナビ「収量」からも1タップ。</p>

  <h2 class="section-title"><?= gf_icon('harvest') ?> 経営常時</h2>
  <p class="page-sub mb-2">実績パフォーマンス（取引先非開示）。ゴミ込みの実力をいつでも。</p>
  <div class="list-group quick-links rounded-3 overflow-hidden shadow-sm mb-4">
    <a class="list-group-item list-group-item-action" href="actual.php"><?= gf_icon('harvest', 'ql-ico') ?>実収穫量<?= gf_icon('arrow', 'ql-chevron') ?></a>
  </div>

  <h2 class="section-title"><?= gf_icon('harvest') ?> 圃場</h2>
  <p class="page-sub mb-2">作業の入力と、ベッドの今。</p>
  <div class="list-group quick-links rounded-3 overflow-hidden shadow-sm mb-4">
    <a class="list-group-item list-group-item-action" href="today.php"><?= gf_icon('calendar', 'ql-ico') ?>今日の作業<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="monitor.php"><?= gf_icon('bed', 'ql-ico') ?>栽培モニター<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="data_entry/planting.php"><?= gf_icon('plant', 'ql-ico') ?>定植入力（ベッド指定）<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="data_entry/harvest.php"><?= gf_icon('harvest', 'ql-ico') ?>収穫入力（ベッド指定）<?= gf_icon('arrow', 'ql-chevron') ?></a>
  </div>

  <h2 class="section-title"><?= gf_icon('chart') ?> 営業</h2>
  <p class="page-sub mb-2">在庫の先行きは予測、出荷の調整は需給。下部ナビと同じ画面です。</p>
  <div class="list-group quick-links rounded-3 overflow-hidden shadow-sm mb-4">
    <a class="list-group-item list-group-item-action" href="inventory.php"><?= gf_icon('chart', 'ql-ico') ?>収穫予測<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="capacity.php"><?= gf_icon('chart', 'ql-ico') ?>需給・営業<?= gf_icon('arrow', 'ql-chevron') ?></a>
  </div>

  <h2 class="section-title"><?= gf_icon('alert') ?> 品質・振り返り</h2>
  <p class="page-sub mb-2">遅れ・昨対・生育。診断・基盤はここ。</p>
  <div class="list-group quick-links rounded-3 overflow-hidden shadow-sm mb-4">
    <a class="list-group-item list-group-item-action" href="overgrow.php"><?= gf_icon('alert', 'ql-ico') ?>過栽培<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="weather.php"><?= gf_icon('chart', 'ql-ico') ?>気温比較<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="growth_days.php"><?= gf_icon('calendar', 'ql-ico') ?>生育日<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="loss.php"><?= gf_icon('chart', 'ql-ico') ?>ロス分析<?= gf_icon('arrow', 'ql-chevron') ?></a>
  </div>

  <h2 class="section-title"><?= gf_icon('bed') ?> 運用</h2>
  <p class="page-sub mb-2">常時回転は自動。ここは例外操作とマスタです。</p>
  <div class="list-group quick-links rounded-3 overflow-hidden shadow-sm mb-3">
    <a class="list-group-item list-group-item-action" href="plan.php"><?= gf_icon('calendar', 'ql-ico') ?>定植計画（例外）<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="beds.php"><?= gf_icon('bed', 'ql-ico') ?>ベッド稼働<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="agent.php"><?= gf_icon('alert', 'ql-ico') ?>予測精度<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="data_browser.php"><?= gf_icon('chart', 'ql-ico') ?>取込データ<?= gf_icon('arrow', 'ql-chevron') ?></a>
    <a class="list-group-item list-group-item-action" href="index.php"><?= gf_icon('bed', 'ql-ico') ?>ホーム<?= gf_icon('arrow', 'ql-chevron') ?></a>
  </div>
</div>
<?php forecast_nav('settings'); ?>
</body>
</html>
