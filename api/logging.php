<?php
function log_error($message, array $ctx = []) {
    $dir = '/home/love-media/forc_logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/app_' . date('Ymd') . '.log';
    $line = date('c') . ' ' . $message;
    if ($ctx) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
