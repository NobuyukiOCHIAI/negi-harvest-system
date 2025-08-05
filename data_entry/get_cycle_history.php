<?php
include '../db.php';
$bed_id = intval($_GET['bed_id']);
$stmt = mysqli_prepare($link, "
    SELECT date, action FROM (
        SELECT c.plant_date AS date, '定植' AS action
        FROM cycles c
        WHERE c.id = (SELECT id FROM cycles WHERE bed_id = ? ORDER BY plant_date DESC LIMIT 1)
        UNION ALL
        SELECT h.harvest_date AS date, '収穫' AS action
        FROM harvests h
        WHERE h.cycle_id = (SELECT id FROM cycles WHERE bed_id = ? ORDER BY plant_date DESC LIMIT 1)
    ) AS latest
    ORDER BY date ASC
");
mysqli_stmt_bind_param($stmt, 'ii', $bed_id, $bed_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
echo json_encode(mysqli_fetch_all($result, MYSQLI_ASSOC));
mysqli_stmt_close($stmt);
?>
