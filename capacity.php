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
$trust = trust_outlook_bundle($link, 20);
$trustSum = $trust['summary'];
$trustActions = $trust['actions'];
$season = supply_seasonal_baseline($link, 6);
$dual = supply_dual_week_lines($link, 14);

$chartLabels = [];
$chartGcal = [];
$chartCap = [];
$chartYoy = [];
$chartSurplus = [];
$weekSlice = array_slice($weeks, 0, 14);
foreach ($weekSlice as $i => $w) {
    $chartLabels[] = date('n/j', strtotime($w['week']));
    $gcal = (float)($w['gcal_kg'] ?? ($dual[$i]['gcal_kg'] ?? 0));
    $cap = (float)($w['capacity_kg'] ?? $w['forecast_kg']);
    $chartGcal[] = $gcal;
    $chartCap[] = $cap;
    $chartYoy[] = $w['yoy_kg'] ?? 0;
    $chartSurplus[] = round(isset($w['balance_kg']) ? (float)$w['balance_kg'] : ($cap - $gcal), 1);
}
$cumCarry = supply_inventory_style_cum_carry($link);
$chartCumSurplus = supply_cum_surplus_with_carry($chartSurplus, $cumCarry);

$simMode = ($_GET['sim'] ?? 'alert') === 'level' ? 'level' : 'alert';
$levelEndParam = (string)($_GET['level_end'] ?? '');
if (!in_array($levelEndParam, ['', 'sept', 'horizon'], true)) {
    $levelEndParam = 'sept';
}
$levelEndArg = null;
if ($simMode === 'level') {
    $levelEndArg = ($levelEndParam === 'horizon') ? 'horizon' : null; // null = Sept end default
}
$chartSimAlert = supply_sim_commit_series($weekSlice, $trustActions);
$levelMeta = supply_sim_level_series($weekSlice, $trustActions, $levelEndArg);
$chartSim = $simMode === 'level' ? $levelMeta['series'] : $chartSimAlert;
$simNote = $simMode === 'level'
    ? $levelMeta['note']
    : '紫=いまのGCAL · 青=アラート直載せの一次案（跳ねやすい）。DB非書き込み。';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>需給・営業</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">需給・営業</h1>
      <p class="page-sub">①能力 · ②累計余剰 · ③シミュレーション</p>
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
      常時フル稼働 · 確定コミットはGCALのみ · 一時=スポット / トレンド=ベース交渉
      <?php if (!empty($ensure['created'])): ?>
        · <?= htmlspecialchars($ensure['message'], ENT_QUOTES, 'UTF-8') ?>
      <?php endif; ?>
    </div>
    <div class="mt-2 d-flex flex-wrap gap-2">
      <form method="post" class="d-inline" onsubmit="return confirm('空きベッドの定植計画行を再投入します（GCAL・実績は変わりません）。よろしいですか？');">
        <input type="hidden" name="action" value="continuous_plant">
        <button class="btn btn-sm btn-outline-success" type="submit">常時回転を再投入</button>
      </form>
      <a class="btn btn-sm btn-outline-success" href="inventory.php">収穫予測</a>
      <a class="btn btn-sm btn-outline-success" href="agent.php">監視エージェント</a>
    </div>
  </div>

  <div class="job-card mb-3" style="border-left:4px solid <?= $trustSum['status'] === 'ok' ? 'var(--gf-green)' : ($trustSum['status'] === 'critical' ? 'var(--gf-red)' : 'var(--gf-amber)') ?>">
    <div class="job-meta fw-bold"><?= htmlspecialchars($trustSum['status_label'], ENT_QUOTES, 'UTF-8') ?></div>
    <div class="job-meta">割れまで <?= (int)$trustSum['runway_weeks'] ?>週<?php if ($trustSum['first_break_week']): ?> · 初回 <?= h_sunday_week($trustSum['first_break_week']) ?><?php endif; ?></div>
  </div>

  <?php if ($trustActions): ?>
  <h2 class="section-title"><?= gf_icon('calendar') ?> 営業アラート</h2>
  <p class="page-sub mb-2">時系列 · ●月●週 ±kg。確定はGCAL更新で反映。</p>
  <?php foreach ($trustActions as $a):
    $isSpot = ($a['kind'] ?? '') === 'spot' || ($a['type'] ?? '') === 'spot_surplus';
    $isTighten = ($a['type'] ?? '') === 'commit_tighten' || ($a['type'] ?? '') === 'trend_tighten';
    $line = $a['short_line'] ?? supply_alert_short_line($a);
  ?>
    <div class="job-card py-2 <?= $isTighten || $isSpot ? 'risk' : '' ?>">
      <div class="d-flex justify-content-between align-items-center gap-2">
        <div class="fw-bold">
          <span class="badge <?= $isSpot ? 'bg-warning text-dark' : ($isTighten ? 'bg-danger' : 'bg-success') ?> me-1">
            <?= $isSpot ? '一時' : 'トレンド' ?>
          </span>
          <?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <h2 class="section-title"><?= gf_icon('chart') ?> 季節ベース（取引先共有の目安）</h2>
  <p class="page-sub mb-2">「●月はこのぐらい」の共有用。昨対実績 / 計画能力 / GCAL確定。</p>
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
      <div class="stat-label">拡大案</div>
      <div class="stat-value"><?= (int)$sum['expand_n'] ?></div>
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
    オープン <?= number_format($sum['open_kg_total'], 0) ?>kg
    + 予定定植 <?= number_format($sum['planned_kg_total'], 0) ?>kg
    + 仮想回転 <?= number_format($sum['rotation_kg_total'], 0) ?>kg
    · 空きベッド <?= (int)$sum['empty_beds'] ?>
    · 想定 <?= (int)$sum['defaults']['days'] ?>日 / <?= (int)$sum['defaults']['yield'] ?>kg
  </p>

  <div class="chart-card">
    <div class="chart-title">① 週ごとの生産能力（システム計画）</div>
    <div class="chart-wrap tall"><canvas id="capChart"></canvas></div>
    <p class="page-sub mt-2 mb-0">緑=計画能力 · 紫点線=GCAL確定 · 灰=昨対実績。緑−紫の差が週次の余り／不足。</p>
  </div>

  <div class="chart-card">
    <div class="chart-title">② 週次余剰＋累計余剰</div>
    <div class="chart-wrap tall"><canvas id="surplusChart"></canvas></div>
    <p class="page-sub mt-2 mb-0">
      棒=その週の余り／不足。破線=累計余剰（経過週の繰越 <?= number_format($cumCarry, 0) ?>kg を引き継ぎ · 予測の直近週と同系）。
    </p>
  </div>

  <div class="chart-card">
    <div class="chart-title">③ 先々シミュレーション（<?= $simMode === 'level' ? 'トレンド平準化' : 'アラート直載せ' ?>）</div>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <a class="btn btn-sm <?= $simMode === 'alert' ? 'btn-primary' : 'btn-outline-secondary' ?>"
         href="?sim=alert">① アラート直載せ</a>
      <a class="btn btn-sm <?= $simMode === 'level' && $levelEndParam !== 'horizon' ? 'btn-primary' : 'btn-outline-secondary' ?>"
         href="?sim=level&amp;level_end=sept">② トレンド平準化（〜9月末）</a>
      <a class="btn btn-sm <?= $simMode === 'level' && $levelEndParam === 'horizon' ? 'btn-primary' : 'btn-outline-secondary' ?>"
         href="?sim=level&amp;level_end=horizon">②′ 平準化（ホライズン末まで）</a>
    </div>
    <div class="chart-wrap tall"><canvas id="simChart"></canvas></div>
    <p class="page-sub mt-2 mb-0"><?= htmlspecialchars($simNote, ENT_QUOTES, 'UTF-8') ?></p>
    <p class="page-sub mb-0">営業の読み方: 青＝先方に話してよい出荷ラインの一次案。①は短期跳ね、②は均して上げたまま維持。</p>
  </div>

  <h2 class="section-title"><?= gf_icon('alert') ?> 破棄候補</h2>
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
  <div class="table-responsive">
    <table class="table table-sm align-middle bg-white shadow-sm">
      <thead>
        <tr>
          <th>週</th>
          <th class="text-end">オープン</th>
          <th class="text-end">回転+</th>
          <th class="text-end">能力</th>
          <th class="text-end">GCAL</th>
          <th class="text-end">余剰</th>
          <th class="text-end">昨対</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($weeks as $w): ?>
          <?php
            $rot = ($w['rotation_kg'] ?? 0) + ($w['planned_kg'] ?? 0);
            $sig = $w['signal'] ?? 'flat';
            $rowCls = ($w['capacity_kg'] ?? 0) <= 0 ? 'table-danger'
              : ((($w['yoy_signal'] ?? '') === 'yoy_miss') ? 'table-warning'
              : ($sig === 'expand' ? 'table-success' : ($sig === 'tighten' ? 'table-warning' : '')));
          ?>
          <tr class="<?= $rowCls ?>">
            <td><?= htmlspecialchars(h_month_week($w['week']), ENT_QUOTES, 'UTF-8') ?></td>
            <td class="text-end"><?= number_format($w['open_kg'] ?? 0, 0) ?></td>
            <td class="text-end"><?= number_format($rot, 0) ?></td>
            <td class="text-end fw-bold"><?= number_format($w['capacity_kg'] ?? $w['forecast_kg'], 0) ?></td>
            <td class="text-end"><?= number_format($w['gcal_kg'] ?? $w['ship_kg'], 0) ?></td>
            <?php $bal = isset($w['balance_kg']) ? (float)$w['balance_kg'] : ((float)($w['capacity_kg'] ?? $w['forecast_kg']) - (float)($w['gcal_kg'] ?? $w['ship_kg'])); ?>
            <td class="text-end <?= $bal < 0 ? 'text-danger' : '' ?>"><?= number_format($bal, 0) ?></td>
            <td class="text-end"><?= number_format($w['yoy_kg'] ?? 0, 0) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
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
          { label: 'システム計画(能力)', data: <?= json_encode($chartCap) ?>, borderColor: '#1b7a4a', tension: 0.25, fill: false },
          { label: 'GCAL確定', data: <?= json_encode($chartGcal) ?>, borderColor: '#7b1fa2', borderDash: [4,3], tension: 0.25, fill: false },
          { label: '昨対実績', data: <?= json_encode($chartYoy) ?>, borderColor: '#9e9e9e', tension: 0.25, fill: false, pointRadius: 0 }
        ]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend }, scales: { y: yKg } }
    });
  }

  const surEl = document.getElementById('surplusChart');
  if (surEl) {
    const surplus = <?= json_encode($chartSurplus) ?>;
    const cum = <?= json_encode($chartCumSurplus) ?>;
    new Chart(surEl, {
      data: {
        labels,
        datasets: [
          {
            type: 'bar',
            label: '週次余剰',
            data: surplus,
            backgroundColor: surplus.map(v => v >= 0 ? 'rgba(27,122,74,0.55)' : 'rgba(196,70,40,0.55)'),
            borderWidth: 0,
            yAxisID: 'y'
          },
          {
            type: 'line',
            label: '累計余剰',
            data: cum,
            borderColor: '#1565c0',
            borderDash: [5, 3],
            tension: 0.25,
            fill: false,
            pointRadius: 2,
            yAxisID: 'y1'
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
            title: { display: true, text: '週次', font: { size: 10 } },
            ticks: { callback: v => v + 'kg' }
          },
          y1: {
            position: 'right',
            grid: { drawOnChartArea: false },
            title: { display: true, text: '累計', font: { size: 10 } },
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
