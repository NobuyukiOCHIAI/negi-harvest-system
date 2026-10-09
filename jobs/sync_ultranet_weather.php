<?php
/**
 * UltraNet（sedia-utnet.com）から日次気温を取得し weather_daily へ投入。
 * 正本 §8: 取れる期間は実測優先。類推埋めしない。
 *
 * CLI: php jobs/sync_ultranet_weather.php [--from=YYYY-MM-DD]
 *
 * 認証: db.local.php の ultranet_user / ultranet_pass
 *   または環境変数 ULTRANET_USER / ULTRANET_PASS
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/weather_ops.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

const GF_ULTRANET_LOGIN = 'https://sedia-utnet.com/users/login';
const GF_ULTRANET_HOUSE = 6229;
const GF_ULTRANET_VIEW = 'https://sedia-utnet.com/Datast/view1/6229/on/on/on/on/off/off/off/off/off/off/off/off/off/off/off/off/1';

/**
 * @return array{user:string,pass:string}
 */
function gf_ultranet_creds(): array
{
    $user = getenv('ULTRANET_USER') ?: '';
    $pass = getenv('ULTRANET_PASS') ?: '';
    $local = __DIR__ . '/../db.local.php';
    if (is_file($local)) {
        $cfg = require $local;
        if (is_array($cfg)) {
            if ($user === '' && !empty($cfg['ultranet_user'])) {
                $user = (string)$cfg['ultranet_user'];
            }
            if ($pass === '' && !empty($cfg['ultranet_pass'])) {
                $pass = (string)$cfg['ultranet_pass'];
            }
        }
    }
    return ['user' => trim($user), 'pass' => trim($pass)];
}

/**
 * @return array{ok:bool,cookie:string,error?:string}
 */
function gf_ultranet_login(string $user, string $pass): array
{
    $ch = curl_init(GF_ULTRANET_LOGIN . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => '',
        CURLOPT_COOKIEFILE => '',
        CURLOPT_TIMEOUT => 90,
        CURLOPT_USERAGENT => 'GreenFarmWeatherSync/1.0',
        CURLOPT_HEADER => true,
    ]);
    // Cookie jar in memory via temp file
    $jar = tempnam(sys_get_temp_dir(), 'utck');
    curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        @unlink($jar);
        return ['ok' => false, 'cookie' => '', 'error' => $err];
    }
    curl_close($ch);

    $ch = curl_init(GF_ULTRANET_LOGIN);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            '_method' => 'POST',
            'user_name_en' => $user,
            'password' => $pass,
        ]),
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_USERAGENT => 'GreenFarmWeatherSync/1.0',
    ]);
    $html = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($html === false || (strpos((string)$html, 'ログアウト') === false && stripos((string)$html, 'datast') === false)) {
        @unlink($jar);
        return ['ok' => false, 'cookie' => '', 'error' => "login failed http={$code}"];
    }
    return ['ok' => true, 'cookie' => $jar];
}

/**
 * @return array{ok:bool,csv?:string,error?:string}
 */
function gf_ultranet_fetch_csv(string $jarPath): array
{
    $ch = curl_init(GF_ULTRANET_VIEW);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEFILE => $jarPath,
        CURLOPT_COOKIEJAR => $jarPath,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_USERAGENT => 'GreenFarmWeatherSync/1.0',
    ]);
    curl_exec($ch);
    curl_close($ch);

    $today = date('Y-m-d');
    $ch = curl_init(GF_ULTRANET_VIEW);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            '_method' => 'POST',
            'basedate' => $today,
            'basetime' => '12:00',
            'hidden_basetime' => '12:00',
            'period' => '8',
            'indication' => '4',
            'valveId' => '999',
            'mode' => 'csv',
        ]),
        CURLOPT_COOKIEFILE => $jarPath,
        CURLOPT_COOKIEJAR => $jarPath,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_USERAGENT => 'GreenFarmWeatherSync/1.0',
    ]);
    $csv = curl_exec($ch);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($csv === false || stripos($ctype, 'csv') === false) {
        return ['ok' => false, 'error' => $err !== '' ? $err : "not csv ctype={$ctype}"];
    }
    return ['ok' => true, 'csv' => $csv];
}

/**
 * @return list<array{date:string,temp_avg:float,temp_max:float,temp_min:float,variation:float}>
 */
function gf_ultranet_parse_daily(string $csv): array
{
    $enc = mb_detect_encoding($csv, ['UTF-8', 'SJIS-win', 'SJIS', 'CP932'], true) ?: 'SJIS-win';
    if (strtoupper($enc) !== 'UTF-8') {
        $csv = mb_convert_encoding($csv, 'UTF-8', $enc);
    }
    $fp = fopen('php://memory', 'r+');
    fwrite($fp, $csv);
    rewind($fp);
    $header = fgetcsv($fp);
    if (!$header) {
        fclose($fp);
        return [];
    }
    $find = static function (array $header, array $needles): int {
        foreach ($header as $i => $h) {
            $ok = true;
            foreach ($needles as $n) {
                if (mb_strpos((string)$h, $n) === false) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return (int)$i;
            }
        }
        throw new RuntimeException('column not found: ' . implode(',', $needles));
    };
    $avgs = $maxs = $mins = [];
    foreach (['1', '2', '3', '4'] as $n) {
        $avgs[] = $find($header, ["ハウス内温度{$n}", '平均']);
        $maxs[] = $find($header, ["ハウス内温度{$n}", '最大']);
        $mins[] = $find($header, ["ハウス内温度{$n}", '最小']);
    }
    $out = [];
    while (($row = fgetcsv($fp)) !== false) {
        if (!$row || $row[0] === null || $row[0] === '') {
            continue;
        }
        $ds = trim((string)$row[0], " \t\"'");
        $dt = null;
        $tmp = DateTime::createFromFormat('Y/m/d H:i', substr($ds, 0, 16));
        if ($tmp instanceof DateTime) {
            $dt = $tmp->format('Y-m-d');
        } else {
            $tmp = DateTime::createFromFormat('Y/m/d', substr($ds, 0, 10));
            if ($tmp instanceof DateTime) {
                $dt = $tmp->format('Y-m-d');
            }
        }
        if ($dt === null) {
            continue;
        }
        $a = [];
        $x = [];
        $n = [];
        foreach ($avgs as $i) {
            $a[] = (float)$row[$i];
        }
        foreach ($maxs as $i) {
            $x[] = (float)$row[$i];
        }
        foreach ($mins as $i) {
            $n[] = (float)$row[$i];
        }
        if (array_sum($a) == 0.0) {
            continue;
        }
        $avg = array_sum($a) / count($a);
        $mx = max($x);
        $mn = min($n);
        $out[] = [
            'date' => $dt,
            'temp_avg' => $avg,
            'temp_max' => $mx,
            'temp_min' => $mn,
            'variation' => $mx - $mn,
        ];
    }
    fclose($fp);
    return $out;
}

$from = null;
foreach ($argv ?? [] as $a) {
    if (strpos($a, '--from=') === 0) {
        $from = substr($a, 7);
    }
}
if ($from === null) {
    // default: fill from day after current asof (or 30 days back)
    $res = mysqli_query($link, 'SELECT MAX(date) mx FROM weather_daily');
    $row = $res ? mysqli_fetch_assoc($res) : null;
    $mx = $row['mx'] ?? null;
    if ($mx) {
        $from = date('Y-m-d', strtotime($mx . ' +1 day'));
    } else {
        $from = date('Y-m-d', strtotime('-40 days'));
    }
}

$creds = gf_ultranet_creds();
if ($creds['user'] === '' || $creds['pass'] === '') {
    fwrite(STDERR, "FAIL missing ultranet_user/pass in db.local.php\n");
    // still emit fetch-fail alert path
    passthru('php ' . escapeshellarg(__DIR__ . '/check_weather_freshness.php') . ' --fetch-fail');
    exit(1);
}

try {
    $login = gf_ultranet_login($creds['user'], $creds['pass']);
    if (!$login['ok']) {
        throw new RuntimeException($login['error'] ?? 'login failed');
    }
    $jar = $login['cookie'];
    $fetched = gf_ultranet_fetch_csv($jar);
    @unlink($jar);
    if (!$fetched['ok']) {
        throw new RuntimeException($fetched['error'] ?? 'csv failed');
    }
    $days = gf_ultranet_parse_daily($fetched['csv']);
    $n = 0;
    $stmt = mysqli_prepare(
        $link,
        'INSERT INTO weather_daily (date, temp_avg, temp_max, temp_min, variation)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           temp_avg=VALUES(temp_avg),
           temp_max=VALUES(temp_max),
           temp_min=VALUES(temp_min),
           variation=VALUES(variation)'
    );
    foreach ($days as $d) {
        if ($d['date'] < $from) {
            continue;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'sdddd',
            $d['date'],
            $d['temp_avg'],
            $d['temp_max'],
            $d['temp_min'],
            $d['variation']
        );
        mysqli_stmt_execute($stmt);
        $n++;
    }
    mysqli_stmt_close($stmt);
    gf_weather_log('ultranet_sync', ['from' => $from, 'upserted' => $n, 'parsed' => count($days)]);
    echo "OK upserted={$n} from={$from} parsed=" . count($days) . "\n";
    passthru('php ' . escapeshellarg(__DIR__ . '/check_weather_freshness.php'));
    passthru('php ' . escapeshellarg(__DIR__ . '/fill_missing_plant_preds.php'));
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL ' . $e->getMessage() . "\n");
    gf_weather_log('ultranet_sync_fail', ['error' => $e->getMessage()]);
    passthru('php ' . escapeshellarg(__DIR__ . '/check_weather_freshness.php') . ' --fetch-fail');
    exit(1);
}
