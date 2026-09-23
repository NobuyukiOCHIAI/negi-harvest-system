<?php
/**
 * ベッド単位の栽培サイクル一覧
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/nav.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/harvest_ratio.php';

$bedId = (int)($_GET['bed_id'] ?? 0);

$bed = null;
if ($bedId > 0) {
    $st = mysqli_prepare($link, 'SELECT id, name, group_type, active FROM beds WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $bedId);
    mysqli_stmt_execute($st);
    $rr = mysqli_stmt_get_result($st);
    $bed = mysqli_fetch_assoc($rr) ?: null;
    mysqli_stmt_close($st);
}

$cycles = [];
$openCycle = null;
if ($bed) {
    $st = mysqli_prepare(
        $link,
        "SELECT id, sow_date, plant_date, harvest_start, harvest_end
         FROM cycles WHERE bed_id = ?
         ORDER BY plant_date DESC, id DESC"
    );
    mysqli_stmt_bind_param($st, 'i', $bedId);
    mysqli_stmt_execute($st);
    $res = mysqli_stmt_get_result($st);
    while ($row = mysqli_fetch_assoc($res)) {
        $row['harvests'] = [];
        $row['total_kg'] = 0.0;
        $row['ratio_sum'] = 0.0;
        $cid = (int)$row['id'];
        if ($row['harvest_end'] !== null) {
            $row['status'] = 'closed';
            $row['status_label'] = '完了';
        } elseif ($row['harvest_start'] !== null) {
            $row['status'] = 'harvesting';
            $row['status_label'] = '収穫中';
        } else {
            $row['status'] = 'growing';
            $row['status_label'] = '栽培中';
        }
        if ($row['harvest_end'] === null && $openCycle === null) {
            $openCycle = $row;
        }
        $cycles[$cid] = $row;
    }
    mysqli_stmt_close($st);

    if ($cycles) {
        $ids = implode(',', array_map('intval', array_keys($cycles)));
        $hr = mysqli_query(
            $link,
            "SELECT id, cycle_id, harvest_date, harvest_kg, harvest_ratio, loss_type_id
             FROM harvests WHERE cycle_id IN ({$ids})
             ORDER BY harvest_date ASC, id ASC"
        );
        $gomiId = null;
        $gr = mysqli_query(
            $link,
            "SELECT id FROM loss_types WHERE name IN ('GOMI','ゴミ','gomi') ORDER BY id ASC LIMIT 1"
        );
        if ($gr && ($grow = mysqli_fetch_assoc($gr))) {
            $gomiId = (int)$grow['id'];
        }
        if ($gr) {
            mysqli_free_result($gr);
        }
        if ($hr) {
            while ($h = mysqli_fetch_assoc($hr)) {
                $cid = (int)$h['cycle_id'];
                if (!isset($cycles[$cid])) {
                    continue;
                }
                $ratio = $h['harvest_ratio'] !== null ? (float)$h['harvest_ratio'] : 0.0;
                $cycles[$cid]['harvests'][] = [
                    'id' => (int)$h['id'],
                    'harvest_date' => $h['harvest_date'],
                    'harvest_kg' => (float)$h['harvest_kg'],
                    'harvest_ratio' => $h['harvest_ratio'] !== null ? $ratio : null,
                    'ratio_label' => '', // 後でサイクル単位に配分
                    'is_gomi' => ($gomiId !== null && (int)($h['loss_type_id'] ?? 0) === $gomiId),
                ];
                $cycles[$cid]['total_kg'] += (float)$h['harvest_kg'];
                $cycles[$cid]['ratio_sum'] += harvest_ratio_snap($ratio);
            }
            mysqli_free_result($hr);
        }
        // 栽培中の予想は予測ページと同じ起点（正本 §6）。完了実績は触らない。
        $pr = mysqli_query(
            $link,
            "SELECT pr.cycle_id,
                    DATE_ADD(c.plant_date, INTERVAL CAST(ROUND(pr.pred_days) AS SIGNED) DAY) AS expected_harvest,
                    COALESCE(pr.postproc_total_kg, pr.pred_total_kg) AS forecast_kg
             FROM predictions pr
             JOIN cycles c ON c.id = pr.cycle_id
             WHERE pr.cycle_id IN ({$ids})
               AND pr.id = (
                 SELECT p2.id FROM predictions p2
                 WHERE p2.cycle_id = pr.cycle_id
                 ORDER BY p2.created_at DESC, p2.id DESC
                 LIMIT 1
               )"
        );
        if ($pr) {
            while ($p = mysqli_fetch_assoc($pr)) {
                $cid = (int)$p['cycle_id'];
                if (!isset($cycles[$cid])) {
                    continue;
                }
                $cycles[$cid]['expected_harvest'] = $p['expected_harvest'] ?: null;
                $cycles[$cid]['forecast_kg'] = ($p['forecast_kg'] !== null && $p['forecast_kg'] !== '')
                    ? (float)$p['forecast_kg']
                    : null;
            }
            mysqli_free_result($pr);
        }
        foreach ($cycles as $cid => &$cRef) {
            if (empty($cRef['harvests'])) {
                continue;
            }
            $ratios = [];
            $kgs = [];
            foreach ($cRef['harvests'] as $h) {
                $ratios[] = $h['harvest_ratio'];
                $kgs[] = $h['harvest_kg'];
            }
            $labels = harvest_ratio_labels_allocated(
                $ratios,
                $kgs,
                ($cRef['status'] === 'closed')
            );
            foreach ($cRef['harvests'] as $i => &$hRef) {
                $hRef['ratio_label'] = $labels[$i] ?? harvest_ratio_label((float)($hRef['harvest_ratio'] ?? 0));
            }
            unset($hRef);
        }
        unset($cRef);
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title><?= $bed ? htmlspecialchars($bed['name'], ENT_QUOTES, 'UTF-8') . ' サイクル' : 'サイクル一覧' ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css?v=20260814b">
</head>
<body class="field-entry">
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title"><?= $bed ? htmlspecialchars($bed['name'], ENT_QUOTES, 'UTF-8') : 'ベッド' ?></h1>
      <p class="page-sub"><?= $bed ? htmlspecialchars($bed['group_type'], ENT_QUOTES, 'UTF-8') . ' · 栽培サイクル' : 'ベッドが見つかりません' ?></p>
    </div>
    <a class="btn btn-outline-success" href="monitor.php">モニターへ</a>
  </div>

  <?php if (!$bed): ?>
    <div class="alert alert-danger">ベッドが見つかりません。</div>
  <?php else: ?>
    <?php if ((int)$bed['active'] !== 1): ?>
      <div class="alert alert-secondary">このベッドは非稼働です。過去データの閲覧はできます。定植・収穫の登録はできません。</div>
    <?php elseif ($openCycle === null): ?>
      <a class="btn btn-primary btn-lg w-100 mb-3" href="data_entry/planting.php?bed_id=<?= (int)$bedId ?>">このベッドに定植する</a>
    <?php else: ?>
      <a class="btn btn-success btn-lg w-100 mb-3" href="data_entry/harvest.php?cycle_id=<?= (int)$openCycle['id'] ?>">収穫を入力</a>
    <?php endif; ?>

    <?php if (!$cycles): ?>
      <p class="text-muted">このベッドのサイクルはまだありません。</p>
    <?php else: ?>
      <?php foreach ($cycles as $c):
        $st = $c['status'];
        $open = ($st !== 'closed');
        ?>
        <div class="recent-cycle <?= $open ? 'open' : 'closed' ?>">
          <div class="rc-head">
            <?= gf_cycle_status($st, $c['status_label']) ?>
            <span class="rc-id">#<?= (int)$c['id'] ?></span>
          </div>
          <table class="kv">
            <tr><th>播種</th><td><?= h_ymd($c['sow_date'] ?? null) ?></td></tr>
            <tr><th>定植</th><td><?= h_ymd($c['plant_date'] ?? null) ?></td></tr>
            <?php if ($st === 'growing'): ?>
              <tr><th>予想収穫日</th><td><?= h_ymd($c['expected_harvest'] ?? null) ?></td></tr>
              <tr><th>予想収穫量</th><td><?= isset($c['forecast_kg']) && $c['forecast_kg'] !== null ? h_int($c['forecast_kg']) . 'kg' : '—' ?></td></tr>
            <?php endif; ?>
            <?php if (!empty($c['harvests'])): ?>
              <tr><th>収穫量</th><td><?= h_int($c['total_kg']) ?>kg</td></tr>
            <?php elseif ($st === 'closed'): ?>
              <tr><th>収穫完了</th><td><?= h_ymd($c['harvest_end'] ?? null) ?></td></tr>
            <?php endif; ?>
          </table>
          <?php if (!empty($c['harvests'])): ?>
            <div class="rc-harvests">
              <?php foreach ($c['harvests'] as $h): ?>
                <div class="rc-h">
                  <div class="rc-h-main">
                    <span><?= h_ymd($h['harvest_date']) ?></span>
                    <span><?= h_int($h['harvest_kg']) ?>kg</span>
                    <span class="rc-h-ratio"><?= htmlspecialchars($h['ratio_label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php if ($h['is_gomi']): ?><span>ゴミ</span><?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php forecast_nav('monitor'); ?>
</body>
</html>
