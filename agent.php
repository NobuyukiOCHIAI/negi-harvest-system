<?php
/**
 * 予測精度 — 主指標は①↔②ドリフト。完了実績は意図在庫の日数を除外。
 * 定植遅れ・割れ・出荷アラートは alerts / 今日 / 需給へ。
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/supply_ops.php';
require_once __DIR__ . '/lib/nav.php';

$acc = supply_pred_accuracy_metrics($link);
$checkedAt = date('Y-m-d H:i:s');

$nEval = (int)$acc['n'];
$nDays = (int)$acc['n_days'];
$mape = $acc['mape_pct'];
$maeKg = $acc['mae_kg'];
$maeDays = $acc['mae_days'];
$withinDaysPct = $acc['within_days_pct'];
$withinKgPct = $acc['within_kg_pct'];
$dayTh = (int)$acc['day_within'];
$kgTh = (int)$acc['kg_within'];
$usable = $nEval >= GF_PRED_ACC_MIN_N;

/**
 * @param float|null $v
 * @param array{good:float,watch:float} $th lower is better
 */
function acc_band(?float $v, bool $usable, array $th): string
{
    if (!$usable || $v === null) {
        return '件数不足';
    }
    if ($v <= $th['good']) {
        return '維持';
    }
    if ($v <= $th['watch']) {
        return '監視';
    }
    return '要確認';
}

/**
 * @param float|null $v higher is better (hit rate %)
 */
function acc_hit_band(?float $v, bool $usable): string
{
    if (!$usable || $v === null) {
        return '件数不足';
    }
    if ($v >= 70) {
        return '維持';
    }
    if ($v >= 50) {
        return '監視';
    }
    return '要確認';
}

$mapeBand = acc_band($mape !== null ? (float)$mape : null, $usable, ['good' => 15, 'watch' => 25]);
$maeKgBand = acc_band($maeKg !== null ? (float)$maeKg : null, $usable, ['good' => 15, 'watch' => 25]);
$maeDaysBand = acc_band(
    $maeDays !== null ? (float)$maeDays : null,
    $nDays >= GF_PRED_ACC_MIN_N,
    ['good' => (float)$dayTh, 'watch' => (float)($dayTh + 2)]
);
$hitDaysBand = acc_hit_band($withinDaysPct !== null ? (float)$withinDaysPct : null, $nDays >= GF_PRED_ACC_MIN_N);
$hitKgBand = acc_hit_band($withinKgPct !== null ? (float)$withinKgPct : null, $usable);

$bands = [$mapeBand, $maeKgBand, $maeDaysBand, $hitDaysBand, $hitKgBand];
$overall = '件数不足';
$overallCls = 'warn';
$overallHint = 'オープンサイクルが' . GF_PRED_ACC_MIN_N . '件以上たまってから判定する。';
if ($usable) {
    if (in_array('要確認', $bands, true)) {
        $overall = '要確認';
        $overallCls = 'danger';
        $overallHint = '①と②の差が大きい。需給SIMの額面を割り引いて読む。';
    } elseif (in_array('監視', $bands, true)) {
        $overall = '監視';
        $overallCls = 'warn';
        $overallHint = '①と②の差が広がっている。需給の額面は慎重に読む。';
    } else {
        $overall = '維持';
        $overallCls = 'ok';
        $overallHint = '①と②の差は小さい。週次の出荷判断に使ってよい。';
    }
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>予測精度</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css?v=20261005a">
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">予測精度</h1>
      <p class="page-sub">栽培中の①と②の差 · <?= htmlspecialchars($checkedAt, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </div>

  <div class="stat-row mb-3">
    <div class="stat-card <?= htmlspecialchars($overallCls, ENT_QUOTES, 'UTF-8') ?>">
      <div class="stat-label">予測の監視</div>
      <div class="stat-value" style="font-size:1.15rem"><?= htmlspecialchars($overall, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="stat-sub"><?= $usable ? ('栽培中 ' . $nEval . '床') : '栽培中の件数が足りません' ?></div>
    </div>
  </div>

  <div class="job-card mb-3">
    <div class="job-meta"><?= htmlspecialchars($overallHint, ENT_QUOTES, 'UTF-8') ?></div>
  </div>
</div>
<?php forecast_nav('settings'); ?>
</body>
</html>
