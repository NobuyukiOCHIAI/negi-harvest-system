<?php
/**
 * 週まとめを月曜始まりへ。CLI のみ。
 * manual/plan の日曜キーは翌日（同じ週の月曜）へ。
 * gcal は ship_date から取り直す。ビューの week_start も WEEKDAY へ。
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/gcal_shipments.php';
require_once dirname(__DIR__) . '/lib/date_display.php';

$today = date('Y-m-d');
$week = gcal_week_start($today);
$label = format_sunday_week($today);
echo "today={$today} week={$week} label={$label}\n";
if ($week !== week_sunday_date($today)) {
    fwrite(STDERR, "week functions disagree\n");
    exit(1);
}

$replacements = [
    'harvest_actual_base_v' => [
        'interval (dayofweek(`c`.`harvest_end`) - 1) day',
        'interval weekday(`c`.`harvest_end`) day',
    ],
    'harvest_actual_events_v' => [
        'interval (dayofweek(`h`.`harvest_date`) - 1) day',
        'interval weekday(`h`.`harvest_date`) day',
    ],
    'weekly_harvest_forecast_v' => [
        'interval (dayofweek((`c`.`plant_date` + interval cast(round(`pr`.`pred_days`,0) as signed) day)) - 1) day',
        'interval weekday((`c`.`plant_date` + interval cast(round(`pr`.`pred_days`,0) as signed) day)) day',
    ],
];

foreach ($replacements as $name => [$from, $to]) {
    $cr = mysqli_query($link, "SHOW CREATE VIEW `{$name}`");
    if (!$cr) {
        fwrite(STDERR, "{$name} show fail " . mysqli_error($link) . "\n");
        exit(1);
    }
    $sql = (string)mysqli_fetch_assoc($cr)['Create View'];
    if (stripos($sql, 'dayofweek') === false) {
        echo "{$name} already monday\n";
        continue;
    }
    if (stripos($sql, $from) === false) {
        fwrite(STDERR, "{$name} pattern missing\n{$sql}\n");
        exit(1);
    }
    $new = str_ireplace($from, $to, $sql);
    $new = preg_replace('/^CREATE\s+/i', 'CREATE OR REPLACE ', $new, 1);
    if (!mysqli_query($link, $new)) {
        fwrite(STDERR, "{$name} FAIL " . mysqli_error($link) . "\n");
        exit(1);
    }
    echo "{$name} view monday\n";
}

$sync = gcal_sync_shipments($link, true);
echo 'sync ' . json_encode($sync, JSON_UNESCAPED_UNICODE) . "\n";
if (empty($sync['synced'])) {
    fwrite(STDERR, "gcal sync did not rewrite weeks\n");
    exit(1);
}

$upd = mysqli_query(
    $link,
    "UPDATE calendar_shipments
     SET week_start_date = DATE_ADD(week_start_date, INTERVAL 1 DAY)
     WHERE source IN ('manual','plan') AND DAYOFWEEK(week_start_date) = 1"
);
if (!$upd) {
    fwrite(STDERR, 'shift fail ' . mysqli_error($link) . "\n");
    exit(1);
}
echo 'shifted_manual_plan=' . mysqli_affected_rows($link) . "\n";

$res = mysqli_query(
    $link,
    "SELECT source, DAYOFWEEK(week_start_date) dow, COUNT(*) n
     FROM calendar_shipments GROUP BY source, dow"
);
while ($row = mysqli_fetch_assoc($res)) {
    echo sprintf("after source=%s dow=%s n=%s\n", $row['source'], $row['dow'], $row['n']);
}
$ev = mysqli_query(
    $link,
    "SELECT SUM(DAYOFWEEK(week_start_date)=2) mondays,
            SUM(DAYOFWEEK(week_start_date)=1) sundays,
            COUNT(*) n
     FROM calendar_shipment_events"
);
$row = mysqli_fetch_assoc($ev);
echo sprintf("events n=%s monday_keys=%s sunday_keys=%s\n", $row['n'], $row['mondays'], $row['sundays']);
if ((int)$row['sundays'] !== 0) {
    fwrite(STDERR, "events still have Sunday keys\n");
    exit(1);
}
echo "OK\n";
