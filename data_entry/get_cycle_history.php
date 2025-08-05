<?php
require '../db.php';
$bed_id = intval($_GET['bed_id'] ?? 0);

$stmt = mysqli_prepare($link, "SELECT id, sow_date, plant_date FROM cycles WHERE bed_id=? ORDER BY plant_date DESC LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $bed_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_bind_result($stmt, $cycle_id, $sow_date, $plant_date);
if (mysqli_stmt_fetch($stmt)) {
    $data = ['cycle_id' => $cycle_id, 'sow_date' => $sow_date, 'plant_date' => $plant_date];
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($link, "SELECT harvest_date, harvest_kg FROM harvests WHERE cycle_id=? ORDER BY harvest_date ASC");
    mysqli_stmt_bind_param($stmt, 'i', $cycle_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $data['harvests'] = mysqli_fetch_all($result, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
} else {
    $data = ['cycle_id' => null, 'sow_date' => null, 'plant_date' => null, 'harvests' => []];
}
echo json_encode($data);
?>
