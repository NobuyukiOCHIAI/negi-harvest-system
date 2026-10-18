<?php
/**
 * 在庫割れの一言サマリ（取引先画面は作らない）
 * 初回は予測チャートと同じ判定: 累計余剰 < その週の残出荷。
 */
require_once __DIR__ . '/rotation_capacity.php';
require_once __DIR__ . '/supply_ops.php';
require_once __DIR__ . '/date_display.php';

/** 週次で「超えた」とみなす余裕（kg） */
const GF_PROMISE_EPS_KG = 1.0;

/**
 * @return array{
 *   status:string,status_label:string,headline:string,detail:string,
 *   weeks:int,promise_kg:float,capacity_kg:float,open_kg:float,slack_kg:float,
 *   breach_weeks:int,first_breach_week:?string,near_breach_weeks:int,
 *   open_breach_weeks:int
 * }
 */
function gf_promise_vs_capacity_summary(mysqli $link, int $weeksAhead = 8): array
{
    $weeksAhead = max(4, min(20, $weeksAhead));
    $outlook = rotation_capacity_outlook($link, $weeksAhead);
    $weeks = array_slice($outlook['weeks'] ?? [], 0, $weeksAhead);

    $promise = 0.0;
    $cap = 0.0;
    $open = 0.0;
    $breach = [];
    $openBreach = [];
    $nearBreach = 0;
    $nearN = min(4, count($weeks));

    $surplusByWeek = [];
    foreach (supply_inventory_surplus_rows($link) as $row) {
        $surplusByWeek[(string)$row['week_start_date']] = $row;
    }

    foreach ($weeks as $i => $w) {
        $p = (float)($w['gcal_kg'] ?? $w['ship_kg'] ?? 0);
        $c = (float)($w['capacity_kg'] ?? 0);
        $o = (float)($w['open_kg'] ?? 0);
        $promise += $p;
        $cap += $c;
        $open += $o;
        $week = (string)($w['week'] ?? '');
        $sur = $surplusByWeek[$week] ?? null;
        $surplusKg = $sur === null ? null : (float)$sur['surplus_kg'];
        $shipKg = ($sur !== null && $sur['ship_kg'] !== null) ? (float)$sur['ship_kg'] : $p;
        if ($surplusKg !== null && supply_week_is_stockout($surplusKg, $shipKg)) {
            $breach[] = $week;
            if ($i < $nearN) {
                $nearBreach++;
            }
        }
        if ($p > $o + GF_PROMISE_EPS_KG) {
            $openBreach[] = $week;
        }
    }

    $slack = round($cap - $promise, 0);
    $promiseR = round($promise, 0);
    $capR = round($cap, 0);
    $openR = round($open, 0);
    $breachN = count($breach);
    $firstBreach = $breach[0] ?? null;

    if ($breachN > 0) {
        $status = $nearBreach > 0 ? 'critical' : 'warn';
        $label = $nearBreach > 0 ? '直近で在庫割れ' : '先の週で在庫割れ';
        $headline = sprintf(
            '今後%d週のうち %d 週で在庫割れ（初回 %s）',
            $weeksAhead,
            $breachN,
            $firstBreach ? h_sunday_week($firstBreach) : '—'
        );
    } else {
        $status = 'ok';
        $label = '今後8週に在庫割れなし';
        $headline = sprintf(
            '今後%d週: 在庫割れなし（約束 %skg）',
            $weeksAhead,
            number_format($promiseR)
        );
    }

    $detail = '累計余剰がその週の残出荷を下回る週。打ち手は需給。社内用（取引先非開示）';

    return [
        'status' => $status,
        'status_label' => $label,
        'headline' => $headline,
        'detail' => $detail,
        'weeks' => $weeksAhead,
        'promise_kg' => $promiseR,
        'capacity_kg' => $capR,
        'open_kg' => $openR,
        'slack_kg' => $slack,
        'breach_weeks' => $breachN,
        'first_breach_week' => $firstBreach,
        'near_breach_weeks' => $nearBreach,
        'open_breach_weeks' => count($openBreach),
    ];
}

/** Above-the-fold カード HTML */
function gf_promise_vs_capacity_card_html(array $sum, string $href = 'capacity.php'): string
{
    $status = (string)($sum['status'] ?? 'ok');
    $border = $status === 'ok' ? 'var(--gf-green)' : ($status === 'critical' ? 'var(--gf-red, #c62828)' : 'var(--gf-amber, #ef6c00)');
    $badge = $status === 'ok' ? '割れなし' : ($status === 'critical' ? '直近' : '先');
    $h = htmlspecialchars((string)$sum['headline'], ENT_QUOTES, 'UTF-8');
    $d = htmlspecialchars((string)$sum['detail'], ENT_QUOTES, 'UTF-8');
    $hrefEsc = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
    $badgeEsc = htmlspecialchars($badge, ENT_QUOTES, 'UTF-8');

    return '<a class="promise-card status-' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '" href="' . $hrefEsc . '" '
        . 'style="border-left-color:' . $border . '">'
        . '<div class="promise-kicker">在庫割れ <span class="promise-badge">' . $badgeEsc . '</span></div>'
        . '<div class="promise-headline">' . $h . '</div>'
        . '<div class="promise-detail">' . $d . '</div>'
        . '</a>';
}
