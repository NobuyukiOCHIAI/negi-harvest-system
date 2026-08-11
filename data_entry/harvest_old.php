<?php
require_once '../db.php';
$selected_user_id = $_COOKIE['user_id'] ?? '';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <title>収穫入力（マスタDB対応）</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container py-4">
  <h4 class="mb-4 text-primary">🌱 収穫入力</h4>

  <form action="confirm.php" method="POST">

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
    <div id="cycle_history" class="mb-4 p-3 bg-light border rounded" style="display:none;">
      <h6>サイクル履歴</h6>
      <div id="cycle_history_content">選択したベッドの履歴を表示します。</div>
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
      <input type="number" step="0.1" class="form-control form-control-lg" name="harvest_kg" required>
    </div>

    <!-- 廃棄・ゴミ区分 -->
    <div class="mb-4">
      <label for="loss_type_id" class="form-label fs-5">廃棄・ゴミ区分</label>
      <select class="form-select form-select-lg" name="loss_type_id" required>
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
      <textarea class="form-control" name="note" rows="3"></textarea>
    </div>

    <!-- ボタン -->
    <div class="d-grid">
      <button type="submit" class="btn btn-primary btn-lg">次へ</button>
    </div>
  </form>
</div>

<script>
function saveUserCookie() {
  const userId = document.getElementById('user_id').value;
  if (userId) {
    const days = 14;
    const d = new Date();
    d.setTime(d.getTime() + (days*24*60*60*1000));
    document.cookie = "user_id=" + userId + "; expires=" + d.toUTCString() + "; path=/";
  }
}

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
    });
});
</script>

</body>
</html>
