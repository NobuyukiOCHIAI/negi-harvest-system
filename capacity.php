<?php
/**
 * 需給シミュレーション — 実効前提で割れ回避の打ち手を試す（正本 §0.10）
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/capacity_outlook.php';
require_once __DIR__ . '/lib/rotation_capacity.php';
require_once __DIR__ . '/lib/inventory_trust.php';
require_once __DIR__ . '/lib/supply_ops.php';
require_once __DIR__ . '/lib/gcal_shipments.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/cycle_photos.php';
require_once __DIR__ . '/lib/promise_capacity.php';
require_once __DIR__ . '/lib/break_sim.php';

$flash = '';
$err = '';
$ensure = supply_ensure_full_rotation($link);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'discard') {
        $cid = (int)($_POST['cycle_id'] ?? 0);
        $result = capacity_discard_cycle($link, $cid);
        if ($result['ok']) {
            supply_ensure_full_rotation($link, true);
            $flash = $result['message'] . ' → 常時回転を再投入';
        } else {
            $err = $result['message'];
        }
    } elseif ($action === 'continuous_plant') {
        $r = supply_ensure_full_rotation($link, true);
        $flash = $r['message'];
    }
}

$sum = capacity_outlook_summary($link);
$weeks = $sum['weeks'];
$recs = $sum['recommendations'];
$discards = $sum['discards'];
$discardPhotoCounts = cycle_photo_counts(
    $link,
    array_map(static fn($d) => (int)$d['cycle_id'], $discards)
);
$trust = trust_outlook_bundle($link, 20);
$trustSum = $trust['summary'];
$trustActions = $trust['actions'];
$season = supply_seasonal_baseline($link, 6);
$dual = supply_dual_week_lines($link, 14);

$chartLabels = [];
$chartGcal = [];
$chartCap = [];
$chartOpen = [];
$chartYoy = [];
$chartSurplus = [];
$chartOpenSurplus = [];
$weekSlice = array_slice($weeks, 0, 14);
foreach ($weekSlice as $i => $w) {
    $chartLabels[] = format_sunday_week($w['week']);
    $gcal = (float)($w['gcal_kg'] ?? ($dual[$i]['gcal_kg'] ?? 0));
    $cap = (float)($w['capacity_kg'] ?? $w['forecast_kg']);
    $openKg = (float)($w['open_kg'] ?? 0);
    $chartGcal[] = $gcal;
    $chartCap[] = $cap;
    $chartOpen[] = $openKg;
    $chartYoy[] = $w['yoy_kg'] ?? 0;
    $chartSurplus[] = round(isset($w['balance_kg']) ? (float)$w['balance_kg'] : ($cap - $gcal), 1);
    $chartOpenSurplus[] = round($openKg - $gcal, 1);
}
$chartCumSurplus = supply_cum_surplus_continuous($link, $weekSlice, true);
$chartOpenCum = supply_cum_surplus_continuous($link, $weekSlice, false);
$cumCarryLabel = $chartCumSurplus ? (float)$chartCumSurplus[0] - ((float)($weekSlice[0]['planned_kg'] ?? 0) + (float)($weekSlice[0]['rotation_kg'] ?? 0)) : 0.0;

$simParam = (string)($_GET['sim'] ?? 'alert');
if ($simParam === 'level') {
    $simParam = ((string)($_GET['level_end'] ?? '')) === 'horizon' ? 'level_weak' : 'level_strong';
}
if (!in_array($simParam, ['alert', 'level_weak', 'level_strong'], true)) {
    $simParam = 'alert';
}
$simMode = $simParam === 'alert' ? 'alert' : 'level';
$levelStrength = $simParam === 'level_weak' ? 0.5 : 1.0;
$chartSimAlert = supply_sim_commit_series($weekSlice, $trustActions, 'spot');
$levelMeta = supply_sim_level_series($weekSlice, $trustActions, null, $levelStrength);
$chartSim = $simMode === 'level' ? $levelMeta['series'] : $chartSimAlert;
$simNote = $simMode === 'level'
    ? $levelMeta['note']
    : '紫=いまのGCAL · 青=一時アラートを該当週にそのまま載せた出荷。トレンドは②③。';

$delays = $trust['plant_delays'] ?? [];
$delayN = count($delays);
$promiseSum = gf_promise_vs_capacity_summary($link, 8);
$effParam = isset($_GET['eff']) ? (float)$_GET['eff'] : null;
if ($effParam !== null && $effParam <= 0) {
    $effParam = null;
}
$shipDelta = isset($_GET['ship_delta']) ? (float)$_GET['ship_delta'] : 0.0;
$plantN = isset($_GET['plant_n']) ? (int)$_GET['plant_n'] : 0;
$earlyDays = isset($_GET['early_days']) ? (int)$_GET['early_days'] : 0;
$breakSim = gf_break_sim_combo($link, [
    'eff' => $effParam,
    'ship_delta' => $shipDelta,
    'plant_n' => $plantN,
    'early_days' => $earlyDays,
], 16);
$plantExecLate = $delayN;
$nearCutoff = supply_near_week_cutoff();
$expandShown = 0;
foreach ($recs as $r) {
    if (($r['type'] ?? '') !== 'expand') {
        continue;
    }
    $start = (string)$r['start_week'];
    if ($start <= $nearCutoff) {
        $kg = (float)($r['avg_kg'] ?? 0);
        if (supply_planted_can_release_spot($trust['open_only'], $start, max($kg, 80))) {
            $expandShown++;
        }
        continue;
    }
    if ($plantExecLate < 3) {
        $expandShown++;
    }
}
foreach ($trustActions as $a) {
    if (($a['type'] ?? '') === 'commit_expand' && (string)$a['start_week'] > $nearCutoff) {
        $expandShown++;
        break;
    }
}

// ③で選んだシミュレーション（アラート直載せ／トレンド平準化）を②の棒・累計線・週次能力表にも反映する。
// 「今のGCALより増やす分」を週次デルタ・累計双方に継続加算するだけなので、
// ①の実績ベース累計線（$chartCumSurplus）との連続性は保ったまま上乗せできる。
$chartSurplusSim = [];
$chartPlanSurplusSim = [];
$simExtraCum = 0.0;
$chartCumSurplusSim = [];
$simGcalByWeek = [];
$simSurplusByWeek = [];
$simPlanSurplusByWeek = [];
foreach ($weekSlice as $i => $w) {
    $simVal = (float)($chartSim[$i] ?? $chartGcal[$i] ?? 0);
    $extra = $simVal - (float)($chartGcal[$i] ?? 0);
    $simExtraCum += $extra;
    $chartSurplusSim[] = round((float)($chartOpen[$i] ?? 0) - $simVal, 1);
    $chartPlanSurplusSim[] = round((float)$chartCap[$i] - $simVal, 1);
    $chartCumSurplusSim[] = round((float)($chartCumSurplus[$i] ?? 0) - $simExtraCum, 1);
    $simGcalByWeek[$w['week']] = round($simVal, 1);
    $simSurplusByWeek[$w['week']] = $chartSurplusSim[$i];
    $simPlanSurplusByWeek[$w['week']] = $chartPlanSurplusSim[$i];
}
$simModeLabel = $simParam === 'level_weak' ? '平準化（弱）'
    : ($simParam === 'level_strong' ? '平準化（強）' : '一時を直載せ');

?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>需給シミュレーション</title>
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
    $shipDeltaV = (float)$bs['ship_delta'];
    $plantNV = (int)$bs['plant_n'];
    $earlyDaysV = (int)($bs['early_days'] ?? 0);
    $emptyN = (int)$bs['empty_beds'];
    $plantedN = (int)($bs['plant_extra']['planted'] ?? 0);
    $shiftedBeds = (int)($bs['early_extra']['shifted_beds'] ?? 0);
    $useEff = !empty($bs['use_eff']);
    $qBase = 'sim=' . rawurlencode($simParam);
  ?>

  <section class="sim-hero" id="sec-break-sim">
    <h2>割れ回避シミュレーション（組み合わせ可）</h2>
    <form class="sim-form" method="get" action="capacity.php">
      <input type="hidden" name="sim" value="<?= htmlspecialchars($simParam, ENT_QUOTES, 'UTF-8') ?>">
      <div>
        <label for="eff">① 実効収量 kg/床</label>
        <input id="eff" type="number" name="eff" min="40" max="400" step="1"
               value="<?= $useEff ? $effNow : $sug ?>" placeholder="<?= $sug ?>">
      </div>
      <div>
        <label for="ship_delta">② 出荷加減 kg</label>
        <input id="ship_delta" type="number" name="ship_delta" min="-500" max="500" step="10" value="<?= (int)$shipDeltaV ?>">
      </div>
      <div>
        <label for="plant_n">③ 明日定植する空き床</label>
        <input id="plant_n" type="number" name="plant_n" min="0" max="999" step="1" value="<?= $plantNV >= 999 ? $emptyN : max(0, $plantNV) ?>">
      </div>
      <div>
        <label for="early_days">④ 前倒し収穫 日</label>
        <input id="early_days" type="number" name="early_days" min="0" max="60" step="1" value="<?= max(0, $earlyDaysV) ?>">
      </div>
      <button type="submit" class="btn btn-success btn-sm">試す</button>
    </form>
    <div class="d-flex flex-wrap gap-2 mt-2 mb-2">
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?>&eff=<?= $sug ?>">①平均<?= $sug ?>kg</a>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?>&eff=160">①160kg</a>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?><?= $useEff ? '&eff=' . $effNow : '' ?>&ship_delta=-50">②出荷−50</a>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?><?= $useEff ? '&eff=' . $effNow : '' ?>&plant_n=999">③空き全床を明日定植(<?= $emptyN ?>)</a>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?><?= $useEff ? '&eff=' . $effNow : '' ?>&early_days=7">④前倒し7日</a>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?><?= $useEff ? '&eff=' . $effNow : '' ?>&early_days=14">④前倒し14日</a>
      <a class="btn btn-outline-secondary btn-sm" href="?<?= $qBase ?>&eff=<?= $sug ?>&ship_delta=-50&plant_n=999&early_days=7">①〜④まとめて</a>
    </div>
    <p class="page-sub mb-2">
      直近平均≈<?= $sug ?>kg<?= $recentN ? "（n={$recentN}）" : '' ?> · 空き床 <?= $emptyN ?> ·
      適用中: <strong><?= htmlspecialchars((string)$bs['lever_label'], ENT_QUOTES, 'UTF-8') ?></strong>
      <?php if ($plantedN > 0): ?>
        · 明日定植<?= $plantedN ?>床→収穫週 <?= h_sunday_week($bs['plant_extra']['harvest_week'] ?? null) ?>
        （約<?= (int)$bs['plant_extra']['days'] ?>日・<?= (int)$bs['plant_extra']['yield_kg'] ?>kg/床）
      <?php endif; ?>
      <?php if ($earlyDaysV > 0): ?>
        · 収穫予定を<?= $earlyDaysV ?>日前倒し<?= $shiftedBeds ? "（{$shiftedBeds}床）" : '' ?>
      <?php endif; ?>
    </p>

    <div class="sim-compare">
      <div class="sim-box">
        <div class="s-lab">現状（モデル残）</div>
        <div class="s-val"><?= $baseRun ?><span style="font-size:0.85rem">週</span></div>
        <div class="s-sub">割れまで · 残 <?= number_format($bs['open_remain_baseline'], 0) ?>kg
          <?php if ($baseBreak): ?> · 初回 <?= h_sunday_week($baseBreak) ?><?php endif; ?></div>
      </div>
      <div class="sim-arrow"><?= $delta > 0 ? '+' . $delta : (string)$delta ?>週</div>
      <div class="sim-box after <?= $afterCls ?>">
        <div class="s-lab">シナリオ</div>
        <div class="s-val"><?= $scRun ?><span style="font-size:0.85rem">週</span></div>
        <div class="s-sub">割れまで · 残 <?= number_format($bs['open_remain_scenario'], 0) ?>kg
          <?php if ($scBreak): ?> · 初回 <?= h_sunday_week($scBreak) ?><?php endif; ?></div>
      </div>
    </div>
    <p class="page-sub mb-0">
      出荷減は営業依頼の試算。前倒し収穫・定植は現場の <a href="today.php">今日</a> で実行。異常は <a href="alerts.php">経営アラート</a>。
    </p>
  </section>

  <div class="chart-card primary">
    <div class="chart-title">定植済累計：現状 vs シナリオ</div>
    <p class="page-sub mb-2"><span class="chart-swatch planted"></span>モデル残 · <span class="chart-swatch sales"></span><?= htmlspecialchars((string)$bs['lever_label'], ENT_QUOTES, 'UTF-8') ?> · 0線が割れ</p>
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

  <details class="secondary-block" id="sec-outlook">
    <summary>本線チャート（週次能力・累計）· 二次</summary>
    <p class="page-sub mt-2 mb-2">計画能力とGCALの山・累計の余りを見る。打ち手の試行は上部SIM。</p>
    <div class="chart-card">
      <div class="chart-title">① 週ごとの生産能力（本線＝計画）</div>
      <p class="page-sub mb-2"><span class="chart-swatch plan"></span>計画 · <span class="chart-swatch planted"></span>定植済 · <span class="chart-swatch gcal"></span>GCAL · 昨対は薄い参考線</p>
      <div class="chart-wrap tall"><canvas id="capChart"></canvas></div>
      <p class="page-sub mt-2 mb-0">緑実線=計画能力（収穫後5日で次定植） · 灰破線=いまの畑 · 紫=GCAL。灰が先でゼロに見えるのは定植催促（公式予測ではない）。</p>
    </div>
    <div class="chart-card">
      <div class="chart-title">② 計画の週次差＋累計（先の余り）</div>
      <p class="page-sub mb-2"><span class="chart-swatch plan"></span>計画累計 · <span class="chart-swatch sales"></span>定植済累計（営業現実） · 棒=計画−GCAL</p>
      <div class="chart-wrap tall"><canvas id="surplusChart"></canvas></div>
      <p class="page-sub mt-2 mb-0">
        棒=計画−GCAL（下の旧出荷SIM反映可）。緑=計画累計。青破線=定植済累計。いま植えても届かない週まで緑と青は一致する。
      </p>
    </div>
  </details>

  <details class="secondary-block">
    <summary>旧・出荷シナリオSIM（一時直載せ）· 二次・非推奨</summary>
    <p class="page-sub mt-2 mb-2">上部の割れ回避SIMで足りる。残置は週次表のGCAL差し替え用。</p>
    <div class="chart-card">
      <div class="chart-title">③ 先々シミュレーション（<?= htmlspecialchars($simModeLabel, ENT_QUOTES, 'UTF-8') ?>）</div>
      <p class="page-sub mb-2"><span class="chart-swatch gcal"></span>GCAL · <span class="chart-swatch sales"></span>シミュレーション出荷</p>
      <div class="d-flex flex-wrap gap-2 mb-2">
        <a class="btn btn-sm <?= $simParam === 'alert' ? 'btn-primary' : 'btn-outline-secondary' ?>"
           href="?sim=alert">① 一時を直載せ</a>
        <a class="btn btn-sm <?= $simParam === 'level_weak' ? 'btn-primary' : 'btn-outline-secondary' ?>"
           href="?sim=level_weak">② 平準化（弱）</a>
        <a class="btn btn-sm <?= $simParam === 'level_strong' ? 'btn-primary' : 'btn-outline-secondary' ?>"
           href="?sim=level_strong">③ 平準化（強）</a>
      </div>
      <div class="chart-wrap tall"><canvas id="simChart"></canvas></div>
      <p class="page-sub mt-2 mb-0"><?= htmlspecialchars($simNote, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </details>

  <details class="secondary-block">
    <summary>季節ベース（取引先共有の目安）· 二次</summary>
  <p class="page-sub mt-2 mb-2">「●月はこのぐらい」の共有用。計画能力＝回転を実行した場合。</p>
  <div class="table-responsive mb-2">
    <table class="table table-sm bg-white shadow-sm">
      <thead><tr><th>月</th><th class="text-end">昨対実績</th><th class="text-end">計画能力</th><th class="text-end">GCAL</th></tr></thead>
      <tbody>
        <?php foreach ($season as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['label'], ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-end"><?= number_format($s['yoy_kg'], 0) ?></td>
            <td class="text-end fw-bold"><?= number_format($s['cap_kg'], 0) ?></td>
            <td class="text-end"><?= number_format($s['commit_kg'], 0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  </details>

  <details class="secondary-block">
    <summary>破棄候補（<?= count($discards) ?>床）· 現場で潰す · 二次</summary>
    <p class="page-sub mt-2 mb-2">床を空けて回転を守る。作業は <a href="today.php#sec-discard">今日</a> でも可。</p>
  <?php if (!$discards): ?>
    <div class="job-card"><div class="job-meta">候補なし</div></div>
  <?php else: ?>
    <?php foreach ($discards as $d): ?>
      <div class="job-card risk">
        <div class="job-top">
          <div>
            <div class="job-name"><?= htmlspecialchars($d['bed_name'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="job-meta text-danger fw-bold"><?= htmlspecialchars($d['reason'], ENT_QUOTES, 'UTF-8') ?></div>
            <div class="job-meta">
              収穫済 <?= (int)$d['harvested_kg'] ?>kg
              <?php if ($d['remain_kg'] !== null): ?> · 残見込み <?= (int)$d['remain_kg'] ?>kg<?php endif; ?>
            </div>
          </div>
          <div class="text-end">
            <?php $photoN = $discardPhotoCounts[(int)$d['cycle_id']] ?? 0; ?>
            <a class="btn btn-sm btn-outline-primary mb-1" href="cycle_photos.php?cycle_id=<?= (int)$d['cycle_id'] ?>">
              写真<?= $photoN > 0 ? ' (' . (int)$photoN . ')' : '' ?>
            </a>
            <a class="btn btn-sm btn-outline-secondary mb-1" href="cycle.php?id=<?= (int)$d['cycle_id'] ?>">詳細</a>
            <form method="post" onsubmit="return confirm('破棄してベッドを空けますか？');">
              <input type="hidden" name="action" value="discard">
              <input type="hidden" name="cycle_id" value="<?= (int)$d['cycle_id'] ?>">
              <button type="submit" class="btn btn-sm btn-danger">破棄して空ける</button>
            </form>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
  </details>

  <details class="secondary-block">
    <summary>週次能力表 · 二次</summary>
  <p class="page-sub mt-2 mb-2">計画差＝回転を実行した到達点。定植済差＝いまの畑。GCALは③の「<?= htmlspecialchars($simModeLabel, ENT_QUOTES, 'UTF-8') ?>」を反映。</p>
  <div class="table-responsive">
    <table class="table table-sm align-middle bg-white shadow-sm">
      <thead>
        <tr>
          <th>週</th>
          <th class="text-end">定植済</th>
          <th class="text-end">回転+</th>
          <th class="text-end">能力</th>
          <th class="text-end">GCAL</th>
          <th class="text-end">定植済差</th>
          <th class="text-end">計画差</th>
          <th class="text-end">昨対</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($weeks as $w): ?>
          <?php
            $rot = ($w['rotation_kg'] ?? 0) + ($w['planned_kg'] ?? 0);
            $hasSim = isset($simGcalByWeek[$w['week']]);
            $gcalShown = $hasSim ? $simGcalByWeek[$w['week']] : (float)($w['gcal_kg'] ?? $w['ship_kg']);
            $openBal = $hasSim
              ? (float)$simSurplusByWeek[$w['week']]
              : ((float)($w['open_kg'] ?? 0) - (float)($w['gcal_kg'] ?? $w['ship_kg']));
            $planBal = $hasSim
              ? (float)($simPlanSurplusByWeek[$w['week']] ?? 0)
              : ((float)($w['capacity_kg'] ?? $w['forecast_kg']) - (float)($w['gcal_kg'] ?? $w['ship_kg']));
            $canRelease = $openBal >= 80
              && supply_planted_can_release_spot($trust['open_only'], (string)$w['week'], $openBal);
            $planExpand = $planBal >= 80 && (string)$w['week'] > $nearCutoff && $plantExecLate < 3;
            $rowCls = ($w['capacity_kg'] ?? 0) <= 0 ? 'table-danger'
              : ($planExpand || $canRelease ? 'table-success'
              : ($planBal <= -50 || $openBal <= -50 ? 'table-warning' : ''));
          ?>
          <tr class="<?= $rowCls ?>">
            <td><?= h_sunday_week($w['week']) ?></td>
            <td class="text-end"><?= number_format($w['open_kg'] ?? 0, 0) ?></td>
            <td class="text-end"><?= number_format($rot, 0) ?></td>
            <td class="text-end fw-bold"><?= number_format($w['capacity_kg'] ?? $w['forecast_kg'], 0) ?></td>
            <td class="text-end"><?= number_format($gcalShown, 0) ?><?= $hasSim ? '<span class="text-muted">＊</span>' : '' ?></td>
            <td class="text-end"><?= h_num($openBal, 0) ?></td>
            <td class="text-end"><?= h_num($planBal, 0) ?></td>
            <td class="text-end"><?= number_format($w['yoy_kg'] ?? 0, 0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="page-sub mb-0">＊＝③のシミュレーション値（DB非書き込み・実際のGCALではありません）</p>
  </div>
  </details>
</div>
<?php forecast_nav('capacity'); ?>
<script>
(() => {
  const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
  if (!window.Chart || !window.GF_CHART) return;
  const G = window.GF_CHART;
  const baseOpt = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: G.legend },
  };

  const brEl = document.getElementById('breakSimChart');
  if (brEl) {
    new Chart(brEl, {
      type: 'line',
      data: {
        labels: <?= json_encode($breakSim['chart']['labels'], JSON_UNESCAPED_UNICODE) ?>,
        datasets: [
          G.dsPlanted('現状（モデル残）', <?= json_encode($breakSim['chart']['baseline_cum']) ?>),
          G.dsSales('シナリオ', <?= json_encode($breakSim['chart']['scenario_cum']) ?>),
        ],
      },
      options: { ...baseOpt, scales: { y: G.yKg(false) } },
      plugins: [G.zeroPlugin],
    });
  }

  const capEl = document.getElementById('capChart');
  if (capEl) {
    new Chart(capEl, {
      type: 'line',
      data: {
        labels,
        datasets: [
          G.dsPlan('計画能力', <?= json_encode($chartCap) ?>),
          G.dsPlanted('定植済（いまの畑）', <?= json_encode($chartOpen) ?>),
          G.dsGcal('GCAL確定', <?= json_encode($chartGcal) ?>),
          G.dsYoy('昨対実績', <?= json_encode($chartYoy) ?>),
        ],
      },
      options: { ...baseOpt, scales: { y: G.yKg(true) } },
      plugins: [G.zeroPlugin],
    });
  }

  const surEl = document.getElementById('surplusChart');
  if (surEl) {
    const surplus = <?= json_encode($chartPlanSurplusSim) ?>;
    new Chart(surEl, {
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: '計画−GCAL',
            data: surplus,
            backgroundColor: surplus.map((v) => (v >= 0 ? G.C.pos : G.C.neg)),
            borderWidth: 0,
            yAxisID: 'y',
          },
          G.dsPlan('計画累計', <?= json_encode($chartCumSurplus) ?>, {
            type: 'line',
            pointRadius: 0,
            yAxisID: 'y',
          }),
          G.dsSales('定植済累計', <?= json_encode($chartOpenCum) ?>, {
            type: 'line',
            borderDash: [5, 3],
            borderWidth: 2,
            pointRadius: 0,
            yAxisID: 'y',
          }),
        ],
      },
      options: {
        ...baseOpt,
        scales: {
          y: Object.assign(G.yKg(true), {
            position: 'left',
            title: { display: true, text: 'kg', font: { size: 10 } },
          }),
        },
      },
      plugins: [G.zeroPlugin],
    });
  }

  const simEl = document.getElementById('simChart');
  if (simEl) {
    new Chart(simEl, {
      type: 'line',
      data: {
        labels,
        datasets: [
          G.dsGcal('GCAL確定', <?= json_encode($chartGcal) ?>),
          G.dsSales(
            <?= json_encode($simMode === 'level' ? '平準化シミュレーション' : 'アラート直載せ', JSON_UNESCAPED_UNICODE) ?>,
            <?= json_encode($chartSim) ?>
          ),
        ],
      },
      options: { ...baseOpt, scales: { y: G.yKg(true) } },
      plugins: [G.zeroPlugin],
    });
  }

  document.querySelectorAll('details.secondary-block').forEach((d) => {
    d.addEventListener('toggle', () => {
      if (!d.open) return;
      d.querySelectorAll('canvas').forEach((c) => {
        const ch = Chart.getChart(c);
        if (ch) ch.resize();
      });
    });
  });
})();
</script>
</body>
</html>
