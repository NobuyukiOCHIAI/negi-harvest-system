<?php
/**
 * 未完了サイクル全件の再特徴量化＋midモデル再予測（③④）。
 * CLI: php jobs/repredict_open_cycles.php
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/build_features.php';
require_once __DIR__ . '/../lib/predict_ridge.php';
require_once __DIR__ . '/../api/logging.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$res = mysqli_query(
    $link,
    "SELECT id FROM cycles WHERE harvest_end IS NULL ORDER BY plant_date ASC, id ASC"
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
        $out = rebuild_and_predict_cycle($link, $cycleId, true, 'mid');
        $ok++;
        echo sprintf(
            "OK cycle=%d days=%.1f yield=%.1f postproc=%s\n",
            $cycleId,
            $out['pred']['days'],
            $out['pred']['yield'],
            $out['postproc_total_kg'] === null ? 'null' : (string)$out['postproc_total_kg']
        );
    } catch (Throwable $e) {
        $fail++;
        echo sprintf("FAIL cycle=%d %s\n", $cycleId, $e->getMessage());
        if (function_exists('log_error')) {
            log_error('repredict_open failed', [
                'cycle_id' => $cycleId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

echo sprintf("DONE ok=%d fail=%d total=%d\n", $ok, $fail, count($ids));
