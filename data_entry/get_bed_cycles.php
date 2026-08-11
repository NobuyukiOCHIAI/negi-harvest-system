<?php
/**
 * ベッド単位のサイクル一覧（収穫入力の直近表示用）
 */
require_once '../db.php';
require_once __DIR__ . '/../lib/cycle_state.php';

header('Content-Type: application/json; charset=utf-8');

$bedId = (int)($_GET['bed_id'] ?? 0);
if ($bedId <= 0) {
    echo json_encode(['cycles' => [], 'error' => 'bed_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$gomiId = null;
$gr = mysqli_query(
    $link,
    "SELECT id FROM loss_types WHERE name IN ('GOMI','ゴミ','gomi') ORDER BY id ASC LIMIT 1"
);
if ($gr && ($grow = mysqli_fetch_assoc($gr))) {
    $gomiId = (int)$grow['id'];
}
if ($gr) {
    mysqli_free_result($gr);
}

$sql = "
SELECT
  c.id AS cycle_id,
  c.bed_id,
  b.name AS bed_name,
  c.sow_date,
  c.plant_date,
  c.harvest_start,
  c.harvest_end
FROM cycles c
JOIN beds b ON b.id = c.bed_id
WHERE c.bed_id = ?
  AND (
    c.harvest_end IS NULL
    OR c.harvest_end >= DATE_SUB(CURDATE(), INTERVAL 21 DAY)
  )
ORDER BY
  CASE WHEN c.harvest_end IS NULL THEN 0 ELSE 1 END ASC,
  COALESCE(c.harvest_end, c.plant_date) DESC,
  c.id DESC
LIMIT 8
";
$stmt = mysqli_prepare($link, $sql);
mysqli_stmt_bind_param($stmt, 'i', $bedId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$cycles = [];
while ($row = mysqli_fetch_assoc($res)) {
    $cid = (int)$row['cycle_id'];
    $hs = [];
    $totalKg = 0.0;
    $ratioSum = 0.0;
    $hst = mysqli_prepare(
        $link,
        "SELECT id, harvest_date, harvest_kg, harvest_ratio, size_eval, loss_type_id
         FROM harvests WHERE cycle_id = ?
         ORDER BY harvest_date DESC, id DESC"
    );
    mysqli_stmt_bind_param($hst, 'i', $cid);
    mysqli_stmt_execute($hst);
    $hr = mysqli_stmt_get_result($hst);
    while ($h = mysqli_fetch_assoc($hr)) {
        $hs[] = [
            'id' => (int)$h['id'],
            'harvest_date' => $h['harvest_date'],
            'harvest_kg' => (float)$h['harvest_kg'],
            'harvest_ratio' => $h['harvest_ratio'] !== null ? (float)$h['harvest_ratio'] : null,
            'size_eval' => $h['size_eval'],
            'is_gomi' => ($gomiId !== null && (int)($h['loss_type_id'] ?? 0) === $gomiId),
        ];
        $totalKg += (float)$h['harvest_kg'];
        $ratioSum += (float)($h['harvest_ratio'] ?? 0);
    }
    mysqli_stmt_close($hst);

    $open = ($row['harvest_end'] === null);
    $status = 'closed';
    if ($open) {
        $status = ($row['harvest_start'] === null) ? 'growing' : 'harvesting';
    }

    $cycles[] = [
        'cycle_id' => $cid,
        'bed_id' => (int)$row['bed_id'],
        'bed_name' => $row['bed_name'],
        'sow_date' => $row['sow_date'],
        'plant_date' => $row['plant_date'],
        'harvest_start' => $row['harvest_start'],
        'harvest_end' => $row['harvest_end'],
        'open' => $open,
        'status' => $status,
        'total_kg' => round($totalKg, 2),
        'ratio_sum' => round($ratioSum, 4),
        'ratio_remaining' => round(max(0, 1 - $ratioSum), 4),
        'harvests' => $hs,
        'selectable' => $open,
    ];
}
mysqli_stmt_close($stmt);

echo json_encode([
    'bed_id' => $bedId,
    'cycles' => $cycles,
    'gomi_loss_type_id' => $gomiId,
], JSON_UNESCAPED_UNICODE);
