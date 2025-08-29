<?php
require_once '../db.php';
require_once __DIR__ . '/../lib/build_features.php';
$selected_user_id = $_COOKIE['gf_fc_useit_id'] ?? '';
if (!$selected_user_id && !empty($_SERVER['HTTP_COOKIE'])) {
    foreach (explode('; ', $_SERVER['HTTP_COOKIE']) as $cookie) {
        [$name, $value] = explode('=', $cookie, 2);
        if ($name === 'gf_fc_useit_id') {
            $selected_user_id = $value;
            break;
        }
    }
}


if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset(
        $_POST['bed_id'],
        $_POST['harvest_date'],
        $_POST['harvest_kg'],
        $_POST['loss_type_id'],
        $_POST['harvest_ratio'],
        $_POST['user_id'],
        $_POST['size_eval']
    )
) {
    $stmt = mysqli_prepare(
        $link,
        "INSERT INTO harvests (cycle_id, harvest_date, harvest_kg, loss_type_id, user_id, harvest_ratio, size_eval, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param(
        $stmt,
        'isdiidss',
        $_POST['cycle_id'],
        $_POST['harvest_date'],
        $_POST['harvest_kg'],
        $_POST['loss_type_id'],
        $_POST['user_id'],
        $_POST['harvest_ratio'],
        $_POST['size_eval'],
        $_POST['note']
    );
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // also record collection for sales adjustment trigger
    $stmt = mysqli_prepare(
        $link,
        "INSERT INTO collections (cycle_id, pickup_date, amount_kg) VALUES (?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'isd', $_POST['cycle_id'], $_POST['harvest_date'], $_POST['harvest_kg']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    // rebuild features cache with updated sales_adjust_days
    try {
        rebuild_features_for_cycle($pdo, (int)$_POST['cycle_id']);
    } catch (Throwable $e) {
        // silently ignore for now
    }

    setcookie('gf_fc_useit_id', $_POST['user_id'], time() + (60 * 60 * 24 * 14), '/');
    $selected_user_id = $_POST['user_id'];
    echo "<div class='alert alert-success text-center m-3'>収穫データを登録しました。</div>";
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>収穫入力（マスタDB対応）</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/mobile-ui.css">
</head>
<body class="pb-5">
<div class="container py-4">
  <h4 class="mb-4 text-primary">🌱 収穫入力</h4>
  <form method="POST">
    <!-- 登録者 -->
    <div class="mb-4">
      <label for="user_id" class="form-label fs-5">登録者（担当）</label>
      <select class="form-select form-select-lg" name="user_id" id="user_id" required onchange="saveUserCookie()">
        <option value="">選択してください</option>
        <?php
        $res = mysqli_query($link, "SELECT id, name FROM users WHERE active=1 ORDER BY name");
        while ($u = mysqli_fetch_assoc($res)) {
            $sel = ($u['id'] == $selected_user_id) ? 'selected' : '';
            echo "<option value='{$u['id']}' {$sel}>{$u['name']}</option>";
        }
        ?>
      </select>
    </div>

    <!-- ベッド -->
    <div class="mb-4">
      <label for="bed" class="form-label fs-5">ベッド名</label>
      <select id="bed" name="bed_id" class="form-select form-select-lg" required>
        <option value="">選択してください</option>
        <?php
        $res = mysqli_query($link, "SELECT id, name FROM beds WHERE active=1 ORDER BY name");
        while ($b = mysqli_fetch_assoc($res)) {
            echo "<option value='{$b['id']}'>{$b['name']}</option>";
        }
        ?>
      </select>
    </div>

    <!-- サイクル履歴表示 -->
    <div id="cycle_history" class="mb-4 p-3 bg-light border rounded">
      <h6>サイクル履歴</h6>
      <div id="cycle_history_content">選択したベッドの履歴を表示します。</div>
    </div>

    <!-- 収穫日 -->
    <div class="mb-4">
      <label for="harvest_date" class="form-label fs-5">収穫日</label>
      <input type="date" id="harvest_date" name="harvest_date" class="form-control form-control-lg" required>
    </div>

    <!-- 面積比 -->
    <div class="mb-4">
      <label class="form-label fs-5">収穫面積比</label><br>
      <div class="btn-group w-100" role="group">
        <input type="radio" class="btn-check" name="harvest_ratio" id="r1" value="0.25" required>
        <label class="btn btn-outline-secondary" for="r1">1/4</label>
        <input type="radio" class="btn-check" name="harvest_ratio" id="r2" value="0.33">
        <label class="btn btn-outline-secondary" for="r2">1/3</label>
        <input type="radio" class="btn-check" name="harvest_ratio" id="r3" value="0.5">
        <label class="btn btn-outline-secondary" for="r3">1/2</label>
        <input type="radio" class="btn-check" name="harvest_ratio" id="r4" value="0.66">
        <label class="btn btn-outline-secondary" for="r4">2/3</label>
        <input type="radio" class="btn-check" name="harvest_ratio" id="r5" value="0.75">
        <label class="btn btn-outline-secondary" for="r5">3/4</label>
        <input type="radio" class="btn-check" name="harvest_ratio" id="r6" value="1.0">
        <label class="btn btn-outline-secondary" for="r6">全体</label>
      </div>
    </div>

    <!-- 収穫量 -->
    <div class="mb-4">
      <label for="harvest_kg" class="form-label fs-5">収穫量（kg）</label>
      <input id="harvest_kg" type="number" step="0.1" class="form-control form-control-lg" name="harvest_kg" required>
    </div>

    <!-- 状態 -->
    <div class="mb-4">
      <label class="form-label fs-5">状態</label><br>
      <div class="btn-group w-100" role="group">
        <input type="radio" class="btn-check" name="size_eval" id="s1" value="big" required>
        <label class="btn btn-outline-secondary" for="s1">大きめ</label>
        <input type="radio" class="btn-check" name="size_eval" id="s2" value="normal">
        <label class="btn btn-outline-secondary" for="s2">適当</label>
        <input type="radio" class="btn-check" name="size_eval" id="s3" value="small">
        <label class="btn btn-outline-secondary" for="s3">小さめ</label>
      </div>
    </div>

    <!-- 廃棄・ゴミ区分 -->
    <div class="mb-4">
      <label for="loss_type_id" class="form-label fs-5">廃棄・ゴミ区分</label>
      <select id="loss_type_id" class="form-select form-select-lg" name="loss_type_id" required>
        <?php
        $res = mysqli_query($link, "SELECT id, name FROM loss_types ORDER BY id");
        while ($lt = mysqli_fetch_assoc($res)) {
            echo "<option value='{$lt['id']}'>{$lt['name']}</option>";
        }
        ?>
      </select>
    </div>

    <!-- 備考 -->
    <div class="mb-4">
      <label for="note" class="form-label fs-5">備考</label>
      <textarea id="note" class="form-control" name="note" rows="3"></textarea>
    </div>

    <input type="hidden" name="cycle_id" id="cycle_id" value="">
    <div class="d-grid">
      <button type="submit" class="btn btn-primary btn-lg">登録</button>
    </div>
  </form>
</div>

<nav class="navbar fixed-bottom bg-light border-top">
  <div class="container-fluid">
    <div class="d-flex justify-content-around w-100">
      <a href="../index.php" class="text-center nav-link"><div>🏠</div><small>ホーム</small></a>
      <a href="../monitor.php" class="text-center nav-link"><div>🌱</div><small>栽培状況</small></a>
      <a href="../inventory.php" class="text-center nav-link"><div>📊</div><small>在庫</small></a>
      <a href="../plan.php" class="text-center nav-link"><div>📅</div><small>計画</small></a>
      <a href="../settings.php" class="text-center nav-link"><div>⚙️</div><small>設定</small></a>
    </div>
  </div>
</nav>

<script>
function saveUserCookie() {
  const userId = document.getElementById('user_id').value;
  console.log('saveUserCookie called with userId:', userId);
  if (userId) {
    const days = 14;
    const d = new Date();
    d.setTime(d.getTime() + (days*24*60*60*1000));
    document.cookie = "gf_fc_useit_id=" + userId + "; expires=" + d.toUTCString() + "; path=/";
    console.log('gf_fc_useit_id cookie saved for', days, 'days');
  } else {
    console.log('gf_fc_useit_id not set, cookie not saved');
  }
}

function getCookie(name) {
  const value = `; ${document.cookie}`;
  const parts = value.split(`; ${name}=`);
  if (parts.length === 2) {
    return parts.pop().split(';').shift();
  }
  return '';
}

document.addEventListener('DOMContentLoaded', () => {
  const savedUserId = getCookie('gf_fc_useit_id');
  if (savedUserId) {
    const userSelect = document.getElementById('user_id');
    if (userSelect) {
      userSelect.value = savedUserId;
    }
    console.log('Loaded gf_fc_useit_id cookie:', savedUserId);
  } else {
    console.log('gf_fc_useit_id cookie not found on load');
  }
});

document.getElementById('harvest_date').valueAsDate = new Date();

document.getElementById('bed').addEventListener('change', function() {
  const bedId = this.value;
  if (!bedId) return;

  fetch('get_cycle_history.php?bed_id=' + bedId)
    .then(response => response.json())
    .then(data => {
      let html = `<p>播種日: ${data.sow_date || '-'}</p>`;
      html += `<p>定植日: ${data.plant_date || '-'}</p>`;
      if (data.harvests && data.harvests.length > 0) {
        html += '<ul>';
        data.harvests.forEach(h => {
          html += `<li>${h.harvest_date} - ${h.harvest_kg}kg</li>`;
        });
        html += '</ul>';
      } else {
        html += '<p>部分収穫はまだありません。</p>';
      }
      document.getElementById('cycle_history_content').innerHTML = html;
      document.getElementById('cycle_history').style.display = 'block';
      if (data.cycle_id) {
        document.getElementById('cycle_id').value = data.cycle_id;
      }
    });
});
</script>
</body>
</html>
