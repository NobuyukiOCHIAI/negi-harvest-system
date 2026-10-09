<?php
/**
 * 予測行が無い未完了サイクルだけ、定植時①を書く。
 * 定植当日に気温末日が前日だと登録時に保留になるため、気温同期のあとにも走る。
 * CLI: php jobs/fill_missing_plant_preds.php
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/predict_ridge.php';
require_once __DIR__ . '/../api/logging.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$res = mysqli_query(
    $link,
    "SELECT c.id
     FROM cycles c
     WHERE c.harvest_end IS NULL
       AND c.plant_date IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM predictions p WHERE p.cycle_id = c.id)
     ORDER BY c.plant_date ASC, c.id ASC"
);
$ids = [];
while ($row = mysqli_fetch_assoc($res)) {
    $ids[] = (int)$row['id'];
}
mysqli_free_result($res);

$ok = 0;
$fail = 0;
foreach ($ids as $cycleId) {
    try {
        $out = rebuild_and_predict_cycle($link, $cycleId, false, 'plant');
        $ok++;
        echo sprintf(
            "OK cycle=%d days=%.1f yield=%.1f model=%s\n",
            $cycleId,
            $out['pred']['days'],
            $out['pred']['yield'],
            $out['pred']['model_id']
        );
    } catch (Throwable $e) {
        $fail++;
        echo sprintf("FAIL cycle=%d %s\n", $cycleId, $e->getMessage());
        if (function_exists('log_error')) {
            log_error('fill_missing_plant_preds failed', [
                'cycle_id' => $cycleId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

echo sprintf("DONE ok=%d fail=%d total=%d\n", $ok, $fail, count($ids));
