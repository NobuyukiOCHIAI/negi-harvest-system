<?php
/**
 * weather_daily 鮮度監視・アラート（正本 §8）
 */

/** 昨日までのデータが無いとき stale とみなす（当日分は UltraNet 遅延を許容） */
const GF_WEATHER_STALE_LAG_DAYS = 1;

/**
 * @return array{ok:bool,asof:?string,yesterday:string,lag_days:int,stale:bool,message:string}
 */
function gf_weather_freshness(mysqli $link): array
{
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $asof = null;
    $chk = mysqli_query($link, "SHOW TABLES LIKE 'weather_daily'");
    $has = $chk && mysqli_num_rows($chk) > 0;
    if ($chk) {
        mysqli_free_result($chk);
    }
    if (!$has) {
        return [
            'ok' => false,
            'asof' => null,
            'yesterday' => $yesterday,
            'lag_days' => 9999,
            'stale' => true,
            'message' => 'weather_daily テーブルがありません',
        ];
    }
    $res = mysqli_query($link, 'SELECT MAX(date) AS mx FROM weather_daily');
    $row = $res ? mysqli_fetch_assoc($res) : null;
    if ($res) {
        mysqli_free_result($res);
    }
    $asof = $row['mx'] ?? null;
    if ($asof === null || $asof === '') {
        return [
            'ok' => false,
            'asof' => null,
            'yesterday' => $yesterday,
            'lag_days' => 9999,
            'stale' => true,
            'message' => 'weather_daily が空です',
        ];
    }
    $asof = substr((string)$asof, 0, 10);
    $lag = (int)((strtotime($yesterday . ' 12:00:00') - strtotime($asof . ' 12:00:00')) / 86400);
    if ($lag < 0) {
        $lag = 0;
    }
    $stale = $lag >= GF_WEATHER_STALE_LAG_DAYS;
    return [
        'ok' => true,
        'asof' => $asof,
        'yesterday' => $yesterday,
        'lag_days' => $lag,
        'stale' => $stale,
        'message' => $stale
            ? "気温データが止まっています（最終={$asof}、{$lag}日遅れ）"
            : "気温データは前日まで揃っています（最終={$asof}）",
    ];
}

/**
 * alerts へ data_missing（気温滞留）を1日1件まで記録。既にあれば id を返す。
 * enum: shortage|delay|loss_spike|data_missing
 * @return int|null alert id
 */
function gf_weather_alert_upsert(mysqli $link, array $fresh, string $kind = 'weather_stale'): ?int
{
    if (empty($fresh['stale'])) {
        return null;
    }
    $chk = mysqli_query($link, "SHOW TABLES LIKE 'alerts'");
    $has = $chk && mysqli_num_rows($chk) > 0;
    if ($chk) {
        mysqli_free_result($chk);
    }
    if (!$has) {
        return null;
    }
    $today = date('Y-m-d');
    $type = 'data_missing';
    $q = mysqli_prepare(
        $link,
        "SELECT id, payload_json FROM alerts WHERE type = ? AND date = ? ORDER BY id DESC LIMIT 5"
    );
    if ($q) {
        mysqli_stmt_bind_param($q, 'ss', $type, $today);
        mysqli_stmt_execute($q);
        $res = mysqli_stmt_get_result($q);
        while ($res && ($exist = mysqli_fetch_assoc($res))) {
            $pj = json_decode((string)($exist['payload_json'] ?? ''), true);
            if (is_array($pj) && ($pj['kind'] ?? '') === $kind) {
                mysqli_stmt_close($q);
                return (int)$exist['id'];
            }
        }
        mysqli_stmt_close($q);
    }
    $payload = json_encode(
        [
            'kind' => $kind,
            'source' => 'weather_daily',
            'asof' => $fresh['asof'],
            'lag_days' => $fresh['lag_days'],
            'yesterday' => $fresh['yesterday'],
            'message' => $fresh['message'],
            'cause_hint' => 'UltraNet停止または取得JOB失敗の可能性',
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    $status = 'open';
    $ins = mysqli_prepare(
        $link,
        'INSERT INTO alerts (date, type, payload_json, status, created_at) VALUES (?, ?, ?, ?, NOW())'
    );
    if (!$ins) {
        return null;
    }
    mysqli_stmt_bind_param($ins, 'ssss', $today, $type, $payload, $status);
    mysqli_stmt_execute($ins);
    $id = (int)mysqli_insert_id($link);
    mysqli_stmt_close($ins);
    return $id > 0 ? $id : null;
}

/**
 * 通知先: 環境変数 FORECAST_ALERT_TO → db.local.php の alert_to
 */
function gf_weather_alert_to(): string
{
    $env = getenv('FORECAST_ALERT_TO');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    $local = __DIR__ . '/../db.local.php';
    if (is_file($local)) {
        $cfg = require $local;
        if (is_array($cfg) && !empty($cfg['alert_to']) && is_string($cfg['alert_to'])) {
            return trim($cfg['alert_to']);
        }
    }
    return '';
}

/**
 * @return array{sent:bool,to:string,reason:string}
 */
function gf_weather_alert_mail(array $fresh): array
{
    $to = gf_weather_alert_to();
    if ($to === '') {
        return ['sent' => false, 'to' => '', 'reason' => 'FORECAST_ALERT_TO / db.local.php alert_to 未設定'];
    }
    if (empty($fresh['stale'])) {
        return ['sent' => false, 'to' => $to, 'reason' => 'not_stale'];
    }
    $subj = '[GreenFarm] 気温データ停止アラート';
    $body = $fresh['message'] . "\n"
        . "asof={$fresh['asof']} lag_days={$fresh['lag_days']}\n"
        . "UltraNet または weather 取得を確認してください。\n"
        . '検知: ' . date('c') . "\n";
    $headers = 'From: noreply@love-media.sakura.ne.jp' . "\r\n"
        . 'Content-Type: text/plain; charset=UTF-8';
    $ok = @mail($to, $subj, $body, $headers);
    return ['sent' => (bool)$ok, 'to' => $to, 'reason' => $ok ? 'ok' : 'mail() failed'];
}

/**
 * 前日までの気温が無いとき、UltraNet 同期を1回実行する。
 * 再予測など予測処理の入口から呼ぶ（正本 §8）。
 *
 * @return array{ok:bool,asof:?string,stale:bool,fetched:bool,message:string}
 */
function gf_weather_sync_if_stale(mysqli $link): array
{
    $fresh = gf_weather_freshness($link);
    $fresh['fetched'] = false;
    if (empty($fresh['stale'])) {
        return $fresh;
    }
    $php = (PHP_SAPI === 'cli' && PHP_BINARY !== '') ? PHP_BINARY : 'php';
    $script = dirname(__DIR__) . '/jobs/sync_ultranet_weather.php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script);
    $out = [];
    $code = 1;
    exec($cmd . ' 2>&1', $out, $code);
    $after = gf_weather_freshness($link);
    $after['fetched'] = true;
    $after['fetch_exit'] = $code;
    gf_weather_log('sync_if_stale', [
        'exit' => $code,
        'asof' => $after['asof'],
        'stale' => $after['stale'],
    ]);
    return $after;
}

function gf_weather_log(string $message, array $ctx = []): void
{
    $dir = '/home/love-media/forc_logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $file = $dir . '/weather_' . date('Ymd') . '.log';
    $line = date('c') . ' ' . $message;
    if ($ctx) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/** 画面バナー用 HTML（stale のときだけ） */
function gf_weather_stale_banner_html(mysqli $link): string
{
    $f = gf_weather_freshness($link);
    if (empty($f['stale'])) {
        return '';
    }
    $msg = htmlspecialchars($f['message'], ENT_QUOTES, 'UTF-8');
    return '<div class="alert alert-danger py-2 mb-3">'
        . '気温アラート: ' . $msg
        . ' · UltraNet／取得JOBを確認してください'
        . '</div>';
}
