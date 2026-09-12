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
require_once __DIR__ . '/lib/weather_ops.php';
require_once __DIR__ . '/lib/promise_capacity.php';

/** 週カード／表のベッド明細（栽培中=未完了。予定残=有効予測−既収穫。有効予測=⑤postproc優先） */
function inv_cycle_list_html(array $details): string
{
    $rows = [];
    foreach ($details as $d) {
        // forecast_kg = COALESCE(postproc_total_kg, pred_total_kg)。週合計と同じ基準。
        $pred = (float)($d['forecast_kg'] ?? $d['pred_total_kg'] ?? 0);
        $got = (float)($d['harvested_kg'] ?? 0);
        $d['remain_kg'] = max(0, (int)round($pred - $got));
        $rows[] = $d;
    }
    if (!$rows) {
        return '<p class="text-muted mb-0">未収穫のベッドはありません</p>';
    }
    $html = '<table class="inv-cycle-list"><thead><tr>';
    $html .= '<th>ベッド名</th><th>収穫予定日</th><th class="text-end">収穫予定残</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $d) {
        $href = 'bed_cycles.php?bed_id=' . (int)$d['bed_id'];
        $html .= '<tr>';
        $html .= '<td><a href="' . $href . '">' . htmlspecialchars((string)$d['bed_name'], ENT_QUOTES, 'UTF-8') . '</a></td>';
        $html .= '<td>' . h_ymd($d['expected_harvest'] ?? null) . '</td>';
        $html .= '<td class="text-end">' . htmlspecialchars((string)$d['remain_kg'], ENT_QUOTES, 'UTF-8') . 'kg</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

$sync = gcal_ensure_fresh_shipments($link, isset($_GET['force_sync']));
$today = date('Y-m-d');
$currentWeek = gcal_week_start_sunday($today);
$horizonEnd = date('Y-m-d', strtotime('+3 months', strtotime($currentWeek)));

$sql = "
SELECT
  c.id AS cycle_id,
  c.bed_id,
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
  ) AS week_start_date,
  (SELECT COALESCE(SUM(h.harvest_kg),0) FROM harvests h WHERE h.cycle_id = c.id) AS harvested_kg
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
  AND b.active = 1
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
    $chartLabels[] = format_sunday_week($cr['week_start_date']);
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
    ? format_sunday_week($nextShort['week_start_date'])
    : 'なし';
$nextShortSurplus = $nextShort !== null ? (float)$nextShort['surplus_kg'] : null;

$trust = trust_outlook_bundle($link, 16);
$trustCumLabels = [];
$trustCumRot = [];
$trustCumOpen = [];
foreach (array_slice($trust['with_rotation'], 0, 14) as $tw) {
    $trustCumLabels[] = format_sunday_week($tw['week']);
    $trustCumRot[] = (float)$tw['cum_surplus_kg'];
}
foreach (array_slice($trust['open_only'], 0, 14) as $tw) {
    $trustCumOpen[] = (float)$tw['cum_surplus_kg'];
}
$promiseSum = gf_promise_vs_capacity_summary($link, 8);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>収穫予測</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css?v=20260912c">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="js/gf-chart-theme.js?v=20260912c"></script>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">収穫予測</h1>
      <p class="page-sub">先の過不足を見通す · 本線は計画どおり定植した場合（緑） · 営業判断は <a href="capacity.php">需給</a></p>
    </div>
    <div class="actions">
      <a class="btn btn-sm btn-outline-primary" href="?force_sync=1">再取込</a>
    </div>
  </div>

  <?= gf_weather_stale_banner_html($link) ?>
  <?php if (!empty($sync['error'])): ?>
    <div class="alert alert-warning py-2">出荷同期失敗: <?= htmlspecialchars($sync['error'], ENT_QUOTES, 'UTF-8') ?></div>
  <?php else: ?>
    <p class="page-sub mb-2">
      <?= $sync['skipped'] ? 'キャッシュ' : '更新済' ?>
      <?php if (!empty($sync['synced_at'])): ?> · <?= htmlspecialchars($sync['synced_at'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
    </p>
  <?php endif; ?>

  <?= gf_promise_vs_capacity_card_html($promiseSum, 'capacity.php#sec-outlook') ?>

  <?php if ($trustCumLabels): ?>
  <div class="chart-card primary">
    <div class="chart-title">累計在庫の先行き（本線）</div>
    <p class="page-sub mb-2"><span class="chart-swatch plan"></span>計画どおり · <span class="chart-swatch planted"></span>いまの畑のまま</p>
    <div class="chart-wrap tall"><canvas id="trustCumChart"></canvas></div>
    <p class="page-sub mt-2 mb-0">この画面の主チャート。緑=計画どおりに定植した場合 · 灰破線=いまの畑のまま（植えないと先でゼロに見える＝定植催促。公式予測ではない）。</p>
  </div>
  <?php endif; ?>

  <?php if ($chartLabels): ?>
  <div class="chart-card">
    <div class="chart-title">直近週 · 定植済予測 / 残出荷 / 累計余剰</div>
    <p class="page-sub mb-2">二次。いま畑に植わっている分。能力・拡大案は <a href="capacity.php">需給</a>。</p>
    <div class="chart-wrap tall">
      <canvas id="invChart"></canvas>
    </div>
  </div>
  <?php endif; ?>

  <h2 class="section-title"><?= gf_icon('chart') ?> 週次明細（定植済予測）</h2>
  <!-- モバイル: 週カード -->
  <div class="inv-week-cards mobile-only">
    <?php if (!$rows): ?>
      <p class="text-muted">表示可能な週がありません</p>
    <?php endif; ?>
    <?php foreach ($rows as $i => $r):
      $sid = 'mw' . $i;
      $cardCls = $r['is_elapsed'] ? 'elapsed' : '';
      if (!empty($r['is_current'])) {
          $cardCls = trim($cardCls . ' current');
      }
      ?>
      <div class="week-card <?= $cardCls ?>"<?= !empty($r['is_current']) ? ' id="week-current"' : '' ?>>
        <div class="d-flex justify-content-between align-items-start">
          <div class="wk-title">
            <?= h_sunday_week($r['week_start_date']) ?>
            <?php if (!empty($r['is_current'])): ?>
              <span class="badge-this-week">当週</span>
            <?php endif; ?>
          </div>
          <span class="badge-status <?= $r['surplus_kg'] < 0 ? 'late' : 'growing' ?>">
            余剰 <?= h_num($r['surplus_kg'], 0) ?>
          </span>
        </div>
        <div class="metrics">
          <div class="metric"><div class="m-label">定植済予測</div><div class="m-val"><?= number_format($r['forecast_kg'], 0) ?>kg</div></div>
          <div class="metric"><div class="m-label">残出荷</div><div class="m-val"><?= $r['ship_kg'] === null ? '—' : number_format($r['ship_kg'], 0) . 'kg' ?></div></div>
          <button type="button" class="metric metric-tap" data-bs-toggle="collapse" data-bs-target="#<?= $sid ?>"
            <?= $r['beds_count'] > 0 ? '' : 'disabled' ?> aria-expanded="false" aria-controls="<?= $sid ?>">
            <div class="m-label">未収穫ベッド</div>
            <div class="m-val"><?= (int)$r['beds_count'] ?></div>
          </button>
          <div class="metric"><div class="m-label">平均kg</div><div class="m-val"><?= $r['avg_kg'] === null ? '—' : number_format($r['avg_kg'], 0) ?></div></div>
        </div>
        <div class="collapse mt-2" id="<?= $sid ?>">
          <?= inv_cycle_list_html($r['details']) ?>
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
          <th class="text-end">未収穫ベッド</th>
          <th class="text-end">平均日</th>
          <th class="text-end">平均kg</th>
          <th class="text-end">定植済予測</th>
          <th class="text-end">残出荷</th>
          <th class="text-end">余剰</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $i => $r):
        $sid = 'w' . $i;
        $rowCls = $r['is_elapsed'] ? 'row-elapsed' : '';
        if (!empty($r['is_current'])) {
            $rowCls = trim($rowCls . ' row-current');
        }
      ?>
        <tr class="<?= $rowCls ?>"<?= !empty($r['is_current']) ? ' id="week-current-desk"' : '' ?>>
          <td class="week-toggle">
            <?= h_sunday_week($r['week_start_date']) ?>
            <?php if (!empty($r['is_current'])): ?>
              <span class="badge-this-week">当週</span>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <?php if ($r['beds_count'] > 0): ?>
              <button type="button" class="btn btn-link p-0 fw-bold inv-bed-count" data-bs-toggle="collapse" data-bs-target="#<?= $sid ?>" aria-expanded="false" aria-controls="<?= $sid ?>">
                <?= (int)$r['beds_count'] ?>
              </button>
            <?php else: ?>
              0
            <?php endif; ?>
          </td>
          <td class="text-end"><?= $r['avg_days'] === null ? '—' : number_format($r['avg_days'], 1) ?></td>
          <td class="text-end"><?= $r['avg_kg'] === null ? '—' : number_format($r['avg_kg'], 1) ?></td>
          <td class="text-end fw-semibold"><?= number_format($r['forecast_kg'], 1) ?></td>
          <td class="text-end"><?= $r['ship_kg'] === null ? '—' : number_format($r['ship_kg'], 1) ?></td>
          <td class="text-end <?= $r['surplus_kg'] < 0 ? 'surplus-neg' : 'surplus-pos' ?>"><?= h_num($r['surplus_kg'], 1) ?></td>
        </tr>
        <tr class="collapse" id="<?= $sid ?>">
          <td colspan="7" class="p-2 bg-light">
            <?= inv_cycle_list_html($r['details']) ?>
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
  if (!el || !window.Chart || !window.GF_CHART) return;
  const G = window.GF_CHART;
  new Chart(el, {
    type: 'line',
    data: {
      labels: <?= json_encode($trustCumLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [
        G.dsPlan('累計在庫（計画どおり定植）', <?= json_encode($trustCumRot) ?>, { fill: true }),
        G.dsPlanted('累計在庫（いまの畑のまま）', <?= json_encode($trustCumOpen) ?>),
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: G.legend },
      scales: { y: G.yKg(false) },
    },
    plugins: [G.zeroPlugin],
  });
})();
</script>
<?php endif; ?>
<?php if ($chartLabels): ?>
<script>
(() => {
  if (!window.Chart || !window.GF_CHART) return;
  const G = window.GF_CHART;
  const currentIdx = <?= $chartCurrentIdx === null ? 'null' : (int)$chartCurrentIdx ?>;
  const pointRadius = <?= json_encode(array_map(static fn($i) => $i === $chartCurrentIdx ? 5 : 0, array_keys($chartLabels))) ?>;
  new Chart(document.getElementById('invChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
      datasets: [
        G.dsPlan('定植済予測', <?= json_encode($chartFc) ?>, {
          fill: true,
          borderWidth: 2.5,
          pointRadius,
          pointHoverRadius: 6,
        }),
        G.dsGcal('残出荷', <?= json_encode($chartShip) ?>, {
          borderColor: '#c47a00',
          borderDash: [],
          borderWidth: 2,
          backgroundColor: 'rgba(196,122,0,0.10)',
          fill: true,
        }),
        G.dsSales('余剰', <?= json_encode($chartSurplus) ?>, {
          borderDash: [4, 3],
          borderWidth: 1.5,
          pointRadius: 0,
        }),
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: G.legend },
      scales: {
        y: G.yKg(false),
        x: {
          ticks: {
            font: (ctx) => ({
              size: 10,
              weight: currentIdx !== null && ctx.index === currentIdx ? 'bold' : 'normal',
            }),
            color: (ctx) =>
              currentIdx !== null && ctx.index === currentIdx ? G.C.plan : undefined,
          },
        },
      },
    },
    plugins: [G.zeroPlugin],
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
