<?php
require_once __DIR__ . '/../api/json_utils.php';
require_once __DIR__ . '/../db.php'; // provides $pdo

function getAsof(PDO $pdo): ?string {
    $stmt = $pdo->query("SELECT LEAST(CURDATE(), MAX(date)) AS asof FROM weather_daily");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    return $row['asof'] ?? null;
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
    $stmt->execute([':d1' => $d1, ':d2' => $d2]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
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
                        AND (:strict = 0 OR b2.group_type = :gtype)
                    ) t";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':win' => $win, ':strict' => $strict, ':gtype' => $groupType]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
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
    $stmt = $pdo->query($sql);
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
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

    $sql = $base . " AND c2.bed_id = :bid) t";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $start, ':end' => $end, ':bid' => $bedId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)$row['k'] >= 1) {
        return [
            'yoy_mean_total' => (float)$row['yoy_mean_total'],
            'yoy_mean_days' => (float)$row['yoy_mean_days'],
            'k' => (int)$row['k']
        ];
    }

    $sql = $base . " AND b2.group_type = :gtype) t";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $start, ':end' => $end, ':gtype' => $groupType]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && (int)$row['k'] >= 1) {
        return [
            'yoy_mean_total' => (float)$row['yoy_mean_total'],
            'yoy_mean_days' => (float)$row['yoy_mean_days'],
            'k' => (int)$row['k']
        ];
    }

    $sql = $base . ") t";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':start' => $start, ':end' => $end]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'yoy_mean_total' => (float)($row['yoy_mean_total'] ?? 0),
        'yoy_mean_days' => (float)($row['yoy_mean_days'] ?? 0),
        'k' => (int)($row['k'] ?? 0)
    ];
}

function build_features_array(PDO $pdo, int $cycleId, ?string $asofDate = null): array {
    $asof = $asofDate ?: getAsof($pdo);
    if (!$asof) {
        throw new RuntimeException('asof not found');
    }

    $stmt = $pdo->prepare("SELECT c.id, c.bed_id, c.sow_date, c.plant_date, c.harvest_start, c.harvest_end,\n                   COALESCE(c.sales_adjust_days, 0) AS sales_adjust_days,\n                   b.group_type\n            FROM cycles c\n            JOIN beds b ON b.id = c.bed_id\n            WHERE c.id = :cid\n            LIMIT 1");
    $stmt->execute([':cid' => $cycleId]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) { throw new RuntimeException("cycle not found: {$cycleId}"); }

    $plantDate = $c['plant_date'];
    $sowDate = $c['sow_date'];
    $groupType = $c['group_type'];
    $bedId = (int)$c['bed_id'];

    $temp = aggregateTemperature($pdo, $plantDate, $asof);
    if (($temp['temp_avg_mean'] ?? null) === null) {
        throw new RuntimeException('temperature data missing');
    }

    $peer = findRecentPeerStats($pdo, $groupType);
    $yoy  = findYOY($pdo, $bedId, $plantDate, $groupType);
    if (($yoy['k'] ?? 0) === 0) {
        $yoy['yoy_mean_total'] = $peer['peer_mean_total'];
        $yoy['yoy_mean_days'] = $peer['peer_mean_days'];
    }

    $nurseryDays = $sowDate ? (int)((strtotime($plantDate) - strtotime($sowDate)) / 86400) : 21;
    $plantMonth = (int)date('n', strtotime($plantDate));
    $groupNormal = ($groupType === 'normal' || $groupType === '通常') ? 1 : 0;

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
        '営業調整日数' => (int)$c['sales_adjust_days'],
    ];

    return [$features, $asof];
}

function save_features_cache(PDO $pdo, int $cycleId, string $asof, array $features): void {
    $payload = ['features' => $features];
    $json = encode_json($payload);
    $hash = hash('sha256', $cycleId . '|' . $asof . '|' . $json);

    $stmt = $pdo->prepare("INSERT INTO features_cache (cycle_id, asof, features_json, hash) VALUES (:cid, :asof, :json, :hash)\n            ON DUPLICATE KEY UPDATE features_json = VALUES(features_json)");
    $stmt->execute([
        ':cid' => $cycleId,
        ':asof' => $asof,
        ':json' => $json,
        ':hash' => $hash,
    ]);
}

function rebuild_features_for_cycle(PDO $pdo, int $cycleId, ?string $asofDate = null): array {
    [$features, $asof] = build_features_array($pdo, $cycleId, $asofDate);
    save_features_cache($pdo, $cycleId, $asof, $features);
    return $features;
}
