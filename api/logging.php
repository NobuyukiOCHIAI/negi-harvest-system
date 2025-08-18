<?php
function log_error(string $message, string $filename = 'app.log'): void {
    $dir = '/home/love-media/forc_logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message\n", 3, $dir . '/' . $filename);
}
?>
