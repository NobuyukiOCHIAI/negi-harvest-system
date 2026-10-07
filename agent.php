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
        $overallHint = '方向は見るが、完了実績とセットで読む。';
    } else {
        $overall = '維持';
        $overallCls = 'ok';
        $overallHint = '①と②の差は小さい。週次の出荷判断に使ってよい。';
    }
}

function fmt_num($v, int $dec = 0): string
{
    if ($v === null) {
        return '—';
    }
    return number_format((float)$v, $dec);
}

$completed = is_array($acc['completed'] ?? null) ? $acc['completed'] : null;
$plant = is_array($acc['plant'] ?? null) ? $acc['plant'] : null;
$legacy = is_array($acc['legacy_mid'] ?? null) ? $acc['legacy_mid'] : null;
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
      <p class="page-sub">①定植時 ↔ ②実効のドリフト（主）· 完了実績は意図在庫の日数を除外 · <?= htmlspecialchars($checkedAt, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
  </div>

  <h2 class="section-title">①↔②ドリフト（運用主指標）</h2>
  <div class="stat-row mb-3">
    <div class="stat-card <?= htmlspecialchars($overallCls, ENT_QUOTES, 'UTF-8') ?>">
      <div class="stat-label">総合</div>
      <div class="stat-value" style="font-size:1.15rem"><?= htmlspecialchars($overall, ENT_QUOTES, 'UTF-8') ?></div>
      <div class="stat-sub">n=<?= $nEval ?> · オープン床</div>
    </div>
    <div class="stat-card <?= $usable && $mape !== null && (float)$mape > 25 ? 'warn' : '' ?>">
      <div class="stat-label">kg差 MAPE</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $mape === null ? '—' : $mape . '%' ?></div>
      <div class="stat-sub"><?= htmlspecialchars($mapeBand, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">MAE（kg）</div>
      <div class="stat-value" style="font-size:1.1rem"><?= fmt_num($maeKg, 0) ?><?= $maeKg !== null ? 'kg' : '' ?></div>
      <div class="stat-sub"><?= htmlspecialchars($maeKgBand, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  </div>

  <div class="stat-row mb-3">
    <div class="stat-card">
      <div class="stat-label">MAE（日）</div>
      <div class="stat-value" style="font-size:1.1rem"><?= fmt_num($maeDays, 1) ?><?= $maeDays !== null ? '日' : '' ?></div>
      <div class="stat-sub"><?= htmlspecialchars($maeDaysBand, ENT_QUOTES, 'UTF-8') ?> · n=<?= $nDays ?>（意図在庫除外）</div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><?= $dayTh ?>日以内</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $withinDaysPct === null ? '—' : ((int)$withinDaysPct . '%') ?></div>
      <div class="stat-sub"><?= htmlspecialchars($hitDaysBand, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label"><?= $kgTh ?>kg以内</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $withinKgPct === null ? '—' : ((int)$withinKgPct . '%') ?></div>
      <div class="stat-sub"><?= htmlspecialchars($hitKgBand, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  </div>

  <div class="job-card mb-3">
    <div class="job-name"><?= htmlspecialchars($overall, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="job-meta"><?= htmlspecialchars($overallHint, ENT_QUOTES, 'UTF-8') ?></div>
  </div>

  <?php if ($completed): ?>
  <h2 class="section-title">完了実績（意図在庫の日数除外）</h2>
  <div class="stat-row mb-3">
    <div class="stat-card">
      <div class="stat-label">MAPE</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $completed['mape_pct'] === null ? '—' : $completed['mape_pct'] . '%' ?></div>
      <div class="stat-sub">n=<?= (int)$completed['n'] ?> · 直近<?= (int)$acc['window_days'] ?>日</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">MAE（kg）</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $completed['mae_kg'] === null ? '—' : number_format((float)$completed['mae_kg'], 0) . 'kg' ?></div>
      <div class="stat-sub"><?= $completed['within_kg_pct'] === null ? '—' : ((int)$completed['within_kg_pct'] . '%が15kg以内') ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">MAE（日）</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $completed['mae_days'] === null ? '—' : number_format((float)$completed['mae_days'], 1) . '日' ?></div>
      <div class="stat-sub">n=<?= (int)$completed['n_days'] ?> · <?= $completed['within_days_pct'] === null ? '—' : ((int)$completed['within_days_pct'] . '%が3日以内') ?></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($plant): ?>
  <h2 class="section-title">定植時（遠週の参考）</h2>
  <div class="stat-row mb-3">
    <div class="stat-card">
      <div class="stat-label">MAPE</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $plant['mape_pct'] === null ? '—' : $plant['mape_pct'] . '%' ?></div>
      <div class="stat-sub">n=<?= (int)$plant['n'] ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">MAE（kg）</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $plant['mae_kg'] === null ? '—' : number_format((float)$plant['mae_kg'], 0) . 'kg' ?></div>
      <div class="stat-sub"><?= $plant['within_kg_pct'] === null ? '—' : ((int)$plant['within_kg_pct'] . '%が15kg以内') ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">MAE（日）</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $plant['mae_days'] === null ? '—' : number_format((float)$plant['mae_days'], 1) . '日' ?></div>
      <div class="stat-sub"><?= $plant['within_days_pct'] === null ? '—' : ((int)$plant['within_days_pct'] . '%が3日以内') ?></div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($legacy): ?>
  <h2 class="section-title">参考（旧 mid・意図在庫込み）</h2>
  <div class="stat-row mb-3">
    <div class="stat-card">
      <div class="stat-label">MAPE</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $legacy['mape_pct'] === null ? '—' : $legacy['mape_pct'] . '%' ?></div>
      <div class="stat-sub">n=<?= (int)$legacy['n'] ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">MAE（日）</div>
      <div class="stat-value" style="font-size:1.1rem"><?= $legacy['mae_days'] === null ? '—' : number_format((float)$legacy['mae_days'], 1) . '日' ?></div>
      <div class="stat-sub">n=<?= (int)$legacy['n_days'] ?> · 延長を含む</div>
    </div>
  </div>
  <?php endif; ?>

  <h2 class="section-title"><?= gf_icon('chart') ?> 指標の見方</h2>
  <div class="job-card">
    <div class="job-name">何と比べているか</div>
    <div class="job-meta">
      <strong>上段（①↔②ドリフト）</strong>：栽培中ベッドで、定植時予測と実効予測がどれだけ離れているか。
      モデルが後から動かした幅。意図在庫床の日数は除外。
    </div>
    <div class="job-meta mt-2">
      <strong>中段（完了実績）</strong>：収穫済み床で mid＋いまの補正 vs 実績。
      <em>意図在庫化した床の日数は母集団から除外</em>（延長をモデル誤差に数えない）。kgは残す。
    </div>
    <div class="job-meta mt-2">
      <strong>下段（参考）</strong>：定植時遠週の当て方、および旧 mid 指標（意図在庫込み）。
    </div>
    <div class="job-meta mt-2">
      目安: MAPE/ドリフト 15%以内・MAE 15kg以内・3日以内ヒット70%以上で「維持」。
    </div>
    <div class="job-meta mt-2">
      定植遅れ・在庫割れ・出荷トレンドは
      <a href="alerts.php">経営アラート</a>・
      <a href="today.php">今日</a>・
      <a href="capacity.php">需給</a>
      で見る。
    </div>
  </div>
</div>
<?php forecast_nav('settings'); ?>
</body>
</html>
