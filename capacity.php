<?php
/**
 * 需給・営業 — フル回転能力見通し / 昨対 / 営業リコメンド / 破棄
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
  <title>需給・営業</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css?v=20260815d">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">需給・営業</h1>
      <p class="page-sub">営業の材料 · 一時／トレンド／遅れ · 確定出荷はGCAL · 先行きの本線は <a href="inventory.php">予測</a></p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-success py-2"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-danger py-2"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="job-card mb-3" style="border-left:4px solid var(--gf-green)">
    <div class="job-meta">
      収穫後<?= (int)GF_REPLANT_GRACE_DAYS ?>日以内の次定植は自動 · 当週の定植済バッファは売らない · 異常の横断は <a href="alerts.php">経営アラート</a>
      <?php if (!empty($ensure['created'])): ?>
        · <?= htmlspecialchars($ensure['message'], ENT_QUOTES, 'UTF-8') ?>
      <?php endif; ?>
    </div>
    <div class="mt-2 d-flex flex-wrap gap-2">
      <a class="btn btn-sm btn-outline-success" href="inventory.php">収穫予測</a>
      <a class="btn btn-sm btn-outline-success" href="today.php#sec-plant">今日の定植</a>
      <form method="post" class="d-inline ms-auto" onsubmit="return confirm('空き・栽培中ベッドの次定植行を再投入します（GCAL・実績は変わりません）。よろしいですか？');">
        <input type="hidden" name="action" value="continuous_plant">
        <button class="btn btn-sm btn-link text-muted" type="submit">手動で再投入</button>
      </form>
    </div>
  </div>

  <div class="job-card mb-3" style="border-left:4px solid <?= $trustSum['status'] === 'ok' ? 'var(--gf-green)' : ($trustSum['status'] === 'critical' ? 'var(--gf-red)' : 'var(--gf-amber)') ?>">
    <div class="job-meta fw-bold"><?= htmlspecialchars($trustSum['status_label'], ENT_QUOTES, 'UTF-8') ?></div>
    <div class="job-meta">計画どおりなら割れまで <?= (int)$trustSum['runway_weeks'] ?>週<?php if ($trustSum['first_break_week']): ?> · 初回 <?= h_sunday_week($trustSum['first_break_week']) ?><?php endif; ?><?php if ($delayN): ?> · 定植遅れ <?= (int)$delayN ?>床<?php endif; ?></div>
  </div>

  <h2 class="section-title"><?= gf_icon('calendar') ?> 営業アラート</h2>
  <p class="page-sub mb-2">この画面の主目的。ラベルは一時 / トレンド / 遅れ。確定はGCAL。</p>
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

  <div class="stat-row">
    <div class="stat-card <?= $sum['zero_weeks'] ? 'danger' : 'ok' ?>">
      <div class="stat-label">能力0の週</div>
      <div class="stat-value"><?= (int)$sum['zero_weeks'] ?></div>
    </div>
    <div class="stat-card <?= $sum['yoy_miss_weeks'] ? 'warn' : 'ok' ?>">
      <div class="stat-label">昨対割れ週</div>
      <div class="stat-value"><?= (int)$sum['yoy_miss_weeks'] ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">仮想回転</div>
      <div class="stat-value"><?= (int)$sum['sim_cycles'] ?></div>
    </div>
  </div>

  <div class="stat-row mt-2">
    <div class="stat-card" style="background:#e8f5ee">
      <div class="stat-label">拡大案（計画）</div>
      <div class="stat-value"><?= (int)$expandShown ?></div>
    </div>
    <div class="stat-card warn">
      <div class="stat-label">絞り案</div>
      <div class="stat-value"><?= (int)$sum['tighten_n'] ?></div>
    </div>
    <div class="stat-card danger">
      <div class="stat-label">破棄候補</div>
      <div class="stat-value"><?= (int)$sum['discard_n'] ?></div>
    </div>
  </div>

  <p class="page-sub mt-2">
    内訳(先<?= (int)GF_CAPACITY_HORIZON_WEEKS ?>週合計):
    定植済 <?= number_format($sum['open_kg_total'], 0) ?>kg
    + 予定定植 <?= number_format($sum['planned_kg_total'], 0) ?>kg
    + 仮想回転 <?= number_format($sum['rotation_kg_total'], 0) ?>kg
    · 空きベッド <?= (int)$sum['empty_beds'] ?>
    · 想定 <?= (int)$sum['defaults']['days'] ?>日 / <?= (int)$sum['defaults']['yield'] ?>kg
  </p>

  <div class="chart-card">
    <div class="chart-title">① 週ごとの生産能力（本線＝計画）</div>
    <div class="chart-wrap tall"><canvas id="capChart"></canvas></div>
    <p class="page-sub mt-2 mb-0">緑実線=計画能力（収穫後5日で次定植した場合） · 灰点線=いま畑に植わっている分 · 紫=GCAL · 灰実線=昨対。灰が先でゼロに見えるのは定植催促であり、公式予測ではない。</p>
  </div>

  <div class="chart-card">
    <div class="chart-title">② 計画の週次差＋累計（先の余り）</div>
    <div class="chart-wrap tall"><canvas id="surplusChart"></canvas></div>
    <p class="page-sub mt-2 mb-0">
      棒=計画−GCAL（③反映）。緑=計画累計。青破線=定植済累計。どちらも予測の累計余剰が土台。いま植えても届かない週まで緑と青は一致する。
    </p>
  </div>

  <div class="chart-card">
    <div class="chart-title">③ 先々シミュレーション（<?= htmlspecialchars($simModeLabel, ENT_QUOTES, 'UTF-8') ?>）</div>
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
    <p class="page-sub mb-0">営業の読み方: 青＝先方に話してよい出荷ライン。計画の山は定植が回ってからの話。遅れが出たら今日の定植を先に。</p>
  </div>

  <h2 class="section-title"><?= gf_icon('chart') ?> 季節ベース（取引先共有の目安）</h2>
  <p class="page-sub mb-2">二次情報。「●月はこのぐらい」の共有用。計画能力＝回転を実行した場合。</p>
  <div class="table-responsive mb-3">
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

  <h2 class="section-title"><?= gf_icon('alert') ?> 破棄候補（現場で潰す）</h2>
  <p class="page-sub mb-2">床を空けて回転を守る。作業は <a href="today.php#sec-discard">今日</a> でも可。</p>
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

  <h2 class="section-title"><?= gf_icon('calendar') ?> 週次能力表</h2>
  <p class="page-sub mb-2">計画差＝回転を実行した到達点（営業の本線）。定植済差＝いまの畑。GCALは③の「<?= htmlspecialchars($simModeLabel, ENT_QUOTES, 'UTF-8') ?>」を反映。</p>
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
</div>
<?php forecast_nav('capacity'); ?>
<script>
(() => {
  const labels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>;
  if (!window.Chart) return;
  const yKg = { beginAtZero: true, ticks: { callback: v => v + 'kg' } };
  const legend = { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } };

  const capEl = document.getElementById('capChart');
  if (capEl) {
    new Chart(capEl, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: '計画能力', data: <?= json_encode($chartCap) ?>, borderColor: '#1b7a4a', borderWidth: 2.5, tension: 0.25, fill: false },
          { label: '定植済（いまの畑）', data: <?= json_encode($chartOpen) ?>, borderColor: '#9e9e9e', borderDash: [5,3], tension: 0.25, fill: false },
          { label: 'GCAL確定', data: <?= json_encode($chartGcal) ?>, borderColor: '#7b1fa2', borderDash: [4,3], tension: 0.25, fill: false },
          { label: '昨対実績', data: <?= json_encode($chartYoy) ?>, borderColor: '#9e9e9e', tension: 0.25, fill: false, pointRadius: 0 }
        ]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend }, scales: { y: yKg } }
    });
  }

  const surEl = document.getElementById('surplusChart');
  if (surEl) {
    const surplus = <?= json_encode($chartPlanSurplusSim) ?>;
    const openCum = <?= json_encode($chartOpenCum) ?>;
    const cumTarget = <?= json_encode($chartCumSurplus) ?>;
    new Chart(surEl, {
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: '計画−GCAL',
            data: surplus,
            backgroundColor: surplus.map(v => v >= 0 ? 'rgba(27,122,74,0.55)' : 'rgba(196,70,40,0.55)'),
            borderWidth: 0,
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: '計画累計',
            data: cumTarget,
            borderColor: '#1b7a4a',
            borderWidth: 2.5,
            tension: 0.25,
            fill: false,
            pointRadius: 2,
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: '定植済累計',
            data: openCum,
            borderColor: '#1565c0',
            borderDash: [5, 3],
            tension: 0.25,
            fill: false,
            pointRadius: 2,
            yAxisID: 'y'
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend },
        scales: {
          y: {
            position: 'left',
            beginAtZero: true,
            title: { display: true, text: 'kg', font: { size: 10 } },
            ticks: { callback: v => v + 'kg' }
          }
        }
      }
    });
  }

  const simEl = document.getElementById('simChart');
  if (simEl) {
    new Chart(simEl, {
      type: 'line',
      data: {
        labels,
        datasets: [
          { label: 'GCAL確定', data: <?= json_encode($chartGcal) ?>, borderColor: '#7b1fa2', borderDash: [4,3], tension: 0.25, fill: false },
          { label: <?= json_encode($simMode === 'level' ? '平準化シミュレーション' : 'アラート直載せ', JSON_UNESCAPED_UNICODE) ?>, data: <?= json_encode($chartSim) ?>, borderColor: '#1976d2', tension: 0.25, fill: false }
        ]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend }, scales: { y: yKg } }
    });
  }
})();
</script>
</body>
</html>
