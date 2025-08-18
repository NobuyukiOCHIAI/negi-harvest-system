<?php
// Database connection using PDO
$dsn = 'mysql:host=localhost;dbname=database_name;charset=utf8mb4';
$pdo = new PDO($dsn, 'username', 'password', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function getEnvOrDefault($key, $default = null) {
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
}

function getAsof(PDO $pdo): ?string {
    $stmt = $pdo->query("SELECT LEAST(CURDATE(), MAX(date)) AS asof FROM weather_daily");
    $asof = $stmt->fetchColumn();
    return $asof ?: null;
}

function aggregateTemperature(PDO $pdo, string $plantDate, string $asof): array {
    if ($asof >= $plantDate) {
        $d1 = $plantDate;
        $d2 = $asof;
    } else {
        $d1 = date('Y-m-d', strtotime($asof . ' -6 days'));
        $d2 = $asof;
    }
    $sql = "SELECT
              AVG(temp_avg) AS temp_avg_mean,
              MAX(temp_max) AS temp_max_max,
              MIN(temp_min) AS temp_min_min,
              STDDEV_POP(temp_avg) AS temp_avg_std,
              AVG(COALESCE(variation, temp_max-temp_min)) AS swing_avg,
              STDDEV_POP(COALESCE(variation, temp_max-temp_min)) AS swing_std
            FROM weather_daily
            WHERE date BETWEEN :d1 AND :d2";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['d1' => $d1, 'd2' => $d2]);
    $row = $stmt->fetch();
    return $row ?: [];
}

function findRecentPeerStats(PDO $pdo, string $groupType): array {
    foreach ([5, 10, 14] as $win) {
        foreach ([1, 0] as $strict) {
            $sql = "SELECT
                      AVG(t.total_yield) AS peer_mean_total,
                      AVG(t.days_to_first) AS peer_mean_days,
                      COUNT(*) AS k
                    FROM (
                      SELECT c2.id,
                             DATEDIFF(c2.harvest_start, c2.plant_date) AS days_to_first,
                             (SELECT SUM(h.harvest_kg) FROM harvests h WHERE h.cycle_id=c2.id) AS total_yield
                      FROM cycles c2 JOIN beds b2 ON c2.bed_id=b2.id
                      WHERE c2.harvest_end BETWEEN DATE_SUB(CURDATE(), INTERVAL :win DAY) AND CURDATE()
                        AND (:strict = 0 OR b2.group_type = :groupType)
                    ) t";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['win' => $win, 'strict' => $strict, 'groupType' => $groupType]);
            $row = $stmt->fetch();
            if ($row && (int)$row['k'] >= 1) {
                return [
                    'peer_mean_total' => (float)$row['peer_mean_total'],
                    'peer_mean_days' => (float)$row['peer_mean_days'],
                    'k' => (int)$row['k']
                ];
            }
        }
    }

    $sql = "SELECT
              AVG(t.total_yield) AS peer_mean_total,
              AVG(t.days_to_first) AS peer_mean_days,
              COUNT(*) AS k
            FROM (
              SELECT c2.id,
                     DATEDIFF(c2.harvest_start, c2.plant_date) AS days_to_first,
                     (SELECT SUM(h.harvest_kg) FROM harvests h WHERE h.cycle_id=c2.id) AS total_yield
              FROM cycles c2
              WHERE c2.harvest_start IS NOT NULL AND c2.harvest_end IS NOT NULL
            ) t";
    $row = $pdo->query($sql)->fetch();
    return [
        'peer_mean_total' => (float)($row['peer_mean_total'] ?? 0),
        'peer_mean_days' => (float)($row['peer_mean_days'] ?? 0),
        'k' => (int)($row['k'] ?? 0)
    ];
}

function findYOY(PDO $pdo, int $bedId, string $plantDate, string $groupType): array {
    $target = date('Y-m-d', strtotime($plantDate . ' -1 year'));
    $start = date('Y-m-d', strtotime($target . ' -5 days'));
    $end   = date('Y-m-d', strtotime($target . ' +5 days'));

    $base = "SELECT
                AVG(t.total_yield) AS yoy_mean_total,
                AVG(t.days_to_first) AS yoy_mean_days,
                COUNT(*) AS k
              FROM (
                SELECT c2.id,
                       DATEDIFF(c2.harvest_start, c2.plant_date) AS days_to_first,
                       (SELECT SUM(h.harvest_kg) FROM harvests h WHERE h.cycle_id=c2.id) AS total_yield
                FROM cycles c2 JOIN beds b2 ON c2.bed_id=b2.id
                WHERE c2.plant_date BETWEEN :start AND :end";

    $sql = $base . " AND c2.bed_id = :bedId) t";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['start' => $start, 'end' => $end, 'bedId' => $bedId]);
    $row = $stmt->fetch();
    if ($row && (int)$row['k'] >= 1) {
        return [
            'yoy_mean_total' => (float)$row['yoy_mean_total'],
            'yoy_mean_days' => (float)$row['yoy_mean_days'],
            'k' => (int)$row['k']
        ];
    }

    $sql = $base . " AND b2.group_type = :groupType) t";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['start' => $start, 'end' => $end, 'groupType' => $groupType]);
    $row = $stmt->fetch();
    if ($row && (int)$row['k'] >= 1) {
        return [
            'yoy_mean_total' => (float)$row['yoy_mean_total'],
            'yoy_mean_days' => (float)$row['yoy_mean_days'],
            'k' => (int)$row['k']
        ];
    }

    $sql = $base . ") t";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['start' => $start, 'end' => $end]);
    $row = $stmt->fetch();
    return [
        'yoy_mean_total' => (float)($row['yoy_mean_total'] ?? 0),
        'yoy_mean_days' => (float)($row['yoy_mean_days'] ?? 0),
        'k' => (int)($row['k'] ?? 0)
    ];
}

function buildFeaturesForPlanting(PDO $pdo, int $cycleId): ?array {
    $stmt = $pdo->prepare("SELECT c.id, c.plant_date, c.sow_date, c.bed_id, b.group_type FROM cycles c JOIN beds b ON c.bed_id=b.id WHERE c.id=?");
    $stmt->execute([$cycleId]);
    $c = $stmt->fetch();
    if (!$c) { return null; }

    $plantDate = $c['plant_date'];
    $sowDate = $c['sow_date'];
    $groupType = $c['group_type'];
    $bedId = (int)$c['bed_id'];

    $asof = getAsof($pdo);
    if (!$asof) { return null; }

    $temp = aggregateTemperature($pdo, $plantDate, $asof);
    if (($temp['temp_avg_mean'] ?? null) === null) { return null; }

    $peer = findRecentPeerStats($pdo, $groupType);
    $yoy  = findYOY($pdo, $bedId, $plantDate, $groupType);
    if (($yoy['k'] ?? 0) === 0) {
        $yoy['yoy_mean_total'] = $peer['peer_mean_total'];
        $yoy['yoy_mean_days'] = $peer['peer_mean_days'];
    }

    $nurseryDays = $sowDate ? (int)((strtotime($plantDate) - strtotime($sowDate)) / 86400) : 21;
    $plantMonth = (int)date('n', strtotime($plantDate));
    $groupNormal = $groupType === '通常' ? 1 : 0;

    $features = [
        '育苗日数' => $nurseryDays,
        '定植月' => $plantMonth,
        'グループ_通常' => $groupNormal,
        '気温_平均' => (float)$temp['temp_avg_mean'],
        '気温_最大' => (float)$temp['temp_max_max'],
        '気温_最小' => (float)$temp['temp_min_min'],
        '気温_std' => (float)$temp['temp_avg_std'],
        '気温振れ幅_平均' => (float)$temp['swing_avg'],
        '気温振れ幅_std' => (float)$temp['swing_std'],
        '類似ベッド_平均収量' => (float)$peer['peer_mean_total'],
        '類似ベッド_平均日数' => (float)$peer['peer_mean_days'],
        '前年同時期収量' => (float)$yoy['yoy_mean_total'],
        '前年同時期日数' => (float)$yoy['yoy_mean_days'],
        '収量差_前年' => (float)$peer['peer_mean_total'] - (float)$yoy['yoy_mean_total'],
        '日数差_前年' => (float)$peer['peer_mean_days'] - (float)$yoy['yoy_mean_days'],
        '営業調整日数' => 0
    ];

    $featuresJson = json_encode($features, JSON_UNESCAPED_UNICODE);
    $hash = hash('sha256', $featuresJson);
    $stmt = $pdo->prepare("INSERT INTO features_cache (cycle_id, asof, features_json, hash) VALUES (?, ?, ?, ?)" );
    $stmt->execute([$cycleId, $asof, $featuresJson, $hash]);

    return $features;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();

        $bedId = (int)$_POST['bed_id'];
        $sow   = $_POST['sow_date'] ?? null;
        $plant = $_POST['plant_date'];

        $stmt = $pdo->prepare("INSERT INTO cycles (bed_id, sow_date, plant_date, status) VALUES (?, ?, ?, 'planted')");
        $stmt->execute([$bedId, $sow, $plant]);
        $cycleId = (int)$pdo->lastInsertId();

        $features = buildFeaturesForPlanting($pdo, $cycleId);
        if ($features === null) {
            $stmt = $pdo->prepare("INSERT INTO alerts (date, type, payload_json, status) VALUES (CURDATE(),'data_missing', JSON_OBJECT('cycle_id', ?), 'open')");
            $stmt->execute([$cycleId]);
            $pdo->commit();
            header('Location: /forecast/cycle.php?id=' . $cycleId . '&msg=temp_pending');
            exit;
        }

        $apiUrl = getEnvOrDefault('XGB_API_URL', 'http://tk2-118-59530.vs.sakura.ne.jp/xgbapi/api/predict_both');
        $apiKey = getEnvOrDefault('XGB_API_KEY', '');
        $payload = json_encode(['data' => [['features' => $features]]], JSON_UNESCAPED_UNICODE);

        $attempt = 0;
        $json = null;
        $delay = 1;
        while ($attempt < 3) {
            $attempt++;
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ["Content-Type: application/json", "x-api-key: " . $apiKey],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            if ($res !== false) {
                $json = json_decode($res, true);
                if (($json['ok'] ?? false)) { break; }
            }
            if ($attempt < 3) { sleep($delay); $delay *= 2; }
        }
        if (!($json['ok'] ?? false)) {
            throw new Exception('xgbapi response not ok');
        }

        $pred = $json['predictions'][0] ?? null;
        if (!$pred) { throw new Exception('no predictions'); }

        $stmt = $pdo->prepare("INSERT INTO predictions (cycle_id, model_id, pred_days, pred_total_kg) VALUES (?, ?, ?, ?)");
        $modelId = basename(dirname($json['model_path_days'] ?? 'current'));
        $stmt->execute([$cycleId, $modelId, $pred['days'], $pred['yield']]);

        $pdo->prepare("UPDATE cycles SET expected_harvest = DATE_ADD(plant_date, INTERVAL ROUND(?) DAY) WHERE id=?")
            ->execute([$pred['days'], $cycleId]);

        $pdo->commit();
        header('Location: /forecast/cycle.php?id=' . $cycleId . '&msg=predicted');
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[planting] failed: ' . $e->getMessage());
        header('Location: /forecast/data_entry/planting.php?error=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>定植入力</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="../css/mobile-ui.css">
</head>
<body class="pb-5">
<div class="container py-4">
  <h4 class="mb-4 text-primary">🌱 定植入力</h4>
  <form method="POST">
    <div class="mb-4">
      <label for="plant_date" class="form-label fs-5">定植日</label>
      <input type="date" id="plant_date" name="plant_date" class="form-control form-control-lg" required>
    </div>
    <div class="mb-4">
      <label for="bed" class="form-label fs-5">ベッド名</label>
      <select id="bed" name="bed_id" class="form-select form-select-lg" required>
        <option value="">選択してください</option>
        <?php
        $stmt = $pdo->query("SELECT id, name FROM beds WHERE active=1 ORDER BY name");
        while ($b = $stmt->fetch()) {
            echo "<option value='{$b['id']}'>{$b['name']}</option>";
        }
        ?>
      </select>
    </div>
    <div class="mb-4">
      <label for="sow_date" class="form-label fs-5">播種日</label>
      <input type="date" id="sow_date" name="sow_date" class="form-control form-control-lg">
      <div class="form-text">育苗日数: <span id="nursery_days">0</span>日</div>
    </div>
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
function calcDays(){
  const plant = new Date(document.getElementById('plant_date').value);
  const sow = new Date(document.getElementById('sow_date').value);
  if(!isNaN(plant) && !isNaN(sow)){
    const diff = (plant - sow)/(1000*60*60*24);
    document.getElementById('nursery_days').innerText = diff;
  }
}
document.getElementById('plant_date').addEventListener('change', calcDays);
document.getElementById('sow_date').addEventListener('change', calcDays);
</script>
</body>
</html>

