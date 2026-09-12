<?php
/**
 * 栽培状況モニター（ベッドボード + カレンダー）— モバイル優先
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/overgrow_metrics.php';
require_once __DIR__ . '/lib/date_display.php';
require_once __DIR__ . '/lib/nav.php';

$statusFilter = $_GET['status'] ?? '';
$harvestWeek = trim((string)($_GET['harvest_week'] ?? ''));
if ($harvestWeek !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $harvestWeek)) {
    $harvestWeek = '';
}
if ($harvestWeek !== '') {
    $harvestWeek = week_sunday_date($harvestWeek) ?? '';
}

$openByBed = [];
foreach (open_cycle_progress($link) as $op) {
    $openByBed[$op['bed_name']] = $op;
}

$allBeds = [];
$bedRes = mysqli_query($link, "SELECT id, name, group_type FROM beds WHERE active = 1 ORDER BY group_type ASC, name ASC");
if ($bedRes) {
    while ($b = mysqli_fetch_assoc($bedRes)) {
        $cycleRes = mysqli_query(
            $link,
            "SELECT * FROM cycles WHERE bed_id=" . (int)$b['id'] . " ORDER BY plant_date DESC, id DESC LIMIT 1"
        );
        $cycle = $cycleRes ? mysqli_fetch_assoc($cycleRes) : null;
        if ($cycleRes) {
            mysqli_free_result($cycleRes);
        }
        $bedStatus = 'empty';
        if ($cycle) {
            if ($cycle['harvest_end'] !== null) {
                $bedStatus = 'empty';
            } elseif ($cycle['harvest_start'] !== null) {
                $bedStatus = 'harvesting';
            } elseif ($cycle['plant_date'] !== null) {
                $bedStatus = 'growing';
            }
        }
        $b['cycle'] = $cycle;
        $b['status'] = $bedStatus;
        $b['progress'] = $openByBed[$b['name']] ?? null;
        $allBeds[] = $b;
    }
}

$todaySun = week_sunday_date(date('Y-m-d')) ?? date('Y-m-d');
$harvestWeekOptions = [];
$optTs = strtotime($todaySun . ' 12:00:00');
for ($i = -2; $i <= 16; $i++) {
    $w = date('Y-m-d', strtotime(($i >= 0 ? '+' : '') . $i . ' week', $optTs));
    $harvestWeekOptions[$w] = format_sunday_week($w);
}
foreach ($allBeds as $b) {
    $exp = ($b['status'] === 'empty') ? null : ($b['progress']['expected_harvest'] ?? null);
    $sun = week_sunday_date($exp);
    if ($sun !== null && !isset($harvestWeekOptions[$sun])) {
        $harvestWeekOptions[$sun] = format_sunday_week($sun);
    }
}
ksort($harvestWeekOptions);

$beds = [];
foreach ($allBeds as $b) {
    if ($statusFilter !== '' && $statusFilter !== $b['status']) {
        continue;
    }
    if ($harvestWeek !== '') {
        $exp = ($b['status'] === 'empty') ? null : ($b['progress']['expected_harvest'] ?? null);
        if (week_sunday_date($exp) !== $harvestWeek) {
            continue;
        }
    }
    $beds[] = $b;
}

function week_status($cycle, $weekStart) {
    $weekEnd = strtotime('+6 day', $weekStart);
    if (!$cycle) {
        return ['', ''];
    }
    $plant = $cycle['plant_date'] ? strtotime($cycle['plant_date']) : null;
    $harvestStart = $cycle['harvest_start'] ? strtotime($cycle['harvest_start']) : null;
    $harvestEnd = $cycle['harvest_end'] ? strtotime($cycle['harvest_end']) : null;

    if ($plant && $plant >= $weekStart && $plant <= $weekEnd) {
        return ['bg-info text-white', '定植'];
    }
    if ($harvestStart && $harvestEnd && $harvestStart <= $weekEnd && $harvestEnd >= $weekStart) {
        return ['bg-warning', '収穫'];
    }
    if ($harvestStart && !$harvestEnd && $harvestStart <= $weekEnd && $weekStart <= time()) {
        return ['bg-warning', '収穫中'];
    }
    if ($plant && $weekStart >= $plant && (!$harvestStart || $weekEnd < $harvestStart)) {
        return ['bg-success text-white', '栽培'];
    }
    return ['', ''];
}

$statusLabel = [
    'empty' => '空き',
    'growing' => '栽培中',
    'harvesting' => '収穫中',
];

$calStart = ($harvestWeek !== '') ? $harvestWeek : $todaySun;
$weekStart = strtotime($calStart . ' 12:00:00');
$weekLabels = [];
for ($i = 0; $i < 7; $i++) {
    $wsTs = strtotime("+$i week", $weekStart);
    $weekLabels[] = format_sunday_week(date('Y-m-d', $wsTs));
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#1b7a4a">
  <title>栽培状況モニター</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="css/mobile-ui.css?v=20260814i">
</head>
<body>
<div class="container py-3">
  <div class="gf-header">
    <div>
      <h1 class="page-title">栽培モニター</h1>
      <p class="page-sub">いまの圃場状態。作業の入力は <a href="today.php">今日</a> へ</p>
    </div>
    <div class="actions">
      <a href="today.php" class="btn btn-sm btn-outline-success">今日</a>
    </div>
  </div>

  <form method="get" class="row g-2 mb-3">
    <div class="col-6">
      <select name="status" class="form-select" onchange="this.form.submit()">
        <option value=""<?= $statusFilter === '' ? ' selected' : '' ?>>状態: 全体</option>
        <option value="growing"<?= $statusFilter === 'growing' ? ' selected' : '' ?>>栽培中</option>
        <option value="harvesting"<?= $statusFilter === 'harvesting' ? ' selected' : '' ?>>収穫中</option>
        <option value="empty"<?= $statusFilter === 'empty' ? ' selected' : '' ?>>空き</option>
      </select>
    </div>
    <div class="col-6">
      <select name="harvest_week" class="form-select" onchange="this.form.submit()">
        <option value=""<?= $harvestWeek === '' ? ' selected' : '' ?>>収穫予定週: 全体</option>
        <?php foreach ($harvestWeekOptions as $w => $lab): ?>
          <option value="<?= htmlspecialchars($w, ENT_QUOTES, 'UTF-8') ?>"<?= $harvestWeek === $w ? ' selected' : '' ?>>
            <?= htmlspecialchars($lab, ENT_QUOTES, 'UTF-8') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <h2 class="section-title"><?= gf_icon('bed') ?> ベッド一覧</h2>
  <p class="page-sub mb-2">状態の確認用。過栽培の深掘りは <a href="overgrow.php">過栽培</a>（診断）。</p>
  <div class="mon-beds-tools mb-2">
    <input type="search" id="monBedsQ" class="form-control" placeholder="検索" autocomplete="off">
    <span class="page-sub mb-0" id="monBedsInfo"></span>
  </div>
  <div class="mon-beds-wrap mb-4">
    <table id="monBeds" class="table table-sm align-middle nowrap w-100">
      <thead>
        <tr>
          <th>ベッド</th>
          <th>状態</th>
          <th>定植日</th>
          <th>収穫予定日</th>
          <th class="text-end">予測kg</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($beds as $bed):
          $st = $bed['status'];
          $prog = $bed['progress'];
          $cycle = $bed['cycle'];
          $href = 'bed_cycles.php?bed_id=' . (int)$bed['id'];
          $plant = ($st === 'empty') ? null : ($cycle['plant_date'] ?? null);
          $expected = ($st === 'empty') ? null : ($prog['expected_harvest'] ?? null);
          $kg = ($st === 'empty') ? null : ($prog['postproc_yield'] ?? $prog['pred_yield'] ?? null);
          $risk = ($prog && $prog['risk']);
          ?>
          <tr class="<?= $risk ? 'table-warning' : '' ?>">
            <td data-order="<?= htmlspecialchars($bed['name'], ENT_QUOTES, 'UTF-8') ?>">
              <a href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" class="fw-bold">
                <?= htmlspecialchars($bed['name'], ENT_QUOTES, 'UTF-8') ?>
              </a>
              <div class="text-muted small"><?= htmlspecialchars($bed['group_type'], ENT_QUOTES, 'UTF-8') ?></div>
            </td>
            <td data-order="<?= $st === 'harvesting' ? '1' : ($st === 'growing' ? '2' : '3') ?>">
              <span class="badge-status <?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>">
                <?= gf_icon($st === 'harvesting' ? 'harvest' : ($st === 'growing' ? 'plant' : 'empty'), 'badge-ico') ?>
                <?= $statusLabel[$st] ?>
              </span>
            </td>
            <td data-order="<?= htmlspecialchars($plant ? substr($plant, 0, 10) : '9999-99-99', ENT_QUOTES, 'UTF-8') ?>">
              <?= h_ymd($plant) ?>
            </td>
            <td data-order="<?= htmlspecialchars($expected ? substr($expected, 0, 10) : '9999-99-99', ENT_QUOTES, 'UTF-8') ?>">
              <?= h_ymd($expected) ?>
            </td>
            <td class="text-end" data-order="<?= $kg !== null ? (int)round((float)$kg) : 999999 ?>">
              <?= $kg !== null ? htmlspecialchars((string)(int)round((float)$kg), ENT_QUOTES, 'UTF-8') : '—' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$beds): ?>
    <p class="text-muted small">条件に合うベッドがありません。</p>
  <?php endif; ?>

  <h2 class="section-title"><?= gf_icon('calendar') ?> 7週カレンダー</h2>
  <div class="table-responsive mb-3" style="max-height:320px;">
    <table class="table table-bordered text-center small align-middle mb-0">
      <thead>
        <tr>
          <th>ベッド</th>
          <?php foreach ($weekLabels as $wl): ?>
            <th><?= htmlspecialchars($wl, ENT_QUOTES, 'UTF-8') ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($beds as $bed): ?>
          <tr>
            <th class="text-nowrap">
              <a href="bed_cycles.php?bed_id=<?= (int)$bed['id'] ?>"><?= htmlspecialchars($bed['name'], ENT_QUOTES, 'UTF-8') ?></a>
            </th>
            <?php for ($i = 0; $i < 7; $i++): $ws = strtotime("+$i week", $weekStart); [$cls, $label] = week_status($bed['cycle'], $ws); ?>
              <td class="<?= $cls ?>"><?= $label ?></td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php forecast_nav('monitor'); ?>
<script>
(() => {
  const table = document.getElementById('monBeds');
  if (!table || !table.tHead || !table.tBodies[0]) return;
  const tbody = table.tBodies[0];
  const headers = Array.from(table.tHead.rows[0].cells);
  const info = document.getElementById('monBedsInfo');
  const qEl = document.getElementById('monBedsQ');
  let sortCol = 3;
  let sortDir = 1;

  const cellVal = (tr, i) => {
    const td = tr.cells[i];
    return td ? (td.getAttribute('data-order') || td.textContent.trim()) : '';
  };

  const normalizeQ = (s) =>
    String(s || '')
      .trim()
      .toLowerCase()
      .replace(/[\uFF10-\uFF19]/g, (ch) => String.fromCharCode(ch.charCodeAt(0) - 0xFF10 + 0x30))
      .replace(/[\u2212\u2010-\u2015\uFF0D\u30FC]/g, '-')
      .replace(/\s+/g, '');

  const bedKey = (tr) => {
    const td = tr.cells[0];
    if (!td) return '';
    const raw = td.getAttribute('data-order') || td.querySelector('a')?.textContent || '';
    return normalizeQ(raw);
  };

  const apply = () => {
    const needle = normalizeQ(qEl.value);
    const list = Array.from(tbody.rows);
    list.sort((a, b) => {
      const av = cellVal(a, sortCol);
      const bv = cellVal(b, sortCol);
      const an = Number(av);
      const bn = Number(bv);
      let cmp;
      if (av !== '' && bv !== '' && av !== '—' && bv !== '—' && !Number.isNaN(an) && !Number.isNaN(bn)) {
        cmp = an - bn;
      } else {
        cmp = String(av).localeCompare(String(bv), 'ja', { numeric: true });
      }
      return cmp * sortDir;
    });
    let shown = 0;
    list.forEach((tr) => {
      const show = !needle || bedKey(tr).includes(needle);
      tr.hidden = !show;
      if (show) shown += 1;
      tbody.appendChild(tr);
    });
    headers.forEach((th, i) => {
      th.classList.toggle('is-sorted', i === sortCol);
      th.classList.toggle('is-asc', i === sortCol && sortDir === 1);
      th.classList.toggle('is-desc', i === sortCol && sortDir === -1);
      th.setAttribute('aria-sort', i === sortCol ? (sortDir === 1 ? 'ascending' : 'descending') : 'none');
    });
    if (info) {
      info.textContent = needle ? (shown + ' / ' + list.length + '件') : (list.length + '件 · 見出しで並べ替え');
    }
  };

  headers.forEach((th, i) => {
    th.tabIndex = 0;
    th.addEventListener('click', () => {
      if (sortCol === i) sortDir *= -1;
      else {
        sortCol = i;
        sortDir = 1;
      }
      apply();
    });
    th.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        th.click();
      }
    });
  });
  if (qEl) qEl.addEventListener('input', apply);
  apply();
})();
</script>
</body>
</html>
