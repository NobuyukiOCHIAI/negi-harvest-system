<?php
/**
 * GreenFarm Google カレンダー → 小ねぎ出荷予定の取込。
 *
 * 優先: Calendar API（サービスアカウント）→ 失敗時のみ公開 ICS。
 * SUMMARY から小ねぎ/ねぎ kg を抽出し calendar_shipment_events / calendar_shipments へ反映。
 */

const GCAL_SHIPMENTS_ICS_URL =
    'https://calendar.google.com/calendar/ical/'
    . 'c_55fa62783b7216255eebe528825843315fcf2d23314c3a62f00d6daae1836028'
    . '%40group.calendar.google.com/public/basic.ics';

const GCAL_CALENDAR_ID =
    'c_55fa62783b7216255eebe528825843315fcf2d23314c3a62f00d6daae1836028'
    . '@group.calendar.google.com';

const GCAL_SHIPMENTS_SYNC_KEY = 'gcal_shipments';
/** ページ表示時の自動再取込間隔（秒）。強制同期時は無視。 */
const GCAL_SHIPMENTS_TTL_SEC = 900;
/** FG（Flat / ケース）1CS あたり kg。`小ねぎFG×2CS` → 3×2=6kg */
const GCAL_FG_KG_PER_CS = 3.0;
/** API 取得窓: 過去N日〜未来N日 */
const GCAL_API_PAST_DAYS = 30;
const GCAL_API_FUTURE_DAYS = 120;

/**
 * サービスアカウント JSON の探索順。
 */
function gcal_service_account_paths(): array
{
    $paths = [];
    $env = getenv('GCAL_SERVICE_ACCOUNT_JSON');
    if (is_string($env) && $env !== '') {
        $paths[] = $env;
    }
    // サーバ配置
    $paths[] = '/home/love-media/.secrets/gcal_service_account.json';
    // リポジトリ隣（ローカル）
    $paths[] = dirname(__DIR__, 2) . '/.secrets/gcal_service_account.json';
    $paths[] = dirname(__DIR__) . '/../.secrets/gcal_service_account.json';
    return $paths;
}

function gcal_find_service_account_json(): ?string
{
    foreach (gcal_service_account_paths() as $p) {
        if (is_string($p) && $p !== '' && is_file($p) && is_readable($p)) {
            return $p;
        }
    }
    return null;
}

/**
 * 日曜起点の週開始日（MySQL DAYOFWEEK と同じ: Sun=1）。
 */
function gcal_week_start_sunday(string $ymd): string
{
    $ts = strtotime($ymd . ' 12:00:00');
    if ($ts === false) {
        throw new InvalidArgumentException("bad date: {$ymd}");
    }
    $back = (int)date('w', $ts);
    return date('Y-m-d', strtotime("-{$back} day", $ts));
}

/**
 * SUMMARY から小ねぎ/ねぎの出荷 kg を抽出。該当なしは null。
 */
function gcal_extract_negi_kg(string $summary): ?float
{
    $s = str_replace(['\\,', '\\n', '\\;'], [',', ' ', ';'], $summary);
    $parts = preg_split('/[,、]/u', $s) ?: [$s];
    $total = 0.0;
    $found = false;

    foreach ($parts as $part) {
        if (!preg_match('/ねぎ|ネギ|葱/u', $part)) {
            continue;
        }
        if (preg_match('/FG\s*[x×*]\s*(\d+)\s*CS/iu', $part, $m)) {
            $total += GCAL_FG_KG_PER_CS * (int)$m[1];
            $found = true;
            continue;
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|g)\s*[x×*]\s*(\d+)/iu', $part, $m)) {
            $v = (float)$m[1];
            $unit = strtolower($m[2]);
            $n = (int)$m[3];
            $kg = ($unit === 'g') ? ($v / 1000.0) : $v;
            $total += $kg * $n;
            $found = true;
            continue;
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(kg|g)/iu', $part, $m)) {
            $v = (float)$m[1];
            $unit = strtolower($m[2]);
            $total += ($unit === 'g') ? ($v / 1000.0) : $v;
            $found = true;
        }
    }

    return $found ? round($total, 3) : null;
}

function gcal_b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function gcal_http_json(string $method, string $url, ?array $headers = null, ?string $body = null): array
{
    $hdr = $headers ?? [];
    if ($body !== null) {
        $hdr[] = 'Content-Length: ' . strlen($body);
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'timeout' => 60,
            'ignore_errors' => true,
            'header' => implode("\r\n", $hdr) . (count($hdr) ? "\r\n" : ''),
            'content' => $body ?? '',
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException('HTTP failed: ' . $url);
    }
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('invalid JSON response status=' . $status);
    }
    if ($status >= 400) {
        $msg = $data['error']['message'] ?? ('HTTP ' . $status);
        throw new RuntimeException('Calendar API error: ' . $msg);
    }
    return $data;
}

function gcal_access_token_from_service_account(string $jsonPath): string
{
    $sa = json_decode((string)file_get_contents($jsonPath), true);
    if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
        throw new RuntimeException('invalid service account JSON');
    }
    $now = time();
    $header = gcal_b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_UNESCAPED_SLASHES));
    $claim = gcal_b64url(json_encode([
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3600,
    ], JSON_UNESCAPED_SLASHES));
    $unsigned = $header . '.' . $claim;
    $pkey = openssl_pkey_get_private($sa['private_key']);
    if ($pkey === false) {
        throw new RuntimeException('openssl private key failed');
    }
    $sig = '';
    if (!openssl_sign($unsigned, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('openssl_sign failed');
    }
    $jwt = $unsigned . '.' . gcal_b64url($sig);
    $tokenRes = gcal_http_json(
        'POST',
        'https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ])
    );
    if (empty($tokenRes['access_token'])) {
        throw new RuntimeException('no access_token');
    }
    return (string)$tokenRes['access_token'];
}

/**
 * @return list<array{uid:string,ship_date:string,summary:string,location:string,amount_kg:float}>
 */
function gcal_fetch_events_via_api(?string $jsonPath = null): array
{
    $jsonPath = $jsonPath ?: gcal_find_service_account_json();
    if ($jsonPath === null) {
        throw new RuntimeException('service account JSON not found');
    }
    $token = gcal_access_token_from_service_account($jsonPath);
    $timeMin = gmdate('c', time() - GCAL_API_PAST_DAYS * 86400);
    $timeMax = gmdate('c', time() + GCAL_API_FUTURE_DAYS * 86400);

    $out = [];
    $pageToken = null;
    do {
        $qs = [
            'calendarId' => GCAL_CALENDAR_ID,
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'maxResults' => 2500,
        ];
        if ($pageToken) {
            $qs['pageToken'] = $pageToken;
        }
        $url = 'https://www.googleapis.com/calendar/v3/calendars/'
            . rawurlencode(GCAL_CALENDAR_ID)
            . '/events?' . http_build_query($qs);
        $data = gcal_http_json('GET', $url, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ]);
        foreach ($data['items'] ?? [] as $it) {
            $summary = (string)($it['summary'] ?? '');
            $kg = gcal_extract_negi_kg($summary);
            if ($kg === null) {
                continue;
            }
            $start = $it['start']['date'] ?? null;
            if ($start === null && !empty($it['start']['dateTime'])) {
                $start = substr((string)$it['start']['dateTime'], 0, 10);
            }
            if (!$start) {
                continue;
            }
            $uid = (string)($it['iCalUID'] ?? $it['id'] ?? '');
            if ($uid === '') {
                $uid = 'noid:' . $start . ':' . md5($summary);
            }
            $out[] = [
                'uid' => $uid,
                'ship_date' => $start,
                'summary' => $summary,
                'location' => (string)($it['location'] ?? ''),
                'amount_kg' => $kg,
            ];
        }
        $pageToken = $data['nextPageToken'] ?? null;
    } while ($pageToken);

    return $out;
}

function gcal_unfold_ics(string $raw): string
{
    return preg_replace("/\r?\n[ \t]/", '', $raw) ?? $raw;
}

/**
 * @return list<array{uid:string,ship_date:string,summary:string,location:string,amount_kg:float}>
 */
function gcal_parse_ics_events(string $ics): array
{
    $ics = gcal_unfold_ics($ics);
    if (!preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $matches)) {
        return [];
    }

    $out = [];
    foreach ($matches[1] as $block) {
        if (!preg_match('/^SUMMARY:(.*)$/m', $block, $sm)) {
            continue;
        }
        $summary = trim($sm[1]);
        $kg = gcal_extract_negi_kg($summary);
        if ($kg === null) {
            continue;
        }
        if (!preg_match('/^DTSTART(?:;VALUE=DATE)?(?:;TZID=[^:]*)?:(\d{8})/m', $block, $dm)) {
            continue;
        }
        $shipDate = substr($dm[1], 0, 4) . '-' . substr($dm[1], 4, 2) . '-' . substr($dm[1], 6, 2);
        $uid = '';
        if (preg_match('/^UID:(.*)$/m', $block, $um)) {
            $uid = trim($um[1]);
        }
        if ($uid === '') {
            $uid = 'noid:' . $shipDate . ':' . md5($summary);
        }
        $location = '';
        if (preg_match('/^LOCATION:(.*)$/m', $block, $lm)) {
            $location = trim(str_replace('\\,', ',', $lm[1]));
        }
        $out[] = [
            'uid' => $uid,
            'ship_date' => $shipDate,
            'summary' => str_replace('\\,', ',', $summary),
            'location' => $location,
            'amount_kg' => $kg,
        ];
    }
    return $out;
}

function gcal_fetch_ics(?string $url = null): string
{
    $url = $url ?: GCAL_SHIPMENTS_ICS_URL;
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 45,
            'header' => "User-Agent: GreenFarmForecast/1.0\r\nAccept: text/calendar\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        throw new RuntimeException('failed to fetch Google Calendar ICS');
    }
    return $raw;
}

/**
 * @return array{0:list<array{uid:string,ship_date:string,summary:string,location:string,amount_kg:float}>,1:string}
 */
function gcal_load_events(): array
{
    $jsonPath = gcal_find_service_account_json();
    if ($jsonPath !== null) {
        try {
            return [gcal_fetch_events_via_api($jsonPath), 'api'];
        } catch (Throwable $e) {
            // fall through to ICS
            $apiErr = $e->getMessage();
        }
    } else {
        $apiErr = 'no service account';
    }
    try {
        return [gcal_parse_ics_events(gcal_fetch_ics()), 'ics:' . $apiErr];
    } catch (Throwable $e) {
        throw new RuntimeException('Calendar fetch failed (api=' . $apiErr . '; ics=' . $e->getMessage() . ')');
    }
}

/**
 * @return array{synced:bool,skipped:bool,events:int,weeks:int,skipped_no_kg:int,message:string,synced_at:?string}
 */
function gcal_sync_shipments(mysqli $link, bool $force = false, ?int $ttlSec = null): array
{
    $ttl = $ttlSec === null ? GCAL_SHIPMENTS_TTL_SEC : max(0, $ttlSec);
    $now = date('Y-m-d H:i:s');

    if (!$force && $ttl > 0) {
        $key = mysqli_real_escape_string($link, GCAL_SHIPMENTS_SYNC_KEY);
        $res = mysqli_query(
            $link,
            "SELECT last_synced_at, last_status FROM sync_state WHERE sync_key='{$key}' LIMIT 1"
        );
        $row = $res ? mysqli_fetch_assoc($res) : null;
        if ($res) {
            mysqli_free_result($res);
        }
        if ($row && ($row['last_status'] ?? '') === 'ok' && !empty($row['last_synced_at'])) {
            $age = time() - strtotime($row['last_synced_at']);
            if ($age >= 0 && $age < $ttl) {
                return [
                    'synced' => false,
                    'skipped' => true,
                    'events' => 0,
                    'weeks' => 0,
                    'skipped_no_kg' => 0,
                    'message' => 'within TTL',
                    'synced_at' => $row['last_synced_at'],
                ];
            }
        }
    }

    try {
        [$events, $sourceMode] = gcal_load_events();
    } catch (Throwable $e) {
        gcal_write_sync_state($link, 'error', $e->getMessage(), $now);
        throw $e;
    }

    mysqli_begin_transaction($link);
    try {
        mysqli_query($link, 'DELETE FROM calendar_shipment_events');

        $ins = mysqli_prepare(
            $link,
            'INSERT INTO calendar_shipment_events
              (gcal_uid, ship_date, week_start_date, amount_kg, summary, location_name)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
               week_start_date=VALUES(week_start_date),
               amount_kg=VALUES(amount_kg),
               summary=VALUES(summary),
               location_name=VALUES(location_name)'
        );
        if (!$ins) {
            throw new RuntimeException('prepare events failed: ' . mysqli_error($link));
        }

        $weekSum = [];
        $seen = [];
        foreach ($events as $ev) {
            $week = gcal_week_start_sunday($ev['ship_date']);
            $uid = $ev['uid'];
            $ship = $ev['ship_date'];
            $dedupeKey = $uid . '|' . $ship;
            if (isset($seen[$dedupeKey])) {
                $weekSum[$seen[$dedupeKey]['week']] -= $seen[$dedupeKey]['kg'];
            }
            $kg = $ev['amount_kg'];
            $summary = mb_substr($ev['summary'], 0, 500);
            $loc = mb_substr($ev['location'], 0, 250);
            mysqli_stmt_bind_param($ins, 'sssdss', $uid, $ship, $week, $kg, $summary, $loc);
            mysqli_stmt_execute($ins);
            $seen[$dedupeKey] = ['week' => $week, 'kg' => $kg];
            if (!isset($weekSum[$week])) {
                $weekSum[$week] = 0.0;
            }
            $weekSum[$week] += $kg;
        }
        mysqli_stmt_close($ins);

        mysqli_query($link, "DELETE FROM calendar_shipments WHERE source='gcal'");

        $insW = mysqli_prepare(
            $link,
            "INSERT INTO calendar_shipments (week_start_date, committed_amount_kg, source, gcal_event_id)
             VALUES (?, ?, 'gcal', NULL)
             ON DUPLICATE KEY UPDATE committed_amount_kg=VALUES(committed_amount_kg)"
        );
        if (!$insW) {
            throw new RuntimeException('prepare weeks failed: ' . mysqli_error($link));
        }
        foreach ($weekSum as $week => $kg) {
            $kgRound = round($kg, 2);
            mysqli_stmt_bind_param($insW, 'sd', $week, $kgRound);
            mysqli_stmt_execute($insW);
        }
        mysqli_stmt_close($insW);

        mysqli_commit($link);
        $msg = sprintf('mode=%s events=%d weeks=%d', $sourceMode, count($events), count($weekSum));
        gcal_write_sync_state($link, 'ok', $msg, $now);

        return [
            'synced' => true,
            'skipped' => false,
            'events' => count($events),
            'weeks' => count($weekSum),
            'skipped_no_kg' => 0,
            'message' => $msg,
            'synced_at' => $now,
        ];
    } catch (Throwable $e) {
        mysqli_rollback($link);
        gcal_write_sync_state($link, 'error', $e->getMessage(), $now);
        throw $e;
    }
}

function gcal_write_sync_state(mysqli $link, string $status, string $message, string $at): void
{
    $stmt = mysqli_prepare(
        $link,
        'INSERT INTO sync_state (sync_key, last_synced_at, last_status, last_message)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           last_synced_at=VALUES(last_synced_at),
           last_status=VALUES(last_status),
           last_message=VALUES(last_message)'
    );
    if (!$stmt) {
        return;
    }
    $key = GCAL_SHIPMENTS_SYNC_KEY;
    mysqli_stmt_bind_param($stmt, 'ssss', $key, $at, $status, $message);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * @return array{synced:bool,skipped:bool,events:int,weeks:int,message:string,synced_at:?string,error:?string}
 */
function gcal_ensure_fresh_shipments(mysqli $link, bool $force = false): array
{
    try {
        $r = gcal_sync_shipments($link, $force);
        $r['error'] = null;
        return $r;
    } catch (Throwable $e) {
        return [
            'synced' => false,
            'skipped' => false,
            'events' => 0,
            'weeks' => 0,
            'message' => '',
            'synced_at' => null,
            'error' => $e->getMessage(),
        ];
    }
}

/**
 * 営業リコメンド適用: 指定週の出荷コミットを manual で upsert。
 * 同一週の表示優先は manual > plan > gcal。
 *
 * @param list<string> $weeks Sunday starts
 * @return array{ok:bool,updated:int,message:string}
 */
function shipments_apply_manual_adjust(
    mysqli $link,
    array $weeks,
    float $deltaKgPerWeek,
    string $mode = 'delta'
): array {
    $weeks = array_values(array_unique(array_filter($weeks, static function ($w) {
        return is_string($w) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $w);
    })));
    if (!$weeks) {
        return ['ok' => false, 'updated' => 0, 'message' => '対象週がありません'];
    }

    // 現行コミット（優先順で1件）
    $in = implode(',', array_map(static function ($w) use ($link) {
        return "'" . mysqli_real_escape_string($link, $w) . "'";
    }, $weeks));
    $current = [];
    $res = mysqli_query(
        $link,
        "SELECT week_start_date, source, committed_amount_kg
         FROM calendar_shipments
         WHERE week_start_date IN ({$in})
         ORDER BY week_start_date ASC, FIELD(source, 'manual', 'gcal', 'plan')"
    );
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $w = $row['week_start_date'];
            $src = $row['source'];
            if ($src === 'plan') {
                continue; // 確定コミットではない
            }
            if (!isset($current[$w])) {
                $current[$w] = (float)$row['committed_amount_kg'];
            }
        }
        mysqli_free_result($res);
    }

    $stmt = mysqli_prepare(
        $link,
        "INSERT INTO calendar_shipments (week_start_date, committed_amount_kg, source, gcal_event_id)
         VALUES (?, ?, 'manual', NULL)
         ON DUPLICATE KEY UPDATE committed_amount_kg = VALUES(committed_amount_kg), source = 'manual'"
    );
    if (!$stmt) {
        return ['ok' => false, 'updated' => 0, 'message' => 'prepare失敗: ' . mysqli_error($link)];
    }

    $updated = 0;
    foreach ($weeks as $w) {
        $base = (float)($current[$w] ?? 0);
        if ($mode === 'set') {
            $newKg = max(0.0, $deltaKgPerWeek);
        } else {
            $newKg = max(0.0, $base + $deltaKgPerWeek);
        }
        $newKg = round($newKg, 2);
        mysqli_stmt_bind_param($stmt, 'sd', $w, $newKg);
        if (mysqli_stmt_execute($stmt)) {
            $updated++;
        }
    }
    mysqli_stmt_close($stmt);

    return [
        'ok' => true,
        'updated' => $updated,
        'message' => sprintf(
            'manualコミットを%d週更新（%+.0fkg/週）',
            $updated,
            $deltaKgPerWeek
        ),
    ];
}

/**
 * 開始〜終了（日曜週）を1週刻みで列挙
 *
 * @return list<string>
 */
function shipments_weeks_between(string $startWeek, string $endWeek): array
{
    $startWeek = gcal_week_start_sunday($startWeek);
    $endWeek = gcal_week_start_sunday($endWeek);
    $out = [];
    $ts = strtotime($startWeek);
    $end = strtotime($endWeek);
    if ($ts === false || $end === false) {
        return [];
    }
    while ($ts <= $end) {
        $out[] = date('Y-m-d', $ts);
        $ts = strtotime('+7 days', $ts);
    }
    return $out;
}
