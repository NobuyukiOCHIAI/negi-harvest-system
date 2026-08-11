<?php
/**
 * Google Calendar 出荷予定の同期 API。
 * GET/POST ?force=1 で TTL 無視。
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/gcal_shipments.php';
require_once __DIR__ . '/json_utils.php';

header('Content-Type: application/json; charset=utf-8');

$force = isset($_GET['force']) || isset($_POST['force']);
$r = gcal_ensure_fresh_shipments($link, $force);
if ($r['error']) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => $r['error'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'synced' => $r['synced'],
    'skipped' => $r['skipped'],
    'events' => $r['events'],
    'weeks' => $r['weeks'],
    'message' => $r['message'],
    'synced_at' => $r['synced_at'],
], JSON_UNESCAPED_UNICODE);
