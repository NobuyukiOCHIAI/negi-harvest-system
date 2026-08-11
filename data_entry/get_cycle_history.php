<?php
require_once '../db.php';
require_once __DIR__ . '/../lib/cycle_state.php';

header('Content-Type: application/json; charset=utf-8');

$bedId = (int)($_GET['bed_id'] ?? 0);
if ($bedId <= 0) {
    echo json_encode([
        'cycle_id' => null,
        'error' => 'bed_id required',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cycle = find_open_cycle_for_bed($link, $bedId);
if (!$cycle) {
    // Fall back: show latest closed cycle info but not selectable for harvest
    $stmt = mysqli_prepare(
        $link,
        "SELECT id, sow_date, plant_date, harvest_start, harvest_end
         FROM cycles WHERE bed_id = ?
         ORDER BY plant_date DESC, id DESC LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $bedId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $latest = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if (!$latest) {
        echo json_encode([
            'cycle_id' => null,
            'sow_date' => null,
            'plant_date' => null,
            'harvests' => [],
            'status' => null,
            'ratio_sum' => 0,
            'ratio_remaining' => 1,
            'total_kg' => 0,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'cycle_id' => (int)$latest['id'],
        'sow_date' => $latest['sow_date'],
        'plant_date' => $latest['plant_date'],
        'harvest_start' => $latest['harvest_start'],
        'harvest_end' => $latest['harvest_end'],
        'harvests' => [],
        'status' => 'closed',
        'ratio_sum' => 1,
        'ratio_remaining' => 0,
        'total_kg' => 0,
        'selectable' => false,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cycleId = (int)$cycle['id'];
$stmt = mysqli_prepare(
    $link,
    "SELECT harvest_date, harvest_kg, harvest_ratio, size_eval
     FROM harvests WHERE cycle_id = ?
     ORDER BY harvest_date ASC, id ASC"
);
mysqli_stmt_bind_param($stmt, 'i', $cycleId);
mysqli_stmt_execute($stmt);
$hres = mysqli_stmt_get_result($stmt);
$harvests = mysqli_fetch_all($hres, MYSQLI_ASSOC);
mysqli_stmt_close($stmt);

$ratioSum = 0.0;
$totalKg = 0.0;
foreach ($harvests as $h) {
    $ratioSum += (float)($h['harvest_ratio'] ?? 0);
    $totalKg += (float)($h['harvest_kg'] ?? 0);
}

$status = ($cycle['harvest_start'] === null) ? 'growing' : 'harvesting';

echo json_encode([
    'cycle_id' => $cycleId,
    'sow_date' => $cycle['sow_date'],
    'plant_date' => $cycle['plant_date'],
    'harvest_start' => $cycle['harvest_start'],
    'harvest_end' => $cycle['harvest_end'],
    'harvests' => $harvests,
    'status' => $status,
    'ratio_sum' => round($ratioSum, 4),
    'ratio_remaining' => round(max(0, 1 - $ratioSum), 4),
    'total_kg' => round($totalKg, 2),
    'selectable' => true,
], JSON_UNESCAPED_UNICODE);
