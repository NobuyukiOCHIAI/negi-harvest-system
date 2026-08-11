<?php
/**
 * DB connection for 栽培予測システム.
 *
 * Priority:
 *  1) Environment variables FORECAST_DB_*
 *  2) Local override file db.local.php (gitignored)
 *  3) Safe defaults pointing at MySQL 8.0 (password must come from 1 or 2)
 */
$host = getenv('FORECAST_DB_HOST') ?: '';
$user = getenv('FORECAST_DB_USER') ?: '';
$pass = getenv('FORECAST_DB_PASS') ?: '';
$name = getenv('FORECAST_DB_NAME') ?: '';
$charset = getenv('FORECAST_DB_CHARSET') ?: 'utf8mb4';

$local = __DIR__ . '/db.local.php';
if (is_file($local)) {
    /** @var array $cfg */
    $cfg = require $local;
    $host = $cfg['host'] ?? $host;
    $user = $cfg['user'] ?? $user;
    $pass = $cfg['pass'] ?? $pass;
    $name = $cfg['name'] ?? $name;
    $charset = $cfg['charset'] ?? $charset;
}

if ($host === '' || $user === '' || $pass === '' || $name === '') {
    // Defaults for host/user/name only — password must be supplied.
    $host = $host !== '' ? $host : 'mysql80.love-media.sakura.ne.jp';
    $user = $user !== '' ? $user : 'love-media_forecast';
    $name = $name !== '' ? $name : 'love-media_forecast';
    if ($pass === '') {
        http_response_code(500);
        echo 'DB config missing: set FORECAST_DB_PASS or create db.local.php';
        exit;
    }
}

$link = mysqli_connect($host, $user, $pass, $name);
if (mysqli_connect_errno() > 0) {
    echo 'DB Connection Error';
    exit;
}
mysqli_set_charset($link, $charset);
