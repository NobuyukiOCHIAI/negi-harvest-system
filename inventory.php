<?php
/**
 * 収穫予測ページ（Excel「収穫予測」相当）
 * - 週=日曜起点
 * - ママイキ(出荷残)= その週の出荷予定のうち ship_date > 今日
 *   （出荷当日は収穫済み扱い。当日分は残に含めない）
 * - ママイキ(在庫差)= 累積(収穫予測 − 出荷残)
 * - 過去週も収穫予測>0 または出荷残>0 なら表示（グレー）
 * - 表示上限: 今週起算で3か月先まで
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/gcal_shipments.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/plant_schedule.php';
require_once __DIR__ . '/lib/inventory_trust.php';
require_once __DIR__ . '/lib/supply_ops.php';

function week_label_sunday(string $weekStartDate): string
{
    $ts = strtotime($weekStartDate . ' 12:00:00');
    if ($ts === false) {
        return $weekStartDate;
    }
    return date('Y年n月j日週', $ts);
}

$sync = gcal_ensure_fresh_shipments($link, isset($_GET['force_sync']));
$today = date('Y-m-d');
$currentWeek = gcal_week_start_sunday($today);
$horizonEnd = date('Y-m-d', strtotime('+3 months', strtotime($currentWeek)));

$sql = "
SELECT
  c.id AS cycle_id,
  b.name AS bed_name,
  c.plant_date,
  c.harvest_start,
  pr.pred_days,
  pr.pred_total_kg,
  pr.postproc_total_kg,
  COALESCE(pr.postproc_total_kg, pr.pred_total_kg) AS forecast_kg,
  DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY) AS expected_harvest,
  DATE_SUB(
    DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY),
    INTERVAL (DAYOFWEEK(DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY)) - 1) DAY
  ) AS week_start_date
FROM cycles c
JOIN beds b ON b.id = c.bed_id
JOIN predictions pr
  ON pr.cycle_id = c.id
 AND NOT EXISTS (
       SELECT 1 FROM predictions p2
        WHERE p2.cycle_id = pr.cycle_id
          AND p2.created_at > pr.created_at
     )
WHERE c.harvest_end IS NULL
  AND pr.pred_days IS NOT NULL
ORDER BY week_start_date ASC, b.name ASC, c.id ASC
";

$detailsByWeek = [];
$res = mysqli_query($link, $sql);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $w = $row['week_start_date'];
        if (!isset($detailsByWeek[$w])) {
            $detailsByWeek[$w] = [];
        }
        $detailsByWeek[$w][] = $row;
    }
    mysqli_free_result($res);
}

// 週の出荷コミット（確定=GCALのみ）
$shipCommitByWeek = [];
$shipSourceByWeek = [];
$res = mysqli_query(
    $link,
    "SELECT week_start_date, source, committed_amount_kg
     FROM calendar_shipments
     WHERE source = 'gcal'
     ORDER BY week_start_date ASC"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $w = $row['week_start_date'];
        if (!isset($shipCommitByWeek[$w])) {
            $shipCommitByWeek[$w] = (float)$row['committed_amount_kg'];
            $shipSourceByWeek[$w] = $row['source'];
        }
    }
    mysqli_free_result($res);
}

// ママイキ(出荷残): 明日以降の日次出荷予定を週で合計
// 出荷当日は収穫完了扱い → ship_date = 今日 は残に含めない
$remainingByWeek = [];
$res = mysqli_query(
    $link,
    "SELECT week_start_date, SUM(amount_kg) AS remaining_kg
     FROM calendar_shipment_events
     WHERE ship_date > CURDATE()
     GROUP BY week_start_date"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $remainingByWeek[$row['week_start_date']] = (float)$row['remaining_kg'];
    }
    mysqli_free_result($res);
}

// 日次イベントがある週（当日・過去含む）— plan 全量へのフォールバックを防ぐ
$eventWeeks = [];
$res = mysqli_query(
    $link,
    "SELECT DISTINCT week_start_date FROM calendar_shipment_events"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $eventWeeks[$row['week_start_date']] = true;
    }
    mysqli_free_result($res);
}

// 当日までに出荷済み（参考・当週の減算表示用）
$shippedThroughTodayByWeek = [];
$res = mysqli_query(
    $link,
    "SELECT week_start_date, SUM(amount_kg) AS shipped_kg
     FROM calendar_shipment_events
     WHERE ship_date <= CURDATE()
     GROUP BY week_start_date"
);
if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $shippedThroughTodayByWeek[$row['week_start_date']] = (float)$row['shipped_kg'];
    }
    mysqli_free_result($res);
}

$weeks = array_values(array_unique(array_merge(
    array_keys($detailsByWeek),
    array_keys($shipCommitByWeek),
    array_keys($remainingByWeek)
)));
sort($weeks);

$rows = [];
$surplus = null;
$totalBeds = 0;
$totalForecast = 0.0;
$sumDaysWeighted = 0.0;
$sumAvgYieldWeighted = 0.0;

foreach ($weeks as $w) {
    if ($w > $horizonEnd) {
        continue;
    }

    $beds = $detailsByWeek[$w] ?? [];
    $n = count($beds);
    $sumKg = 0.0;
    $sumDays = 0.0;
    foreach ($beds as $b) {
        $sumKg += (float)$b['forecast_kg'];
        $sumDays += (float)$b['pred_days'];
    }
    $avgDays = $n > 0 ? $sumDays / $n : null;
    $avgKg = $n > 0 ? $sumKg / $n : null;

    $source = $shipSourceByWeek[$w] ?? null;
    $commit = $shipCommitByWeek[$w] ?? null;

    // 日次イベントがある週: 明日以降の合計のみ（当日分は収穫済みで除外）
    // イベント週で残0 → plan の728等に戻さない
    if (isset($eventWeeks[$w])) {
        $shipRemain = (float)($remainingByWeek[$w] ?? 0);
        if ($source === null) {
            $source = 'gcal';
        }
    } elseif ($commit !== null) {
        // イベント無し（または未同期）の plan/manual
        if ($w < $currentWeek) {
            $shipRemain = 0.0;
        } else {
            // 当日までの日次出荷があれば、計画全量から差し引く（出荷当日=収穫済）
            $shipped = (float)($shippedThroughTodayByWeek[$w] ?? 0);
            $shipRemain = max(0.0, (float)$commit - $shipped);
        }
    } else {
        $shipRemain = null;
    }

    // 収穫予測0 かつ 出荷残0/なし → 非表示
    if ($sumKg <= 0.0 && ($shipRemain === null || $shipRemain <= 0.0)) {
        continue;
    }

    if ($surplus === null) {
        $surplus = $sumKg - (float)($shipRemain ?? 0);
    } else {
        $surplus = $surplus + $sumKg - (float)($shipRemain ?? 0);
    }

    $rows[] = [
        'week_start_date' => $w,
        'is_elapsed' => ($w < $currentWeek),
        'is_current' => ($w === $currentWeek),
        'beds_count' => $n,
        'avg_days' => $avgDays,
        'avg_kg' => $avgKg,
        'forecast_kg' => $sumKg,
        'ship_kg' => $shipRemain,
        'ship_source' => $source,
        'surplus_kg' => $surplus,
        'details' => $beds,
    ];

    $totalBeds += $n;
    $totalForecast += $sumKg;
    $sumDaysWeighted += $sumDays;
    $sumAvgYieldWeighted += $sumKg;
}

$grandAvgDays = $totalBeds > 0 ? $sumDaysWeighted / $totalBeds : null;
$grandAvgKg = $totalBeds > 0 ? $sumAvgYieldWeighted / $totalBeds : null;

$chartLabels = [];
$chartFc = [];
$chartShip = [];
$chartSurplus = [];
$chartCurrentIdx = null;
foreach (array_slice($rows, 0, 10) as $ci => $cr) {
    $chartLabels[] = date('n/j', strtotime($cr['week_start_date']));
    $chartFc[] = round((float)$cr['forecast_kg'], 1);
    $chartShip[] = $cr['ship_kg'] === null ? 0 : round((float)$cr['ship_kg'], 1);
    $chartSurplus[] = round((float)$cr['surplus_kg'], 1);
    if (!empty($cr['is_current'])) {
        $chartCurrentIdx = $ci;
    }
}
$shortageWeeks = array_values(array_filter(
    $rows,
    static fn($r) => !$r['is_elapsed'] && $r['surplus_kg'] < 0
));
$negSurplusN = count($shortageWeeks);
$nextShort = $shortageWeeks[0] ?? null;
$nextShortLabel = $nextShort
    ? date('n/j', strtotime($nextShort['week_start_date']))
    : 'なし';
$nextShortSurplus = $nextShort !== null ? (float)$nextShort['surplus_kg'] : null;

$sim = plant_schedule_simulate($link, 12);
$plannedN = (int)$sim['planned_n'];

$trust = trust_outlook_bundle($link, 16);
$trustSum = $trust['summary'];
$trustOpen = $trust['summary_open_only'];
$trustActions = $trust['actions'];
$trustCumLabels = [];
$trustCumRot = [];
$trustCumOpen = [];
foreach (array_slice($trust['with_rotation'], 0, 14) as $tw) {
    $trustCumLabels[] = date('n/j', strtotime($tw['week']));
    $trustCumRot[] = (float)$tw['cum_surplus_kg'];
}
foreach (array_slice($trust['open_only'], 0, 14) as $tw) {
    $trustCumOpen[] = (float)$tw['cum_surplus_kg'];
}
$trustStatusClass = [
    'ok' => 'ok',
    'watch' => 'warn',
    'warn' => 'warn',
    'critical' => 'danger',
][$trustSum['status']] ?? 'warn';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>収穫予測</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">収穫予測</h1>
      <p class="page-sub">累計在庫 · 確定出荷=GCAL · <a href="capacity.php">需給</a></p>
    </div>
    <div class="actions">
      <a class="btn btn-sm btn-outline-primary" href="?force_sync=1">再取込</a>
    </div>
  </div>

  <?php if (!empty($sync['error'])): ?>
    <div class="alert alert-warning py-2">出荷同期失敗: <?= htmlspecialchars($sync['error'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php else: ?>
    <p class="page-sub mb-2">
      <?= $sync['skipped'] ? 'キャッシュ' : '更新済' ?>
      <?php if (!empty($sync['synced_at'])): ?> · <?= htmlspecialchars($sync['synced_at'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
    </p>
  <?php endif; ?>

  <div class="job-card mb-3 py-2" style="border-left:4px solid <?= $trustSum['status'] === 'ok' ? 'var(--gf-green)' : ($trustSum['status'] === 'critical' ? 'var(--gf-red)' : 'var(--gf-amber)') ?>">
    <div class="job-meta fw-bold"><?= htmlspecialchars($trustSum['status_label'], ENT_QUOTES, 'UTF-8') ?></div>
    <div class="job-meta">割れまで <?= (int)$trustSum['runway_weeks'] ?>週（フル） / <?= (int)$trustOpen['runway_weeks'] ?>週（いまの株）</div>
  </div>

  <?php if ($trustActions): ?>
    <h2 class="section-title"><?= gf_icon('calendar') ?> 営業アラート</h2>
    <p class="page-sub mb-2">要約のみ · 交渉・シミュレーションは <a href="capacity.php">需給</a></p>
    <?php foreach ($trustActions as $a):
      $isSpot = ($a['kind'] ?? '') === 'spot' || ($a['type'] ?? '') === 'spot_surplus';
      $isTighten = in_array(($a['type'] ?? ''), ['commit_tighten', 'trend_tighten'], true);
      $line = $a['short_line'] ?? supply_alert_short_line($a);
    ?>
      <div class="job-card py-2 <?= $isTighten || $isSpot ? 'risk' : '' ?>">
        <div class="fw-bold">
          <span class="badge <?= $isSpot ? 'bg-warning text-dark' : ($isTighten ? 'bg-danger' : 'bg-success') ?> me-1">
            <?= $isSpot ? '一時' : 'トレンド' ?>
          </span>
          <?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="stat-row">
    <div class="stat-card <?= $trustStatusClass ?>">
      <?= gf_icon('alert', 'stat-ico') ?>
      <div class="stat-label">割れまで</div>
      <div class="stat-value"><?= (int)$trustSum['runway_weeks'] ?></div>
      <div class="stat-sub">週（フル回転）</div>
    </div>
    <div class="stat-card <?= $negSurplusN ? 'danger' : 'ok' ?>">
      <?= gf_icon('chart', 'stat-ico') ?>
      <div class="stat-label">週表の累計不足</div>
      <div class="stat-value"><?= $negSurplusN ?></div>
      <div class="stat-sub">オープン予測ベース</div>
    </div>
    <a href="plan.php" class="stat-card stat-link <?= $plannedN ? 'ok' : ($negSurplusN ? 'warn' : '') ?>">
      <?= gf_icon('plant', 'stat-ico') ?>
      <div class="stat-label">定植予定</div>
      <div class="stat-value"><?= $plannedN ?></div>
      <div class="stat-sub">計画中のベッド数</div>
    </a>
  </div>

  <?php if ($trustCumLabels): ?>
  <div class="chart-card">
    <div class="chart-title">累計在庫の先行き（信頼の核）</div>
    <div class="chart-wrap tall"><canvas id="trustCumChart"></canvas></div>
    <p class="page-sub mt-2 mb-0">緑=常時回転込み累計 · 灰=いまの株だけ累計 · ゼロ割れが仲卸への先行交渉ポイント</p>
  </div>
  <?php endif; ?>

  <?php if ($chartLabels): ?>
  <div class="chart-card">
    <div class="chart-title">直近週 · オープン予測 / 残出荷 / 累計余剰</div>
    <div class="chart-wrap tall">
      <canvas id="invChart"></canvas>
    </div>
    <p class="page-sub mt-2 mb-0">破線の累計余剰が本線。能力・シミュレーションは <a href="capacity.php">需給</a>。</p>
  </div>
  <?php endif; ?>

  <h2 class="section-title"><?= gf_icon('chart') ?> 週次明細（オープン予測）</h2>
  <!-- モバイル: 週カード -->
  <div class="inv-week-cards mobile-only">
    <?php if (!$rows): ?>
      <p class="text-muted">表示可能な週がありません</p>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r):
      $sid = 'mw' . $i;
      $surplusClass = $r['surplus_kg'] < 0 ? 'surplus-neg' : 'surplus-pos';
      $cardCls = $r['is_elapsed'] ? 'elapsed' : '';
      if (!empty($r['is_current'])) {
          $cardCls = trim($cardCls . ' current');
      }
      ?>
      <div class="week-card <?= $cardCls ?>"<?= !empty($r['is_current']) ? ' id="week-current"' : '' ?>>
        <div class="d-flex justify-content-between align-items-start">
          <div class="wk-title" data-bs-toggle="collapse" data-bs-target="#<?= $sid ?>" style="cursor:pointer">
            <?= htmlspecialchars(week_label_sunday($r['week_start_date']), ENT_QUOTES, 'UTF-8') ?>
            <?php if (!empty($r['is_current'])): ?>
              <span class="badge-this-week">当週</span>
            <?php endif; ?>
          </div>
          <span class="badge-status <?= $r['surplus_kg'] < 0 ? 'late' : 'growing' ?>">
            余剰 <?= number_format($r['surplus_kg'], 0) ?>
          </span>
        </div>
        <div class="metrics">
          <div class="metric"><div class="m-label">収穫予測</div><div class="m-val"><?= number_format($r['forecast_kg'], 0) ?>kg</div></div>
          <div class="metric"><div class="m-label">残出荷</div><div class="m-val"><?= $r['ship_kg'] === null ? '—' : number_format($r['ship_kg'], 0) . 'kg' ?></div></div>
          <div class="metric"><div class="m-label">ベッド</div><div class="m-val"><?= (int)$r['beds_count'] ?></div></div>
          <div class="metric"><div class="m-label">平均kg</div><div class="m-val"><?= $r['avg_kg'] === null ? '—' : number_format($r['avg_kg'], 0) ?></div></div>
        </div>
        <div class="collapse mt-2" id="<?= $sid ?>">
          <?php foreach ($r['details'] as $d): ?>
            <a class="chip mb-1" href="cycle.php?id=<?= (int)$d['cycle_id'] ?>">
              <?= htmlspecialchars($d['bed_name'], ENT_QUOTES, 'UTF-8') ?>
              · <?= number_format((float)$d['forecast_kg'], 0) ?>kg
            </a>
          <?php endforeach; ?>
          <?php if (!$r['details']): ?><span class="text-muted small">明細なし</span><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- デスクトップ: 表 -->
  <div class="table-responsive desktop-only mb-3">
    <table class="table table-sm align-middle">
      <thead>
        <tr>
          <th>収穫週</th>
          <th class="text-end">個数</th>
          <th class="text-end">平均日</th>
          <th class="text-end">平均kg</th>
          <th class="text-end">予測</th>
          <th class="text-end">残出荷</th>
          <th class="text-end">余剰</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $i => $r):
        $sid = 'w' . $i;
        $surplusClass = $r['surplus_kg'] < 0 ? 'surplus-neg' : 'surplus-pos';
        $rowCls = $r['is_elapsed'] ? 'row-elapsed' : '';
        if (!empty($r['is_current'])) {
            $rowCls = trim($rowCls . ' row-current');
        }
      ?>
        <tr class="<?= $rowCls ?>"<?= !empty($r['is_current']) ? ' id="week-current-desk"' : '' ?>>
          <td class="week-toggle" data-bs-toggle="collapse" data-bs-target="#<?= $sid ?>" style="cursor:pointer">
            <?= htmlspecialchars(week_label_sunday($r['week_start_date']), ENT_QUOTES, 'UTF-8') ?>
            <?php if (!empty($r['is_current'])): ?>
              <span class="badge-this-week">当週</span>
            <?php endif; ?>
          </td>
          <td class="text-end"><?= (int)$r['beds_count'] ?></td>
          <td class="text-end"><?= $r['avg_days'] === null ? '—' : number_format($r['avg_days'], 1) ?></td>
          <td class="text-end"><?= $r['avg_kg'] === null ? '—' : number_format($r['avg_kg'], 1) ?></td>
          <td class="text-end fw-semibold"><?= number_format($r['forecast_kg'], 1) ?></td>
          <td class="text-end"><?= $r['ship_kg'] === null ? '—' : number_format($r['ship_kg'], 1) ?></td>
          <td class="text-end <?= $surplusClass ?>"><?= number_format($r['surplus_kg'], 1) ?></td>
        </tr>
        <tr class="collapse" id="<?= $sid ?>">
          <td colspan="7" class="p-2 bg-light">
            <?php foreach ($r['details'] as $d): ?>
              <a href="cycle.php?id=<?= (int)$d['cycle_id'] ?>"><?= htmlspecialchars($d['bed_name'], ENT_QUOTES, 'UTF-8') ?></a>
              <?= number_format((float)$d['forecast_kg'], 1) ?>kg ·
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php forecast_nav('inventory'); ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($trustCumLabels): ?>
<script>
(() => {
  const el = document.getElementById('trustCumChart');
  if (!el || !window.Chart) return;
  new Chart(el, {
    type: 'line',
    data: {
      labels: <?= json_encode($trustCumLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [
        { label: '累計在庫（常時回転込み）', data: <?= json_encode($trustCumRot) ?>, borderColor: '#1b7a4a', tension: 0.25, fill: false },
        { label: '累計在庫（いまの株だけ）', data: <?= json_encode($trustCumOpen) ?>, borderColor: '#9e9e9e', borderDash: [5,4], tension: 0.25, fill: false }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
      scales: { y: { ticks: { callback: v => v + 'kg' } } }
    }
  });
})();
</script>
<?php endif; ?>
<?php if ($chartLabels): ?>
<script>
(() => {
  const currentIdx = <?= $chartCurrentIdx === null ? 'null' : (int)$chartCurrentIdx ?>;
  const pointRadius = <?= json_encode(array_map(static fn($i) => $i === $chartCurrentIdx ? 6 : 2, array_keys($chartLabels))) ?>;
  const pointBorder = <?= json_encode(array_map(static fn($i) => $i === $chartCurrentIdx ? '#1b7a4a' : undefined, array_keys($chartLabels))) ?>;
  new Chart(document.getElementById('invChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [
        { label: '収穫予測', data: <?= json_encode($chartFc) ?>, borderColor: '#1b7a4a', backgroundColor: 'rgba(27,122,74,0.15)', fill: true, tension: 0.25, pointRadius, pointHoverRadius: 7 },
        { label: '残出荷', data: <?= json_encode($chartShip) ?>, borderColor: '#c47a00', backgroundColor: 'rgba(196,122,0,0.12)', fill: true, tension: 0.25, pointRadius: 2 },
        { label: '余剰', data: <?= json_encode($chartSurplus) ?>, borderColor: '#2c5aa0', borderDash: [4,3], tension: 0.25, pointRadius: 2 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
        annotation: undefined
      },
      scales: {
        y: { ticks: { font: { size: 10 } } },
        x: {
          ticks: {
            font: (ctx) => ({
              size: 10,
              weight: currentIdx !== null && ctx.index === currentIdx ? 'bold' : 'normal'
            }),
            color: (ctx) => (currentIdx !== null && ctx.index === currentIdx ? '#1b7a4a' : undefined)
          }
        }
      }
    }
  });
})();
</script>
<?php endif; ?>
<script>
  // 営業の最重要（累計在庫・先行調整）を先に見せるため、
  // 当週カードへの自動スクロールは行わない。
</script>
</body>
</html>
