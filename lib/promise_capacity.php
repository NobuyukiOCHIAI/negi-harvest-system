<?php
/**
 * 約束 ≤ 実力 — 社内一言サマリ（取引先画面は作らない）
 * 約束 = GCAL確定出荷、実力 = 計画能力（緑本線）。定植済は参考。
 */
require_once __DIR__ . '/rotation_capacity.php';
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

    foreach ($weeks as $i => $w) {
        $p = (float)($w['gcal_kg'] ?? $w['ship_kg'] ?? 0);
        $c = (float)($w['capacity_kg'] ?? 0);
        $o = (float)($w['open_kg'] ?? 0);
        $promise += $p;
        $cap += $c;
        $open += $o;
        $week = (string)($w['week'] ?? '');
        if ($p > $c + GF_PROMISE_EPS_KG) {
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

    if ($nearBreach > 0) {
        $status = 'critical';
        $label = '直近で約束が実力を超える週がある';
        $headline = sprintf(
            '直近%d週で約束＞計画が %d 週（初回 %s）',
            $nearN,
            $nearBreach,
            $firstBreach ? h_sunday_week($firstBreach) : '—'
        );
    } elseif ($breachN > 0) {
        $status = 'warn';
        $label = '先の週で約束が計画能力を超える';
        $headline = sprintf(
            '今後%d週のうち %d 週で約束＞計画（初回 %s）',
            $weeksAhead,
            $breachN,
            $firstBreach ? h_sunday_week($firstBreach) : '—'
        );
    } else {
        $status = 'ok';
        $label = '約束は計画能力の範囲内';
        $headline = sprintf(
            '今後%d週: 約束 %skg ≤ 計画能力 %skg（余裕 %+skg）',
            $weeksAhead,
            number_format($promiseR),
            number_format($capR),
            number_format($slack)
        );
    }

    $detail = sprintf(
        '定植済だけの能力合計 %skg · 約束超過（定植済比）%d週 · 社内用（取引先非開示）',
        number_format($openR),
        count($openBreach)
    );

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
    $badge = $status === 'ok' ? '余裕あり' : ($status === 'critical' ? '直近超過' : '先超過');
    $h = htmlspecialchars((string)$sum['headline'], ENT_QUOTES, 'UTF-8');
    $d = htmlspecialchars((string)$sum['detail'], ENT_QUOTES, 'UTF-8');
    $hrefEsc = htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
    $badgeEsc = htmlspecialchars($badge, ENT_QUOTES, 'UTF-8');

    return '<a class="promise-card status-' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '" href="' . $hrefEsc . '" '
        . 'style="border-left-color:' . $border . '">'
        . '<div class="promise-kicker">約束 ≤ 実力 <span class="promise-badge">' . $badgeEsc . '</span></div>'
        . '<div class="promise-headline">' . $h . '</div>'
        . '<div class="promise-detail">' . $d . '</div>'
        . '</a>';
}
