<?php
/**
 * Cycle harvest state machine helpers.
 *
 * States (derived):
 *   growing    = plant_date set, harvest_start IS NULL
 *   harvesting = harvest_start set, harvest_end IS NULL
 *   closed     = harvest_end set
 *
 * Close rule (agreed operational model):
 *   SUM(harvest_ratio) >= 0.999  OR  latest ratio >= 1.0
 *   → harvest_end = that harvest_date
 */
require_once __DIR__ . '/../api/json_utils.php';

/**
 * Recalculate harvest_start / harvest_end from harvests rows.
 *
 * @return array{harvest_start:?string,harvest_end:?string,ratio_sum:float,status:string}
 */
function refresh_cycle_harvest_state(mysqli $link, int $cycleId): array
{
    $stmt = mysqli_prepare(
        $link,
        "SELECT harvest_date, harvest_ratio
         FROM harvests
         WHERE cycle_id = ?
         ORDER BY harvest_date ASC, id ASC"
    );
    mysqli_stmt_bind_param($stmt, 'i', $cycleId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $rows = mysqli_fetch_all($res, MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);

    if (!$rows) {
        $stmt = mysqli_prepare(
            $link,
            "UPDATE cycles SET harvest_start = NULL, harvest_end = NULL WHERE id = ?"
        );
        mysqli_stmt_bind_param($stmt, 'i', $cycleId);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return [
            'harvest_start' => null,
            'harvest_end' => null,
            'ratio_sum' => 0.0,
            'status' => 'growing',
        ];
    }

    $start = $rows[0]['harvest_date'];
    $ratioSum = 0.0;
    $end = null;
    foreach ($rows as $row) {
        $ratio = (float)($row['harvest_ratio'] ?? 0);
        $ratioSum += $ratio;
        if ($end === null && ($ratio >= 1.0 - 1e-6 || $ratioSum >= 0.999)) {
            $end = $row['harvest_date'];
        }
    }
    // If ratios never reached 1 but we want last date only when explicitly closed —
    // leave harvest_end null when sum < 0.999.

    $stmt = mysqli_prepare(
        $link,
        "UPDATE cycles SET harvest_start = ?, harvest_end = ? WHERE id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'ssi', $start, $end, $cycleId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $status = 'harvesting';
    if ($end !== null) {
        $status = 'closed';
    }

    return [
        'harvest_start' => $start,
        'harvest_end' => $end,
        'ratio_sum' => round($ratioSum, 4),
        'status' => $status,
    ];
}

/**
 * Open (unfinished) cycle for a bed, else null.
 */
function find_open_cycle_for_bed(mysqli $link, int $bedId): ?array
{
    $stmt = mysqli_prepare(
        $link,
        "SELECT id, sow_date, plant_date, harvest_start, harvest_end
         FROM cycles
         WHERE bed_id = ? AND harvest_end IS NULL
         ORDER BY plant_date DESC, id DESC
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $bedId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    return $row ?: null;
}
