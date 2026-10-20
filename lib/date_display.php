<?php
/**
 * 管理画面の日付表記統一。
 * 内部は DATE のまま。表示のみ「●月●週（週開始日=月曜）」.
 *
 * 例: 2026-03-12 → 3月2週（2026-03-09）
 */

/**
 * その日が属する週の月曜（Y-m-d）。
 */
function week_monday_date(?string $date): ?string
{
    if ($date === null || $date === '' || $date === '-') {
        return null;
    }
    try {
        $d = new DateTimeImmutable(substr($date, 0, 10));
    } catch (Exception $e) {
        return null;
    }
    $w = (int)$d->format('N'); // 1=Mon .. 7=Sun
    return $d->modify('-' . ($w - 1) . ' day')->format('Y-m-d');
}

/**
 * 月曜日付から「月内第何週」（その月曜の日番号を7で割って切り上げ）。
 */
function week_of_month_from_monday(string $mondayYmd): int
{
    $day = (int)(new DateTimeImmutable($mondayYmd))->format('j');
    return (int)ceil($day / 7.0);
}

/**
 * 表示用: 3月2週（2026-03-09）
 * $empty は日付なしのとき。
 */
function format_month_week(?string $date, string $empty = '—'): string
{
    $mon = week_monday_date($date);
    if ($mon === null) {
        return $empty;
    }
    $dt = new DateTimeImmutable($mon);
    $month = (int)$dt->format('n');
    $wom = week_of_month_from_monday($mon);
    return sprintf('%d月%d週（%s）', $month, $wom, $mon);
}

/**
 * 短いラベル（カレンダー列など）: 3月2週
 */
function format_month_week_short(?string $date, string $empty = '—'): string
{
    $mon = week_monday_date($date);
    if ($mon === null) {
        return $empty;
    }
    $dt = new DateTimeImmutable($mon);
    return sprintf('%d月%d週', (int)$dt->format('n'), week_of_month_from_monday($mon));
}

/**
 * HTMLエスケープ済み表示。
 */
function h_month_week(?string $date, string $empty = '—'): string
{
    return htmlspecialchars(format_month_week($date, $empty), ENT_QUOTES, 'UTF-8');
}

/**
 * 実日付 Y-m-d（作業入力・サイクル一覧用）
 */
function format_ymd(?string $date, string $empty = '—'): string
{
    if ($date === null || $date === '' || $date === '-') {
        return $empty;
    }
    return substr($date, 0, 10);
}

function h_ymd(?string $date, string $empty = '—'): string
{
    return htmlspecialchars(format_ymd($date, $empty), ENT_QUOTES, 'UTF-8');
}

function h_int($value, string $empty = '—'): string
{
    return h_num($value, 0, $empty);
}

/**
 * HTML数値。負は赤字（.num-neg）。
 * $signed=true なら正にも + を付ける。
 */
function h_num($value, int $decimals = 0, string $empty = '—', bool $signed = false, string $suffix = ''): string
{
    if ($value === null || $value === '') {
        return htmlspecialchars($empty, ENT_QUOTES, 'UTF-8');
    }
    $n = (float)$value;
    $text = number_format($n, $decimals, '.', ',');
    if ($signed && $n >= 0) {
        $text = '+' . $text;
    }
    $text .= $suffix;
    $esc = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    if ($n < 0) {
        return '<span class="num-neg">' . $esc . '</span>';
    }
    return $esc;
}

/** 差・昨対。正は +、負は赤字。 */
function h_delta($value, int $decimals = 1, string $empty = '—', string $suffix = ''): string
{
    return h_num($value, $decimals, $empty, true, $suffix);
}

/**
 * その日が属する週の月曜（Y-m-d）。出荷・収穫予定週の内部キー。
 * 関数名 week_sunday_date は互換のため残す。
 */
function week_sunday_date(?string $date): ?string
{
    return week_monday_date($date);
}

/**
 * 月曜起点の週ラベル（出荷・収穫予測・需給・モニター用）
 * 例: 10/19週
 */
function format_sunday_week(?string $date, string $empty = '—'): string
{
    $sun = week_sunday_date($date);
    if ($sun === null) {
        return $empty;
    }
    $dt = new DateTimeImmutable($sun);
    return sprintf('%d/%d週', (int)$dt->format('n'), (int)$dt->format('j'));
}

function h_sunday_week(?string $date, string $empty = '—'): string
{
    return htmlspecialchars(format_sunday_week($date, $empty), ENT_QUOTES, 'UTF-8');
}
