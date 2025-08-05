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
<style>
body { font-family: sans-serif; margin: 10px; padding: 5px; font-size: 16px; }
label { display: block; margin-top: 10px; }
select, input { width: 100%; padding: 5px; font-size: 16px; }
button { margin-top: 15px; padding: 10px; font-size: 16px; width: 100%; }
.ratio-group { display: flex; justify-content: space-between; margin-top: 10px; }
.ratio-group label { flex: 1; text-align: center; }
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
<h2>収穫登録</h2>
<form method="post">
    <label>従業員</label>
    <select name="user_id" onchange="this.form.submit()">
        <option value="">選択してください</option>
        <?php
        $result = mysqli_query($link, "SELECT id, name FROM users");
        while ($u = mysqli_fetch_assoc($result)) {
            $sel = ($u['id'] == $selectedUserId) ? 'selected' : '';
            echo "<option value='{$u['id']}' {$sel}>{$u['name']}</option>";
        }
        ?>
    </select>

    <label>ベッド</label>
    <select name="bed_id" id="bed_id" onchange="loadCycleHistory()"></select>

    <label>収穫日</label>
    <input type="date" name="harvest_date" required>

    <label>収穫量(kg)</label>
    <input type="number" step="0.1" name="harvest_kg" required>

    <label>廃棄区分</label>
    <select name="loss_type_id" id="loss_type_id"></select>

    <label>収穫面積割合</label>
    <div class="ratio-group">
        <label><input type="radio" name="harvest_ratio" value="0.25">1/4</label>
        <label><input type="radio" name="harvest_ratio" value="0.33">1/3</label>
        <label><input type="radio" name="harvest_ratio" value="0.5">1/2</label>
        <label><input type="radio" name="harvest_ratio" value="0.66">2/3</label>
        <label><input type="radio" name="harvest_ratio" value="0.75">3/4</label>
        <label><input type="radio" name="harvest_ratio" value="1.0">全面</label>
    </div>

    <input type="hidden" name="cycle_id" value="1">
    <button type="submit">登録</button>
</form>

<div id="cycle_history" style="margin-top:20px;"></div>
</body>
</html>
