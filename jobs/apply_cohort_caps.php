<?php
/**
 * 未収穫サイクルへ同世代補正の上限行を足す（モデル再計算はしない）。
 * CLI: php jobs/apply_cohort_caps.php
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/cohort_adjust.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$r = gf_cohort_apply_open_caps($link);
echo sprintf("inserted=%d skipped=%d\n", $r['inserted'], $r['skipped']);
