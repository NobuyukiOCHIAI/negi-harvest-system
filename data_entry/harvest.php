<?php
include '../db.php';

$selectedUserId = isset($_COOKIE['selected_user_id']) ? intval($_COOKIE['selected_user_id']) : null;

if (isset($_POST['user_id']) && $_POST['user_id'] !== '') {
    $selectedUserId = intval($_POST['user_id']);
    setcookie('selected_user_id', $selectedUserId, time() + (14 * 24 * 60 * 60), '/');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bed_id'], $_POST['harvest_date'], $_POST['harvest_kg'], $_POST['loss_type_id'], $_POST['harvest_ratio'])) {
    $stmt = mysqli_prepare($link, "INSERT INTO harvests (cycle_id, harvest_date, harvest_kg, loss_type_id, user_id, harvest_ratio) VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'isd iid', $_POST['cycle_id'], $_POST['harvest_date'], $_POST['harvest_kg'], $_POST['loss_type_id'], $selectedUserId, $_POST['harvest_ratio']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    echo "<p>収穫データを登録しました。</p>";
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>収穫登録</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body { font-family: sans-serif; margin: 10px; padding: 5px; font-size: 16px; }
.ratio-group { display: flex; justify-content: space-between; margin-top: 10px; }
@media (max-width: 768px) {
    .form-group {
      margin-bottom: 1rem;
    }
    .form-label {
      display: block;
      margin-bottom: 0.5rem;
      font-size: 1rem;
    }
    .form-control,
    .form-select {
      font-size: 1rem;
      padding: 0.75rem;
      min-height: 44px;
    }
    .form-check-input {
      width: 1.25rem;
      height: 1.25rem;
    }
    .form-check-label {
      font-size: 1rem;
      margin-left: 0.5rem;
    }
    .btn {
      font-size: 1rem;
      padding: 0.75rem;
      min-height: 44px;
    }
    .btn-block {
      display: block;
      width: 100%;
    }
    .btn + .btn {
      margin-top: 0.5rem;
    }
  }
</style>
<script>
function loadBeds() {
    fetch('get_beds.php').then(res => res.json()).then(data => {
        let bedSelect = document.getElementById('bed_id');
        bedSelect.innerHTML = '';
        data.forEach(b => {
            let opt = document.createElement('option');
            opt.value = b.id;
            opt.textContent = b.name;
            bedSelect.appendChild(opt);
        });
    });
}
function loadLossTypes() {
    fetch('get_loss_types.php').then(res => res.json()).then(data => {
        let lossSelect = document.getElementById('loss_type_id');
        lossSelect.innerHTML = '';
        data.forEach(l => {
            let opt = document.createElement('option');
            opt.value = l.id;
            opt.textContent = l.name;
            lossSelect.appendChild(opt);
        });
    });
}
function loadCycleHistory() {
    let bedId = document.getElementById('bed_id').value;
    fetch('get_cycle_history.php?bed_id=' + bedId).then(res => res.json()).then(data => {
        let histDiv = document.getElementById('cycle_history');
        histDiv.innerHTML = '<h4>サイクル履歴</h4>';
        data.forEach(h => {
            histDiv.innerHTML += '<div>' + h.date + ' - ' + h.action + '</div>';
        });
    });
}
window.onload = function() {
    loadBeds();
    loadLossTypes();
}
</script>
</head>
<body>
<div class="container">
<h2 class="mb-4">収穫登録</h2>
<form method="post">
    <div class="form-group">
        <label for="user_id" class="form-label">従業員</label>
        <select name="user_id" id="user_id" class="form-select" onchange="this.form.submit()">
            <option value="">選択してください</option>
            <?php
            $result = mysqli_query($link, "SELECT id, name FROM users");
            while ($u = mysqli_fetch_assoc($result)) {
                $sel = ($u['id'] == $selectedUserId) ? 'selected' : '';
                echo "<option value='{$u['id']}' {$sel}>{$u['name']}</option>";
            }
            ?>
        </select>
    </div>

    <div class="form-group">
        <label for="bed_id" class="form-label">ベッド</label>
        <select name="bed_id" id="bed_id" class="form-select" onchange="loadCycleHistory()"></select>
    </div>

    <div class="form-group">
        <label for="harvest_date" class="form-label">収穫日</label>
        <input type="date" name="harvest_date" id="harvest_date" class="form-control" required>
    </div>

    <div class="form-group">
        <label for="harvest_kg" class="form-label">収穫量(kg)</label>
        <input type="number" step="0.1" name="harvest_kg" id="harvest_kg" class="form-control" required>
    </div>

    <div class="form-group">
        <label for="loss_type_id" class="form-label">廃棄区分</label>
        <select name="loss_type_id" id="loss_type_id" class="form-select"></select>
    </div>

    <div class="form-group">
        <label class="form-label">収穫面積割合</label>
        <div class="ratio-group">
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="harvest_ratio" id="ratio25" value="0.25">
                <label class="form-check-label" for="ratio25">1/4</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="harvest_ratio" id="ratio33" value="0.33">
                <label class="form-check-label" for="ratio33">1/3</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="harvest_ratio" id="ratio50" value="0.5">
                <label class="form-check-label" for="ratio50">1/2</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="harvest_ratio" id="ratio66" value="0.66">
                <label class="form-check-label" for="ratio66">2/3</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="harvest_ratio" id="ratio75" value="0.75">
                <label class="form-check-label" for="ratio75">3/4</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="radio" name="harvest_ratio" id="ratio100" value="1.0">
                <label class="form-check-label" for="ratio100">全面</label>
            </div>
        </div>
    </div>

    <input type="hidden" name="cycle_id" value="1">
    <button type="submit" class="btn btn-primary btn-lg btn-block">登録</button>
</form>

<div id="cycle_history" class="mt-4"></div>
</div>
</body>
</html>
