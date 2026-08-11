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
 * 日曜起点の週ラベル（出荷・収穫予測・需給ブロック用）
 * 例: 8/9週（2026-08-09）
 */
function format_sunday_week(?string $date, string $empty = '—'): string
{
    if ($date === null || $date === '' || $date === '-') {
        return $empty;
    }
    try {
        $d = new DateTimeImmutable(substr($date, 0, 10));
    } catch (Exception $e) {
        return $empty;
    }
    $dow = (int)$d->format('w'); // 0=Sun
    $sun = $d->modify('-' . $dow . ' day');
    return sprintf('%d/%d週（%s）', (int)$sun->format('n'), (int)$sun->format('j'), $sun->format('Y-m-d'));
}

function h_sunday_week(?string $date, string $empty = '—'): string
{
    return htmlspecialchars(format_sunday_week($date, $empty), ENT_QUOTES, 'UTF-8');
}
