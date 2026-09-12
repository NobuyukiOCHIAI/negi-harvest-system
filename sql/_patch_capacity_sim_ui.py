# -*- coding: utf-8 -*-
from pathlib import Path

p = Path(r"c:\Users\n00218\my-workspace\栽培予測システム\capacity.php")
text = p.read_text(encoding="utf-8")
start = text.find("  <title>")
end = text.find('  <div class="chart-card primary" id="sec-outlook">')
if start < 0 or end < 0:
    raise SystemExit(f"markers not found start={start} end={end}")

new = r'''  <title>需給シミュレーション</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css?v=20260912d">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="js/gf-chart-theme.js?v=20260912c"></script>
  <style>
    .sim-hero {
      background: #fff;
      border: 1px solid var(--gf-line);
      border-radius: 12px;
      padding: 0.9rem 1rem;
      margin-bottom: 0.85rem;
      box-shadow: var(--gf-shadow);
    }
    .sim-hero h2 { font-size: 1rem; font-weight: 800; margin: 0 0 0.35rem; }
    .sim-compare {
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      gap: 0.5rem;
      align-items: stretch;
      margin: 0.75rem 0;
    }
    .sim-box {
      border: 1px solid var(--gf-line);
      border-radius: 10px;
      padding: 0.65rem 0.7rem;
      background: var(--gf-bg, #f4f6f5);
    }
    .sim-box.after.ok { background: #e8f5e9; border-color: #a5d6a7; }
    .sim-box.after.warn { background: #fff8e1; border-color: #ffe082; }
    .sim-box .s-lab { font-size: 0.7rem; font-weight: 700; color: var(--gf-muted); }
    .sim-box .s-val { font-size: 1.55rem; font-weight: 800; line-height: 1.1; }
    .sim-box .s-sub { font-size: 0.72rem; color: var(--gf-muted); margin-top: 0.2rem; }
    .sim-arrow {
      display: flex; align-items: center; justify-content: center;
      font-weight: 800; color: var(--gf-green-dark); font-size: 0.85rem;
    }
    .sim-form { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: end; }
    .sim-form label { font-size: 0.75rem; font-weight: 700; color: var(--gf-muted); display: block; }
    .sim-form input[type=number] {
      width: 6.5rem; font-weight: 800; font-size: 1.05rem;
      padding: 0.35rem 0.5rem; border-radius: 8px; border: 1px solid var(--gf-line);
    }
    details.secondary-block {
      border: 1px solid var(--gf-line);
      border-radius: 10px;
      padding: 0.55rem 0.75rem;
      margin-bottom: 0.75rem;
      background: #fff;
    }
    details.secondary-block > summary {
      font-weight: 800; font-size: 0.9rem; cursor: pointer; list-style: none;
    }
    details.secondary-block > summary::-webkit-details-marker { display: none; }
    details.secondary-block > summary::after { content: ' ▸'; color: var(--gf-muted); }
    details.secondary-block[open] > summary::after { content: ' ▾'; }
  </style>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">需給シミュレーション</h1>
      <p class="page-sub">実効前提で打ち手を試し、在庫割れを防ぐ／先延ばしする · 本線の見通しは <a href="inventory.php">予測</a></p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success py-2"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <?php
    $bs = $breakSim;
    $baseRun = (int)$bs['baseline']['runway_weeks'];
    $scRun = (int)$bs['scenario']['runway_weeks'];
    $delta = (int)$bs['delta_runway'];
    $afterCls = $delta > 0 ? 'ok' : ($delta < 0 ? 'warn' : '');
    $effNow = (int)$bs['eff_yield_kg'];
    $sug = (int)$bs['suggested_kg'];
    $recentN = (int)($bs['recent']['n'] ?? 0);
    $baseBreak = $bs['baseline']['first_break_week'] ?? null;
    $scBreak = $bs['scenario']['first_break_week'] ?? null;
  ?>

  <section class="sim-hero" id="sec-break-sim">
    <h2>打ち手① 実効収量で残量を置き換える</h2>
    <p class="page-sub mb-2">
      直近完了床の平均（ゴミ除く<?= $recentN ? '・n=' . $recentN : '' ?>）は約 <strong><?= $sug ?>kg</strong>。
      栽培中の残も同じ実力と見ると、割れは先延ばしできるか？
    </p>
    <form class="sim-form" method="get" action="capacity.php">
      <input type="hidden" name="sim" value="<?= htmlspecialchars($simParam, ENT_QUOTES, 'UTF-8') ?>">
      <div>
        <label for="eff">実効収量（kg/床）</label>
        <input id="eff" type="number" name="eff" min="40" max="400" step="5" value="<?= $effNow ?>">
      </div>
      <button type="submit" class="btn btn-success btn-sm">試す</button>
      <a class="btn btn-outline-secondary btn-sm" href="?eff=<?= $sug ?>&sim=<?= urlencode($simParam) ?>">平均<?= $sug ?>kg</a>
      <a class="btn btn-outline-secondary btn-sm" href="?eff=160&sim=<?= urlencode($simParam) ?>">160kg</a>
    </form>

    <div class="sim-compare">
      <div class="sim-box">
        <div class="s-lab">現状（モデル残）</div>
        <div class="s-val"><?= $baseRun ?><span style="font-size:0.85rem">週</span></div>
        <div class="s-sub">割れまで · 残合計 <?= number_format($bs['open_remain_baseline'], 0) ?>kg
          <?php if ($baseBreak): ?> · 初回 <?= h_sunday_week($baseBreak) ?><?php endif; ?></div>
      </div>
      <div class="sim-arrow"><?= $delta > 0 ? '+' . $delta : (string)$delta ?>週</div>
      <div class="sim-box after <?= $afterCls ?>">
        <div class="s-lab">実効 <?= $effNow ?>kg/床</div>
        <div class="s-val"><?= $scRun ?><span style="font-size:0.85rem">週</span></div>
        <div class="s-sub">割れまで · 残合計 <?= number_format($bs['open_remain_scenario'], 0) ?>kg
          <?php if ($scBreak): ?> · 初回 <?= h_sunday_week($scBreak) ?><?php endif; ?></div>
      </div>
    </div>
    <p class="page-sub mb-0">
      定植済ベース（いまの畑）の比較。DBは書き換えない。計画どおりの本線は下の詳細／<a href="inventory.php">予測</a>。
      異常の横断は <a href="alerts.php">経営アラート</a>。
    </p>
  </section>

  <div class="chart-card primary">
    <div class="chart-title">定植済累計：現状 vs 実効収量</div>
    <p class="page-sub mb-2"><span class="chart-swatch planted"></span>モデル残 · <span class="chart-swatch sales"></span>実効<?= $effNow ?>kg · 0線が割れ</p>
    <div class="chart-wrap tall"><canvas id="breakSimChart"></canvas></div>
  </div>

  <?= gf_promise_vs_capacity_card_html($promiseSum, '#sec-outlook') ?>

  <details class="secondary-block">
    <summary>営業アラート（<?= count($trustActions) ?>件）· 二次</summary>
    <p class="page-sub mt-2 mb-2">SIMの結果を共有する材料。ラベルは一時 / トレンド / 遅れ。</p>
    <?php if (!$trustActions): ?>
      <div class="job-card"><div class="job-meta">いま出すアラートはありません</div></div>
    <?php else: ?>
    <?php foreach ($trustActions as $a):
      $isSpot = ($a['kind'] ?? '') === 'spot' || ($a['type'] ?? '') === 'spot_surplus';
      $isTighten = ($a['type'] ?? '') === 'commit_tighten' || ($a['type'] ?? '') === 'trend_tighten';
      $isDelay = ($a['kind'] ?? '') === 'exec' || ($a['type'] ?? '') === 'plant_delay';
      $isExpand = ($a['type'] ?? '') === 'commit_expand';
      $line = $a['short_line'] ?? supply_alert_short_line($a);
      $badge = $isDelay ? '遅れ' : ($isSpot ? '一時' : 'トレンド');
      $badgeCls = $isDelay || ($isTighten && empty($a['cover']['covered']))
        ? 'bg-danger'
        : ($isSpot || ($isTighten && !empty($a['cover']['covered'])) ? 'bg-warning text-dark' : 'bg-success');
    ?>
      <div class="job-card py-2 <?= $isDelay || ($isTighten && empty($a['cover']['covered'])) || $isSpot ? 'risk' : '' ?>">
        <div class="d-flex justify-content-between align-items-center gap-2">
          <div class="fw-bold">
            <span class="badge <?= $badgeCls ?> me-1"><?= $badge ?></span>
            <?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?>
          </div>
          <?php if ($isDelay): ?>
            <a class="btn btn-sm btn-outline-danger" href="today.php#sec-plant">定植へ</a>
          <?php endif; ?>
        </div>
        <?php if (!empty($a['detail']) && ($isDelay || $isExpand || $isTighten)): ?>
          <div class="job-meta"><?= htmlspecialchars((string)$a['detail'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($isTighten && !empty($a['cover']['note'])): ?>
          <div class="job-meta"><?= htmlspecialchars((string)$a['cover']['note'], ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </details>

  <details class="secondary-block">
    <summary>能力サマリ · 二次</summary>
    <div class="stat-row mt-2">
      <div class="stat-card <?= $sum['zero_weeks'] ? 'danger' : 'ok' ?>">
        <div class="stat-label">能力0の週</div>
        <div class="stat-value"><?= (int)$sum['zero_weeks'] ?></div>
      </div>
      <div class="stat-card" style="background:#e8f5ee">
        <div class="stat-label">拡大案</div>
        <div class="stat-value"><?= (int)$expandShown ?></div>
      </div>
      <div class="stat-card danger">
        <div class="stat-label">破棄</div>
        <div class="stat-value"><?= (int)$sum['discard_n'] ?></div>
      </div>
    </div>
    <p class="page-sub mt-2 mb-0">
      定植済 <?= number_format($sum['open_kg_total'], 0) ?>kg
      + 予定 <?= number_format($sum['planned_kg_total'], 0) ?>kg
      + 仮想 <?= number_format($sum['rotation_kg_total'], 0) ?>kg
      · 空き <?= (int)$sum['empty_beds'] ?>
    </p>
  </details>

'''

p.write_text(text[:start] + new + text[end:], encoding="utf-8")
print("patched", start, end, "ok")
