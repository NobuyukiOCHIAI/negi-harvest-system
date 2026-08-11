<?php
/**
 * Google Calendar 出荷予定の同期。
 * CLI: php jobs/sync_gcal_shipments.php [--force]
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/gcal_shipments.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$force = in_array('--force', $argv ?? [], true);

try {
    $r = gcal_sync_shipments($link, $force, $force ? 0 : GCAL_SHIPMENTS_TTL_SEC);
    if ($r['skipped']) {
        echo "SKIP within TTL last={$r['synced_at']}\n";
        exit(0);
    }
    echo "OK {$r['message']} at={$r['synced_at']}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
