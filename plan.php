<?php
/**
 * 定植計画 — 不足週シミュレーション + 明示スケジュール
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/plant_schedule.php';
require_once __DIR__ . '/lib/rotation_capacity.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/actual_advice.php';

function week_label_sunday_local(string $weekStartDate): string
{
    $ts = strtotime($weekStartDate . ' 12:00:00');
    return $ts ? date('n/j週', $ts) : $weekStartDate;
}

$flash = '';
$err = '';
$tableOk = false;
$chk = mysqli_query($link, "SHOW TABLES LIKE 'plant_schedule'");
if ($chk && mysqli_num_rows($chk) > 0) {
    $tableOk = true;
}
if ($chk) {
    mysqli_free_result($chk);
}

if ($tableOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'generate') {
        $result = plant_schedule_generate($link, 12);
        $flash = sprintf(
            '追加生成 %d件（想定 %.0fkg / %.0f日 · 今日定植→%s週から収穫可 · 間に合わない不足週 %d）',
            $result['created'],
            $result['defaults']['yield'],
            $result['defaults']['days'],
            date('n/j', strtotime($result['earliest_harvest_week'])),
            $result['skipped_late'] ?? 0
        );
    } elseif ($action === 'continuous') {
        $result = rotation_generate_continuous_plants($link, 12);
        $flash = sprintf(
            '常時回転: 空きベッド定植 %d件追加（猶予%d日 · 想定 %.0f日 / %.0fkg）',
            $result['created'],
            GF_REPLANT_GRACE_DAYS,
            $result['defaults']['days'],
            $result['defaults']['yield']
        );
    } elseif ($action === 'regenerate') {
        $result = plant_schedule_regenerate($link, 12);
        $earliest = plant_schedule_earliest_harvest_week((float)$result['defaults']['days']);
        $flash = sprintf(
            '作り直し: 旧planned %d件削除 → 新規 %d件（想定 %.0f日 · 今日定植→%s週から · 遅すぎ除外 %d週）',
            $result['cleared'],
            $result['created'],
            $result['defaults']['days'],
            date('n/j', strtotime($earliest)),
            $result['skipped_late']
        );
    } elseif ($action === 'status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = (string)($_POST['status'] ?? '');
        if ($id > 0 && plant_schedule_set_status($link, $id, $status)) {
            $flash = '更新しました';
        } else {
            $err = '更新に失敗しました';
        }
    }
}

$sim = plant_schedule_simulate($link, 12);
$emptyN = count(plant_schedule_empty_beds($link));
$defaults = $sim['defaults'];
$rows = $tableOk ? plant_schedule_list($link) : [];

$gapLabels = [];
$shipVals = [];
$fcVals = [];
$planAddVals = [];
foreach (array_slice($sim['weeks'], 0, 10) as $g) {
    $gapLabels[] = date('n/j', strtotime($g['week']));
    $shipVals[] = $g['ship_kg'];
    $fcVals[] = $g['forecast_kg'];
    $planAddVals[] = $g['planned_kg'];
}

$thresh = GF_SHORTAGE_THRESHOLD_KG;
$shortageWeeks = array_values(array_filter(
    $sim['weeks'],
    static fn($g) => $g['gap_kg'] > $thresh || $g['sim_gap_kg'] > $thresh || $g['planned_kg'] > 0
));
$needWeeks = (int)$sim['shortage_before'];
$needAfter = (int)$sim['shortage_after'];
$earliestHarvestWeek = $sim['earliest_harvest_week'] ?? plant_schedule_earliest_harvest_week((float)$defaults['days']);
$earliestLabel = date('n/j', strtotime($earliestHarvestWeek));

$actualTip = null;
$loadedActual = gf_actual_load_monthly($link);
if ($loadedActual['ok'] && $loadedActual['years']) {
    $fy = (int)date('Y');
    $adv = gf_actual_build_advice(
        $loadedActual['matrix'],
        $loadedActual['years'],
        $fy,
        (float)$defaults['yield']
    );
    $actualTip = $adv['plan_summary'];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>定植計画</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <style>
    .advice-box {
      border-radius: 12px; padding: 0.7rem 0.85rem; margin-bottom: 0.85rem;
      border: 1px solid #f0d9a0; background: var(--gf-amber-soft);
      font-size: 0.82rem; line-height: 1.45;
    }
    .advice-box a { font-weight: 800; color: var(--gf-green-dark); text-decoration: none; }
    .advice-box a:hover { text-decoration: underline; }
  </style>
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">定植計画</h1>
      <p class="page-sub">週次不足を埋める · スタッフへ明示</p>
    </div>
  </div>

  <div class="job-card mb-3" style="border-left:4px solid var(--gf-amber)">
    <div class="job-meta fw-bold">役割分担</div>
    <div class="job-meta">
      <strong>常時回転</strong>で空きベッドを猶予<?= (int)GF_REPLANT_GRACE_DAYS ?>日内に植え続けるのが基本。
      「不足埋め」は出荷コミットへの追加調整。<a href="capacity.php">需給・営業</a>でフル回転見通しと昨対を確認。
    </div>
  </div>

  <?php if (!$tableOk): ?>
    <div class="alert alert-danger">plant_schedule 未作成。sql/_apply_plant_schedule.py を実行してください。</div>
  <?php endif; ?>
  <?php if ($flash): ?><div class="alert alert-success"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <?php if ($actualTip): ?>
    <div class="advice-box">
      <?= htmlspecialchars($actualTip, ENT_QUOTES, 'UTF-8') ?>
      <div style="margin-top:0.35rem"><a href="actual.php">実収穫量（過去最高との差）→</a></div>
    </div>
  <?php endif; ?>

  <div class="stat-row">
    <div class="stat-card ok">
      <?= gf_icon('empty', 'stat-ico') ?>
      <div class="stat-label">空きベッド</div>
      <div class="stat-value"><?= $emptyN ?></div>
      <div class="stat-sub">今すぐ定植できるベッド</div>
    </div>
    <div class="stat-card <?= $needWeeks ? 'warn' : 'ok' ?>">
      <?= gf_icon('alert', 'stat-ico') ?>
      <div class="stat-label">不足週・週次（現行）</div>
      <div class="stat-value"><?= $needWeeks ?></div>
      <div class="stat-sub">その週の収穫＜出荷</div>
    </div>
    <div class="stat-card <?= $needAfter ? 'danger' : 'ok' ?>">
      <?= gf_icon('plant', 'stat-ico') ?>
      <div class="stat-label">不足週・週次（計画後）</div>
      <div class="stat-value"><?= $needAfter ?></div>
      <div class="stat-sub">計画分を足しても残る週</div>
    </div>
  </div>

  <div id="sec-sim" class="chart-card">
    <div class="chart-title">シミュレーション · 週ごとの収穫量と出荷量</div>
    <div class="sim-row">
      <div class="sim-box">
        <div class="s-label">現行の不足週</div>
        <div class="s-val"><?= $needWeeks ?>週</div>
      </div>
      <div class="sim-box after <?= $needAfter ? 'ng' : 'ok' ?>">
        <div class="s-label">定植計画後</div>
        <div class="s-val"><?= $needAfter ?>週</div>
      </div>
    </div>
    <div class="chart-wrap tall"><canvas id="gapChart"></canvas></div>
    <p class="page-sub mt-2 mb-0">
      積み上げ棒＝その週に収穫できる量。<strong>濃い緑＝定植済み（現行予測）</strong>、
      <strong>薄い緑＝これから定植する計画分</strong>（定植日＋想定<?= (int)$defaults['days'] ?>日で載せる）。
      赤い線の<strong>出荷量</strong>を棒の合計が上回っていればOK。
      今日定植すると<strong><?= htmlspecialchars($earliestLabel, ENT_QUOTES, 'UTF-8') ?>週</strong>から収穫に届くため、
      それより前の不足週（例: 8/30）は計画対象外。薄い緑は<?= htmlspecialchars($earliestLabel, ENT_QUOTES, 'UTF-8') ?>以降に積み上がります。
    </p>
  </div>

  <?php if ($tableOk): ?>
    <p class="page-sub mb-2">
      常時回転は画面表示時に自動投入済み。下のボタンは計画行の手動再投入のみ（畑に植わる／GCALは変わりません）。
    </p>
    <form method="post" class="mb-2" onsubmit="return confirm('空きベッドの定植計画行を再投入します（GCAL・実績は変わりません）。よろしいですか？');">
      <input type="hidden" name="action" value="continuous">
      <button type="submit" class="btn btn-sm btn-outline-success">常時回転を再投入</button>
    </form>
    <form method="post" class="mb-1" onsubmit="return confirm('不足週向けの推奨定植を追加生成しますか？');">
      <input type="hidden" name="action" value="generate">
      <button type="submit" class="btn btn-outline-primary w-100">不足週の推奨定植を追加</button>
    </form>
    <form method="post" class="mb-1" onsubmit="return confirm('未実施の planned を消して、今日基準で作り直します。よろしいですか？');">
      <input type="hidden" name="action" value="regenerate">
      <button type="submit" class="btn btn-outline-secondary w-100">不足埋め planned を作り直す</button>
    </form>
    <p class="page-sub mb-3">
      基本は常時回転（自動）。不足埋めは出荷コミットへの追加調整です。
      想定 <?= (int)$defaults['days'] ?>日 / <?= (int)$defaults['yield'] ?>kg。
    </p>
  <?php endif; ?>

  <h2 class="section-title"><?= gf_icon('alert') ?> 不足週と定植の対応</h2>
  <?php if (!$shortageWeeks): ?>
    <div class="job-card"><div class="job-meta">対象週はありません。</div></div>
  <?php else: ?>
    <?php foreach ($shortageWeeks as $g):
      $stillShort = $g['sim_gap_kg'] > $thresh;
      ?>
      <div class="shortage-card" style="<?= $stillShort ? '' : 'border-left-color:var(--gf-green);border-color:#b8dcc8' ?>">
        <div class="sh-title"><?= htmlspecialchars(week_label_sunday_local($g['week']), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="sim-row mt-2 mb-1">
          <div class="sim-box">
            <div class="s-label">現行の不足</div>
            <div class="s-val" style="font-size:1rem">
              <?= $g['gap_kg'] > 0 ? number_format($g['gap_kg'], 0) . 'kg不足' : '充足' ?>
            </div>
          </div>
          <div class="sim-box after <?= $stillShort ? 'ng' : 'ok' ?>">
            <div class="s-label">計画後</div>
            <div class="s-val" style="font-size:1rem">
              <?= $g['sim_gap_kg'] > 0 ? number_format($g['sim_gap_kg'], 0) . 'kg不足' : '充足' ?>
            </div>
          </div>
        </div>
        <div class="sh-meta">
          出荷 <?= number_format($g['ship_kg'], 0) ?>kg · 収穫予測 <?= number_format($g['forecast_kg'], 0) ?>kg
          <?php if ($g['planned_kg'] > 0): ?>
            · 計画分 +<?= number_format($g['planned_kg'], 0) ?>kg
          <?php endif; ?>
        </div>
        <?php if ($g['plans']): ?>
          <div class="sh-meta mt-1">
            <?php foreach ($g['plans'] as $p): ?>
              <span class="chip mb-1"><?= htmlspecialchars($p['bed_name'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars($p['planned_plant_date'], ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
          </div>
        <?php elseif ($stillShort): ?>
          <div class="sh-meta text-danger fw-semibold">定植計画なし · 推奨定植 <?= htmlspecialchars($g['suggest_plant_date'], ENT_QUOTES, 'UTF-8') ?> · あと<?= (int)$g['need_beds'] ?>ベッド</div>
        <?php endif; ?>
        <?php if ($stillShort && $tableOk): ?>
          <div class="sh-action">
            <form method="post" onsubmit="return confirm('この不足を埋める推奨定植を生成しますか？');">
              <input type="hidden" name="action" value="generate">
              <button type="submit" class="btn btn-outline-primary btn-sm w-100">推奨定植を生成</button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <h2 class="section-title"><?= gf_icon('calendar') ?> 定植スケジュール（明示）</h2>
  <?php if (!$tableOk || !$rows): ?>
    <div class="job-card"><div class="job-meta">予定がありません。上の生成ボタンを押してください。</div></div>
  <?php else: ?>
    <div class="job-grid">
      <?php foreach ($rows as $r): ?>
        <div class="job-card compact">
          <div class="job-name"><?= htmlspecialchars($r['bed_name'], ENT_QUOTES, 'UTF-8') ?></div>
          <div class="job-meta">
            定植 <?= htmlspecialchars($r['planned_plant_date'], ENT_QUOTES, 'UTF-8') ?>
          </div>
          <div class="job-meta">
            収穫 <?= $r['target_harvest_week'] ? week_label_sunday_local($r['target_harvest_week']) : '—' ?>
            · <?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8') ?>
          </div>
          <?php if (in_array($r['status'], ['planned', 'approved'], true)): ?>
            <a class="btn btn-success btn-sm w-100 mt-2"
               href="data_entry/planting.php?bed_id=<?= (int)$r['bed_id'] ?>&schedule_id=<?= (int)$r['id'] ?>">定植入力</a>
            <form method="post" class="d-flex gap-1 mt-1">
              <input type="hidden" name="action" value="status">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <?php if ($r['status'] === 'planned'): ?>
                <button name="status" value="approved" class="btn btn-outline-primary btn-sm flex-grow-1">承認</button>
              <?php endif; ?>
              <button name="status" value="skipped" class="btn btn-outline-secondary btn-sm flex-grow-1">見送り</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php forecast_nav('plan'); ?>
<script>
new Chart(document.getElementById('gapChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($gapLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
      { label: '収穫予測(定植済)', data: <?= json_encode($fcVals) ?>, backgroundColor: '#1b7a4a', stack: 'harvest' },
      { label: '定植計画分', data: <?= json_encode($planAddVals) ?>, backgroundColor: '#8fd0a8', stack: 'harvest' },
      { label: '出荷量', data: <?= json_encode($shipVals) ?>, type: 'line', borderColor: '#c62828', backgroundColor: '#c62828', tension: 0.2, borderWidth: 2, pointRadius: 3 }
    ]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
      tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + Math.round(c.parsed.y) + 'kg' } }
    },
    scales: {
      y: { beginAtZero: true, stacked: true, ticks: { font: { size: 10 } } },
      x: { stacked: true, ticks: { font: { size: 10 } } }
    }
  }
});
</script>
</body>
</html>
