<?php
require_once __DIR__ . '/../api/json_utils.php';

function getAsof($link) {
    $res = mysqli_query($link, "SELECT LEAST(CURDATE(), MAX(date)) AS asof FROM weather_daily");
    if (!$res) { return null; }
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    return $row['asof'] ?? null;
}

function aggregateTemperature($link, $plantDate, $asof) {
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
              AVG(variation) AS swing_avg,
              STDDEV_POP(variation) AS swing_std
            FROM weather_daily
            WHERE date BETWEEN ? AND ?";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $d1, $d2);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    mysqli_stmt_close($stmt);
    return $row ?: [];
}

function findRecentPeerStats($link, $groupType) {
    foreach ([5,10,14] as $win) {
        foreach ([1,0] as $strict) {
            $sql = "SELECT
                      AVG(t.total_yield) AS peer_mean_total,
                      AVG(t.days_to_first) AS peer_mean_days,
                      COUNT(*) AS k
                    FROM (
                      SELECT c2.id,
                             DATEDIFF(c2.harvest_start, c2.plant_date) AS days_to_first,
                             (SELECT SUM(h.harvest_kg) FROM harvests h WHERE h.cycle_id=c2.id) AS total_yield
                      FROM cycles c2 JOIN beds b2 ON c2.bed_id=b2.id
                      WHERE c2.harvest_end BETWEEN DATE_SUB(CURDATE(), INTERVAL ? DAY) AND CURDATE()
                        AND (? = 0 OR b2.group_type = ?)
                    ) t";
            $stmt = mysqli_prepare($link, $sql);
            mysqli_stmt_bind_param($stmt, 'iis', $win, $strict, $groupType);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $row = mysqli_fetch_assoc($res);
            mysqli_free_result($res);
            mysqli_stmt_close($stmt);
            if ($row && (int)$row['k'] >= 1) {
                return [
                    'peer_mean_total' => (float)$row['peer_mean_total'],
                    'peer_mean_days'  => (float)$row['peer_mean_days'],
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
    $res = mysqli_query($link, $sql);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($res) { mysqli_free_result($res); }
    return [
        'peer_mean_total' => (float)($row['peer_mean_total'] ?? 0),
        'peer_mean_days'  => (float)($row['peer_mean_days'] ?? 0),
        'k' => (int)($row['k'] ?? 0)
    ];
}

function findYOY($link, $bedId, $plantDate, $groupType) {
    $target = date('Y-m-d', strtotime($plantDate . ' -1 year'));
    $start  = date('Y-m-d', strtotime($target . ' -5 days'));
    $end    = date('Y-m-d', strtotime($target . ' +5 days'));
    $base = "SELECT
                AVG(t.total_yield) AS yoy_mean_total,
                AVG(t.days_to_first) AS yoy_mean_days,
                COUNT(*) AS k
              FROM (
                SELECT c2.id,
                       DATEDIFF(c2.harvest_start, c2.plant_date) AS days_to_first,
                       (SELECT SUM(h.harvest_kg) FROM harvests h WHERE h.cycle_id=c2.id) AS total_yield
                FROM cycles c2 JOIN beds b2 ON c2.bed_id=b2.id
                WHERE c2.plant_date BETWEEN ? AND ?";
    $sql = $base . " AND c2.bed_id = ?) t";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'ssi', $start, $end, $bedId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    mysqli_stmt_close($stmt);
    if ($row && (int)$row['k'] >= 1) {
        return [
            'yoy_mean_total' => (float)$row['yoy_mean_total'],
            'yoy_mean_days'  => (float)$row['yoy_mean_days'],
            'k' => (int)$row['k']
        ];
    }
    $sql = $base . " AND b2.group_type = ?) t";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $start, $end, $groupType);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    mysqli_stmt_close($stmt);
    if ($row && (int)$row['k'] >= 1) {
        return [
            'yoy_mean_total' => (float)$row['yoy_mean_total'],
            'yoy_mean_days'  => (float)$row['yoy_mean_days'],
            'k' => (int)$row['k']
        ];
    }
    $sql = $base . ") t";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $start, $end);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    mysqli_stmt_close($stmt);
    return [
        'yoy_mean_total' => (float)($row['yoy_mean_total'] ?? 0),
        'yoy_mean_days'  => (float)($row['yoy_mean_days'] ?? 0),
        'k' => (int)($row['k'] ?? 0)
    ];
}

function build_features_array($link, $cycleId, $asofDate = null) {
    $asof = $asofDate ?: getAsof($link);
    if (!$asof) { throw new RuntimeException('asof not found'); }

    $sql = "SELECT c.id, c.bed_id, c.sow_date, c.plant_date, c.harvest_start, c.harvest_end,
                   COALESCE(c.sales_adjust_days,0) AS sales_adjust_days,
                   b.group_type
            FROM cycles c
            JOIN beds b ON b.id = c.bed_id
            WHERE c.id = ?
            LIMIT 1";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $cycleId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $c = mysqli_fetch_assoc($res);
    mysqli_free_result($res);
    mysqli_stmt_close($stmt);
    if (!$c) { throw new RuntimeException("cycle not found: {$cycleId}"); }

    $plantDate = $c['plant_date'];
    $sowDate   = $c['sow_date'];
    $groupType = $c['group_type'];
    $bedId     = (int)$c['bed_id'];

    $temp = aggregateTemperature($link, $plantDate, $asof);
    if (($temp['temp_avg_mean'] ?? null) === null) { throw new RuntimeException('temperature data missing'); }

    $peer = findRecentPeerStats($link, $groupType);
    $yoy  = findYOY($link, $bedId, $plantDate, $groupType);
    if (($yoy['k'] ?? 0) === 0) {
        $yoy['yoy_mean_total'] = $peer['peer_mean_total'];
        $yoy['yoy_mean_days']  = $peer['peer_mean_days'];
    }

    $nurseryDays = $sowDate ? (int)((strtotime($plantDate) - strtotime($sowDate)) / 86400) : 21;
    $plantMonth  = (int)date('n', strtotime($plantDate));
    $groupNormal = ($groupType === 'normal' || $groupType === '通常') ? 1 : 0;

    $features = [
        '育苗日数'         => $nurseryDays,
        '定植月'           => $plantMonth,
        'グループ_通常'     => $groupNormal,
        '気温_平均'        => (float)$temp['temp_avg_mean'],
        '気温_最大'        => (float)$temp['temp_max_max'],
        '気温_最小'        => (float)$temp['temp_min_min'],
        '気温_std'         => (float)$temp['temp_avg_std'],
        '気温振れ幅_平均'  => (float)$temp['swing_avg'],
        '気温振れ幅_std'   => (float)$temp['swing_std'],
        '類似ベッド_平均収量' => (float)$peer['peer_mean_total'],
        '類似ベッド_平均日数' => (float)$peer['peer_mean_days'],
        '前年同時期収量'   => (float)$yoy['yoy_mean_total'],
        '前年同時期日数'   => (float)$yoy['yoy_mean_days'],
        '収量差_前年'       => (float)$peer['peer_mean_total'] - (float)$yoy['yoy_mean_total'],
        '日数差_前年'       => (float)$peer['peer_mean_days'] - (float)$yoy['yoy_mean_days'],
        '営業調整日数'      => (int)$c['sales_adjust_days'],
    ];

    return [$features, $asof];
}

function save_features_cache($link, $cycleId, $asof, $features) {
    $payload = ['features' => $features];
    $json = encode_json($payload);
    $hash = hash('sha256', $cycleId . '|' . $asof . '|' . $json);
    $sql = "INSERT INTO features_cache (cycle_id, asof, features_json, hash)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE features_json = VALUES(features_json)";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'isss', $cycleId, $asof, $json, $hash);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

function rebuild_features_for_cycle($link, $cycleId, $asofDate = null) {
    list($features, $asof) = build_features_array($link, $cycleId, $asofDate);
    save_features_cache($link, $cycleId, $asof, $features);
    return $features;
}
