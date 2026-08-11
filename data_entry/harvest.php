<?php
/**
 * 収穫入力 — 簡易入力 + 直近サイクル訂正
 */
require_once '../db.php';
require_once __DIR__ . '/../lib/build_features.php';
require_once __DIR__ . '/../lib/predict_ridge.php';
require_once __DIR__ . '/../lib/cycle_state.php';
require_once __DIR__ . '/../api/logging.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$selected_user_id = $_COOKIE['gf_fc_useit_id'] ?? '';
$message = null;
$messageType = 'success';
$preCycleId = (int)($_GET['cycle_id'] ?? 0);
$editHarvestId = (int)($_GET['edit'] ?? 0);
$preBedId = 0;

/**
 * @return int|null GOMI loss_type id
 */
function harvest_gomi_loss_type_id(mysqli $link): ?int
{
    $res = mysqli_query(
        $link,
        "SELECT id FROM loss_types WHERE name IN ('GOMI','ゴミ','gomi') ORDER BY id ASC LIMIT 1"
    );
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($res) {
        mysqli_free_result($res);
    }
    return $row ? (int)$row['id'] : null;
}

$gomiLossId = harvest_gomi_loss_type_id($link);

$editRow = null;
if ($editHarvestId > 0) {
    $st = mysqli_prepare(
        $link,
        "SELECT h.*, c.bed_id, c.harvest_end, b.name AS bed_name
         FROM harvests h
         JOIN cycles c ON c.id = h.cycle_id
         JOIN beds b ON b.id = c.bed_id
         WHERE h.id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($st, 'i', $editHarvestId);
    mysqli_stmt_execute($st);
    $rr = mysqli_stmt_get_result($st);
    $editRow = mysqli_fetch_assoc($rr) ?: null;
    mysqli_stmt_close($st);
    if ($editRow) {
        $preCycleId = (int)$editRow['cycle_id'];
        $preBedId = (int)$editRow['bed_id'];
    }
}

if ($preCycleId > 0 && $preBedId <= 0) {
    $st = mysqli_prepare($link, 'SELECT bed_id FROM cycles WHERE id=? LIMIT 1');
    mysqli_stmt_bind_param($st, 'i', $preCycleId);
    mysqli_stmt_execute($st);
    $rr = mysqli_stmt_get_result($st);
    if ($row = mysqli_fetch_assoc($rr)) {
        $preBedId = (int)$row['bed_id'];
    }
    mysqli_stmt_close($st);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $cycleId = (int)($_POST['cycle_id'] ?? 0);
    $bedId = (int)($_POST['bed_id'] ?? 0);
    $harvestDate = $_POST['harvest_date'] ?? '';
    $harvestKg = (float)($_POST['harvest_kg'] ?? 0);
    $harvestRatio = (float)($_POST['harvest_ratio'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);
    $sizeEval = $_POST['size_eval'] ?? 'normal';
    $note = $_POST['note'] ?? '';
    $lossChoice = $_POST['loss_choice'] ?? 'none'; // none | gomi
    $harvestId = (int)($_POST['harvest_id'] ?? 0);

    if ($sizeEval === '') {
        $sizeEval = 'normal';
    }

    $lossTypeId = null;
    if ($lossChoice === 'gomi') {
        if ($gomiLossId === null) {
            $messageType = 'danger';
            $message = 'ゴミ区分（loss_types）が未登録です。';
        } else {
            $lossTypeId = $gomiLossId;
        }
    }

    try {
        if ($messageType === 'danger' && $message) {
            throw new RuntimeException($message);
        }
        if ($cycleId <= 0 || $bedId <= 0 || $harvestDate === '' || $harvestKg <= 0 || $harvestRatio <= 0 || $userId <= 0) {
            throw new InvalidArgumentException('必須項目が不足しています。');
        }
        if (!in_array($sizeEval, ['big', 'normal', 'small'], true)) {
            throw new InvalidArgumentException('状態の値が不正です。');
        }
        if (!in_array($lossChoice, ['none', 'gomi'], true)) {
            throw new InvalidArgumentException('廃棄区分が不正です。');
        }

        mysqli_begin_transaction($link);

        if ($action === 'update') {
            if ($harvestId <= 0) {
                throw new InvalidArgumentException('訂正対象がありません。');
            }
            $stmt = mysqli_prepare(
                $link,
                "SELECT h.*, c.bed_id, c.harvest_end
                 FROM harvests h
                 JOIN cycles c ON c.id = h.cycle_id
                 WHERE h.id = ? FOR UPDATE"
            );
            mysqli_stmt_bind_param($stmt, 'i', $harvestId);
            mysqli_stmt_execute($stmt);
            $ores = mysqli_stmt_get_result($stmt);
            $old = mysqli_fetch_assoc($ores);
            mysqli_stmt_close($stmt);
            if (!$old) {
                throw new RuntimeException('訂正対象の収穫が見つかりません。');
            }
            $cycleId = (int)$old['cycle_id'];
            $bedId = (int)$old['bed_id'];

            if ($lossTypeId === null) {
                $stmt = mysqli_prepare(
                    $link,
                    "UPDATE harvests
                     SET harvest_date=?, harvest_kg=?, loss_type_id=NULL, user_id=?,
                         harvest_ratio=?, size_eval=?, note=?
                     WHERE id=?"
                );
                mysqli_stmt_bind_param(
                    $stmt,
                    'sdidssi',
                    $harvestDate,
                    $harvestKg,
                    $userId,
                    $harvestRatio,
                    $sizeEval,
                    $note,
                    $harvestId
                );
            } else {
                $stmt = mysqli_prepare(
                    $link,
                    "UPDATE harvests
                     SET harvest_date=?, harvest_kg=?, loss_type_id=?, user_id=?,
                         harvest_ratio=?, size_eval=?, note=?
                     WHERE id=?"
                );
                mysqli_stmt_bind_param(
                    $stmt,
                    'sdiidssi',
                    $harvestDate,
                    $harvestKg,
                    $lossTypeId,
                    $userId,
                    $harvestRatio,
                    $sizeEval,
                    $note,
                    $harvestId
                );
            }
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            // 集荷の粗い同期: 旧日付・旧量を消し、非ゴミなら新値を入れる
            $oldDate = $old['harvest_date'];
            $oldKg = (float)$old['harvest_kg'];
            $stmt = mysqli_prepare(
                $link,
                "DELETE FROM collections
                 WHERE cycle_id=? AND pickup_date=? AND ABS(amount_kg - ?) < 0.05
                 LIMIT 1"
            );
            mysqli_stmt_bind_param($stmt, 'isd', $cycleId, $oldDate, $oldKg);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($lossChoice !== 'gomi') {
                $stmt = mysqli_prepare(
                    $link,
                    'INSERT INTO collections (cycle_id, pickup_date, amount_kg) VALUES (?, ?, ?)'
                );
                mysqli_stmt_bind_param($stmt, 'isd', $cycleId, $harvestDate, $harvestKg);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            $state = refresh_cycle_harvest_state($link, $cycleId);
            try {
                rebuild_and_predict_cycle($link, $cycleId, true, 'mid');
            } catch (Throwable $e) {
                log_error('harvest update predict failed', [
                    'cycle_id' => $cycleId,
                    'error' => $e->getMessage(),
                ]);
            }
            mysqli_commit($link);
            setcookie('gf_fc_useit_id', (string)$userId, time() + (60 * 60 * 24 * 14), '/');
            $selected_user_id = (string)$userId;
            $message = sprintf(
                '収穫を訂正しました。（累積面積比 %.0f%%%s）',
                $state['ratio_sum'] * 100,
                $state['status'] === 'closed' ? '・完了' : ''
            );
            $editHarvestId = 0;
            $editRow = null;
        } else {
            // create
            $stmt = mysqli_prepare(
                $link,
                'SELECT id, bed_id, harvest_end FROM cycles WHERE id = ? FOR UPDATE'
            );
            mysqli_stmt_bind_param($stmt, 'i', $cycleId);
            mysqli_stmt_execute($stmt);
            $cres = mysqli_stmt_get_result($stmt);
            $cycle = mysqli_fetch_assoc($cres);
            mysqli_stmt_close($stmt);

            if (!$cycle || (int)$cycle['bed_id'] !== $bedId) {
                throw new RuntimeException('サイクルが見つからないか、ベッドと一致しません。');
            }
            if ($cycle['harvest_end'] !== null) {
                throw new RuntimeException('このサイクルは収穫完了済みです。新しい定植を登録してください。');
            }

            if ($lossTypeId === null) {
                $stmt = mysqli_prepare(
                    $link,
                    "INSERT INTO harvests
                      (cycle_id, harvest_date, harvest_kg, loss_type_id, user_id, harvest_ratio, size_eval, note)
                     VALUES (?, ?, ?, NULL, ?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param(
                    $stmt,
                    'isdidss',
                    $cycleId,
                    $harvestDate,
                    $harvestKg,
                    $userId,
                    $harvestRatio,
                    $sizeEval,
                    $note
                );
            } else {
                $stmt = mysqli_prepare(
                    $link,
                    "INSERT INTO harvests
                      (cycle_id, harvest_date, harvest_kg, loss_type_id, user_id, harvest_ratio, size_eval, note)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                mysqli_stmt_bind_param(
                    $stmt,
                    'isdiidss',
                    $cycleId,
                    $harvestDate,
                    $harvestKg,
                    $lossTypeId,
                    $userId,
                    $harvestRatio,
                    $sizeEval,
                    $note
                );
            }
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            if ($lossChoice !== 'gomi') {
                $stmt = mysqli_prepare(
                    $link,
                    'INSERT INTO collections (cycle_id, pickup_date, amount_kg) VALUES (?, ?, ?)'
                );
                mysqli_stmt_bind_param($stmt, 'isd', $cycleId, $harvestDate, $harvestKg);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            $state = refresh_cycle_harvest_state($link, $cycleId);
            try {
                rebuild_and_predict_cycle($link, $cycleId, true, 'mid');
            } catch (Throwable $e) {
                log_error('harvest predict failed', [
                    'cycle_id' => $cycleId,
                    'error' => $e->getMessage(),
                ]);
            }
            mysqli_commit($link);
            setcookie('gf_fc_useit_id', (string)$userId, time() + (60 * 60 * 24 * 14), '/');
            $selected_user_id = (string)$userId;
            if ($state['status'] === 'closed') {
                require_once __DIR__ . '/../lib/supply_ops.php';
                $ens = supply_ensure_full_rotation($link, true);
                $message = sprintf(
                    '収穫を登録し、サイクルを完了しました。（累積面積比 %.0f%%）%s',
                    $state['ratio_sum'] * 100,
                    $ens['created'] > 0 ? ' · 空き床へ常時回転を自動投入' : ''
                );
            } else {
                $message = sprintf(
                    '途中収穫を登録しました。（累積面積比 %.0f%% / 残り約 %.0f%%）',
                    $state['ratio_sum'] * 100,
                    max(0, 1 - $state['ratio_sum']) * 100
                );
            }
        }
    } catch (Throwable $e) {
        if (isset($link) && $link instanceof mysqli) {
            @mysqli_rollback($link);
        }
        $messageType = 'danger';
        $message = ($action === 'update' ? '訂正に失敗しました: ' : '登録に失敗しました: ') . $e->getMessage();
        log_error('harvest failed', ['error' => $e->getMessage(), 'action' => $action]);
    }
}

$isEdit = ($editRow !== null);
$formSize = $isEdit ? (string)($editRow['size_eval'] ?: 'normal') : 'normal';
$formLossGomi = $isEdit && $gomiLossId !== null && (int)($editRow['loss_type_id'] ?? 0) === $gomiLossId;
$formRatio = $isEdit ? (float)$editRow['harvest_ratio'] : null;
$formDate = $isEdit ? (string)$editRow['harvest_date'] : '';
$formKg = $isEdit ? (string)$editRow['harvest_kg'] : '';
$formNote = $isEdit ? (string)($editRow['note'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title><?= $isEdit ? '収穫訂正' : '収穫入力' ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/mobile-ui.css">
  <style>
    .recent-cycle {
      background:#fff; border:1px solid var(--gf-line,#d5e0d9); border-radius:12px;
      padding:0.65rem 0.75rem; margin-bottom:0.55rem;
    }
    .recent-cycle.open { border-left:4px solid #1b7a4a; }
    .recent-cycle.closed { border-left:4px solid #9e9e9e; opacity:0.95; }
    .recent-cycle .title { font-weight:800; font-size:0.95rem; }
    .recent-cycle .meta { font-size:0.78rem; color:#5a655e; }
    .recent-cycle ul { margin:0.35rem 0 0; padding-left:1.1rem; font-size:0.8rem; }
    .recent-cycle.selected { outline: 2px solid #1b7a4a; }
    .pick-btn { font-size:0.78rem; font-weight:700; }
  </style>
</head>
<body class="pb-5">
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0 text-primary"><?= $isEdit ? '収穫訂正' : '収穫入力' ?></h4>
    <a class="btn btn-sm btn-outline-success" href="../today.php">今日の作業へ</a>
  </div>
  <?php if ($message): ?>
    <div class="alert alert-<?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <?php if ($isEdit): ?>
    <div class="alert alert-warning py-2">
      訂正モード: <?= htmlspecialchars($editRow['bed_name'], ENT_QUOTES, 'UTF-8') ?>
      · 収穫#<?= (int)$editRow['id'] ?>
      <a class="ms-2" href="harvest.php">新規入力に戻る</a>
    </div>
  <?php endif; ?>

  <form method="POST" id="harvest_form">
    <input type="hidden" name="action" value="<?= $isEdit ? 'update' : 'create' ?>">
    <?php if ($isEdit): ?>
      <input type="hidden" name="harvest_id" value="<?= (int)$editRow['id'] ?>">
    <?php endif; ?>

    <div class="mb-4">
      <label for="user_id" class="form-label fs-5">登録者（担当）</label>
      <select class="form-select form-select-lg" name="user_id" id="user_id" required onchange="saveUserCookie()">
        <option value="">選択してください</option>
        <?php
        $res = mysqli_query($link, 'SELECT id, name FROM users WHERE active=1 ORDER BY name');
        while ($u = mysqli_fetch_assoc($res)) {
            $sel = ((string)$u['id'] === (string)$selected_user_id) ? 'selected' : '';
            echo '<option value="' . (int)$u['id'] . '" ' . $sel . '>' . htmlspecialchars($u['name']) . '</option>';
        }
        ?>
      </select>
    </div>

    <div class="mb-3">
      <label for="bed" class="form-label fs-5">ベッド名</label>
      <select id="bed" name="bed_id" class="form-select form-select-lg" required>
        <option value="">選択してください</option>
        <?php
        $res = mysqli_query($link, 'SELECT id, name FROM beds WHERE active=1 ORDER BY name');
        while ($b = mysqli_fetch_assoc($res)) {
            $sel = ($preBedId > 0 && (int)$b['id'] === $preBedId) ? ' selected' : '';
            echo '<option value="' . (int)$b['id'] . '"' . $sel . '>' . htmlspecialchars($b['name']) . '</option>';
        }
        ?>
      </select>
    </div>

    <div id="recent_cycles_wrap" class="mb-4">
      <h2 class="section-title mb-2" style="font-size:1rem">直近のサイクル</h2>
      <div id="recent_cycles">ベッドを選択すると、そのベッドの直近サイクルを表示します。</div>
    </div>

    <div class="mb-4">
      <label for="harvest_date" class="form-label fs-5">収穫日</label>
      <input type="date" id="harvest_date" name="harvest_date" class="form-control form-control-lg" required
        value="<?= htmlspecialchars($formDate, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="mb-4">
      <label class="form-label fs-5">今回の収穫面積比（ベッド全体に対する割合）</label><br>
      <div class="btn-group w-100 flex-wrap" role="group">
        <?php
        $ratios = [
            '0.25' => '1/4', '0.33' => '1/3', '0.5' => '1/2',
            '0.66' => '2/3', '0.75' => '3/4', '1.0' => '全体（完了）',
        ];
        $i = 0;
        foreach ($ratios as $val => $lab):
            $i++;
            $checked = ($formRatio !== null && abs($formRatio - (float)$val) < 0.02) ? ' checked' : '';
            $req = ($i === 1 && $formRatio === null) ? ' required' : '';
        ?>
          <input type="radio" class="btn-check" name="harvest_ratio" id="r<?= $i ?>" value="<?= $val ?>"<?= $checked ?><?= $req ?>>
          <label class="btn btn-outline-<?= $val === '1.0' ? 'primary' : 'secondary' ?>" for="r<?= $i ?>"><?= $lab ?></label>
        <?php endforeach; ?>
      </div>
      <div class="form-text">累積がおおむね100%に達するとサイクル完了になります。</div>
    </div>

    <div class="mb-4">
      <label for="harvest_kg" class="form-label fs-5">収穫量（kg）</label>
      <input id="harvest_kg" type="number" step="0.1" min="0.1" class="form-control form-control-lg" name="harvest_kg" required
        value="<?= htmlspecialchars($formKg, ENT_QUOTES, 'UTF-8') ?>">
    </div>

    <div class="mb-4">
      <label class="form-label fs-5">状態</label><br>
      <div class="btn-group w-100" role="group">
        <input type="radio" class="btn-check" name="size_eval" id="s1" value="big"<?= $formSize === 'big' ? ' checked' : '' ?>>
        <label class="btn btn-outline-secondary" for="s1">大きめ</label>
        <input type="radio" class="btn-check" name="size_eval" id="s2" value="normal"<?= $formSize === 'normal' ? ' checked' : '' ?> required>
        <label class="btn btn-outline-primary" for="s2">適当</label>
        <input type="radio" class="btn-check" name="size_eval" id="s3" value="small"<?= $formSize === 'small' ? ' checked' : '' ?>>
        <label class="btn btn-outline-secondary" for="s3">小さめ</label>
      </div>
      <div class="form-text">通常は「適当」のままでOKです。</div>
    </div>

    <div class="mb-4">
      <label class="form-label fs-5">廃棄・ゴミ区分</label><br>
      <div class="btn-group w-100" role="group">
        <input type="radio" class="btn-check" name="loss_choice" id="loss_none" value="none"<?= !$formLossGomi ? ' checked' : '' ?> required>
        <label class="btn btn-outline-primary" for="loss_none">なし</label>
        <input type="radio" class="btn-check" name="loss_choice" id="loss_gomi" value="gomi"<?= $formLossGomi ? ' checked' : '' ?>
          <?= $gomiLossId === null ? ' disabled' : '' ?>>
        <label class="btn btn-outline-danger" for="loss_gomi">ゴミ</label>
      </div>
      <?php if ($gomiLossId === null): ?>
        <div class="form-text text-danger">loss_types にゴミが未登録のため「ゴミ」は選べません。</div>
      <?php else: ?>
        <div class="form-text">デフォルトは「なし」。ゴミのときだけ出荷（集荷）に載せません。</div>
      <?php endif; ?>
    </div>

    <div class="mb-4">
      <label for="note" class="form-label fs-5">備考</label>
      <textarea id="note" class="form-control" name="note" rows="2"><?= htmlspecialchars($formNote, ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <input type="hidden" name="cycle_id" id="cycle_id" value="<?= $preCycleId > 0 ? (int)$preCycleId : '' ?>">
    <div class="d-grid">
      <button type="submit" id="submit_btn" class="btn btn-primary btn-lg" <?= ($preCycleId > 0 || $isEdit) ? '' : 'disabled' ?>>
        <?= $isEdit ? '訂正を保存' : '登録' ?>
      </button>
    </div>
  </form>
</div>

<?php
require_once __DIR__ . '/../lib/nav.php';
forecast_nav('today', '../');
?>

<script src="../js/date_display.js"></script>
<script>
function saveUserCookie() {
  const userId = document.getElementById('user_id').value;
  if (!userId) return;
  const d = new Date();
  d.setTime(d.getTime() + (14*24*60*60*1000));
  document.cookie = "gf_fc_useit_id=" + userId + "; expires=" + d.toUTCString() + "; path=/";
}

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
  }[c]));
}

const harvestDateEl = document.getElementById('harvest_date');
if (!harvestDateEl.value) {
  harvestDateEl.valueAsDate = new Date();
}

function loadCyclesForBed(bedId) {
  const box = document.getElementById('recent_cycles');
  const cycleInput = document.getElementById('cycle_id');
  const submitBtn = document.getElementById('submit_btn');
  const isEdit = <?= $isEdit ? 'true' : 'false' ?>;
  const preferCycle = <?= (int)$preCycleId ?>;

  if (!isEdit) {
    cycleInput.value = '';
    submitBtn.disabled = true;
  }
  if (!bedId) {
    box.textContent = 'ベッドを選択すると、そのベッドの直近サイクルを表示します。';
    return;
  }
  box.innerHTML = '<div class="text-muted">読み込み中…</div>';

  fetch('get_bed_cycles.php?bed_id=' + encodeURIComponent(bedId))
    .then(r => r.json())
    .then(data => {
      const cycles = data.cycles || [];
      if (!cycles.length) {
        box.innerHTML = '<p class="text-danger mb-0">このベッドの直近サイクルがありません。先に定植を登録してください。</p>';
        return;
      }

      let html = '';
      let autoCycle = null;
      cycles.forEach(c => {
        const statusLabel = c.status === 'harvesting' ? '収穫中'
          : (c.status === 'growing' ? '栽培中' : '完了');
        html += '<div class="recent-cycle ' + (c.open ? 'open' : 'closed') + '">';
        html += '<div class="d-flex justify-content-between gap-2 align-items-start">';
        html += '<div><div class="title">サイクル #' + escapeHtml(c.cycle_id) + ' · ' + statusLabel + '</div>';
        html += '<div class="meta">定植 ' + escapeHtml(formatMonthWeek(c.plant_date, '-'));
        html += ' · 累計 ' + escapeHtml(c.total_kg) + 'kg';
        html += ' · 面積比 ' + Math.round((c.ratio_sum || 0) * 100) + '%</div></div>';
        if (c.selectable && !isEdit) {
          html += '<button type="button" class="btn btn-sm btn-success pick-btn" data-cycle="' + escapeHtml(c.cycle_id) + '">このサイクルを入力</button>';
        }
        html += '</div>';
        if (c.harvests && c.harvests.length) {
          html += '<ul>';
          c.harvests.slice(0, 6).forEach(h => {
            const pct = h.harvest_ratio != null ? Math.round(h.harvest_ratio * 100) + '%' : '—';
            html += '<li>' + escapeHtml(formatMonthWeek(h.harvest_date, '-'));
            html += ' · ' + escapeHtml(h.harvest_kg) + 'kg · ' + pct;
            if (h.is_gomi) html += ' · ゴミ';
            html += ' <a class="ms-1" href="harvest.php?edit=' + encodeURIComponent(h.id) + '">訂正</a></li>';
          });
          html += '</ul>';
        } else {
          html += '<div class="meta mt-1">収穫記録なし</div>';
        }
        html += '</div>';
        if (c.selectable && autoCycle === null) autoCycle = c.cycle_id;
        if (preferCycle && c.cycle_id === preferCycle) autoCycle = c.cycle_id;
      });
      box.innerHTML = html;

      box.querySelectorAll('.pick-btn').forEach(btn => {
        btn.addEventListener('click', () => {
          const cid = btn.getAttribute('data-cycle');
          cycleInput.value = cid;
          submitBtn.disabled = false;
          box.querySelectorAll('.recent-cycle').forEach(el => el.classList.remove('selected'));
          btn.closest('.recent-cycle')?.classList.add('selected');
        });
      });

      if (!isEdit && autoCycle) {
        cycleInput.value = autoCycle;
        submitBtn.disabled = false;
      } else if (isEdit) {
        submitBtn.disabled = false;
        if (preferCycle) cycleInput.value = preferCycle;
      }
    })
    .catch(() => {
      box.innerHTML = '<p class="text-danger mb-0">サイクルの取得に失敗しました。</p>';
    });
}

document.getElementById('bed').addEventListener('change', function() {
  loadCyclesForBed(this.value);
});

<?php if ($preBedId > 0): ?>
document.getElementById('bed').dispatchEvent(new Event('change'));
<?php endif; ?>
</script>
</body>
</html>
