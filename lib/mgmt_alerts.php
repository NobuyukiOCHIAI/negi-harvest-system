<?php
/**
 * 経営アラート集約 — 枝C「異常・要注意にすぐ気づける」
 * 既存ロジックを束ねるだけ。判定ルールは各 lib の正本どおり。
 */
require_once __DIR__ . '/capacity_outlook.php';
require_once __DIR__ . '/inventory_trust.php';
require_once __DIR__ . '/supply_ops.php';
require_once __DIR__ . '/weather_ops.php';
require_once __DIR__ . '/promise_capacity.php';
require_once __DIR__ . '/date_display.php';

/**
 * @param array|null $trust 既存の trust_outlook_bundle 結果（二重計算回避）
 * @return array{
 *   generated_at:string,
 *   counts:array{critical:int,warn:int,info:int,total:int},
 *   items:list<array{
 *     id:string,category:string,severity:string,title:string,detail:string,href:string,badge:?string
 *   }>,
 *   trust_summary:array
 * }
 */
function gf_mgmt_alerts_bundle(mysqli $link, ?array $trust = null): array
{
    $items = [];

    // 1) 気温鮮度
    $fresh = gf_weather_freshness($link);
    if (!empty($fresh['stale'])) {
        $items[] = [
            'id' => 'weather_stale',
            'category' => 'weather',
            'severity' => ((int)($fresh['lag_days'] ?? 0) >= 3) ? 'critical' : 'warn',
            'title' => '気温データが止まっている',
            'detail' => (string)($fresh['message'] ?? '気温鮮度を確認'),
            'href' => 'weather.php',
            'badge' => isset($fresh['lag_days']) ? ((int)$fresh['lag_days'] . '日遅') : null,
        ];
    }

    // 1b) 約束 > 実力
    $promise = gf_promise_vs_capacity_summary($link, 8);
    if (($promise['status'] ?? 'ok') !== 'ok') {
        $items[] = [
            'id' => 'promise_over_capacity',
            'category' => 'inventory_break',
            'severity' => $promise['status'] === 'critical' ? 'critical' : 'warn',
            'title' => '約束が計画能力を超える週がある',
            'detail' => (string)$promise['headline'],
            'href' => 'capacity.php#sec-outlook',
            'badge' => ((int)$promise['breach_weeks']) . '週',
        ];
    }

    // 2) 在庫割れ接近（計画どおり＝緑系）
    if ($trust === null) {
        $trust = trust_outlook_bundle($link, 16);
    }
    $sum = $trust['summary'] ?? [];
    $status = (string)($sum['status'] ?? 'ok');
    if ($status !== 'ok') {
        $runway = (int)($sum['runway_weeks'] ?? 0);
        $sev = $status === 'critical' ? 'critical' : ($status === 'warn' ? 'warn' : 'info');
        $break = $sum['first_break_week'] ?? null;
        $breakLabel = $break ? h_sunday_week((string)$break) : '先';
        $items[] = [
            'id' => 'inventory_break',
            'category' => 'inventory_break',
            'severity' => $sev,
            'title' => $status === 'critical'
                ? '在庫割れリスクが高い'
                : ($status === 'warn' ? '先に在庫割れ見込み' : '遠い先で割れ見込み'),
            'detail' => sprintf(
                '計画どおりなら割れまで %d週 · 初回 %s · %s',
                $runway,
                $breakLabel,
                (string)($sum['status_label'] ?? '')
            ),
            'href' => 'inventory.php',
            'badge' => $runway . '週',
        ];
    }

    // 定植済のみがかなり悪いときも一言（営業現実線）
    $sumOpen = $trust['summary_open_only'] ?? [];
    $openStatus = (string)($sumOpen['status'] ?? 'ok');
    $openRunway = (int)($sumOpen['runway_weeks'] ?? 99);
    $planRunway = (int)($sum['runway_weeks'] ?? 99);
    if (
        $openStatus !== 'ok'
        && in_array($openStatus, ['critical', 'warn'], true)
        && $openRunway + 2 < $planRunway
    ) {
        $items[] = [
            'id' => 'inventory_break_planted',
            'category' => 'inventory_break',
            'severity' => $openStatus === 'critical' ? 'critical' : 'warn',
            'title' => 'いまの畑だけだと割れが近い',
            'detail' => sprintf(
                '定植済ベースで割れまで %d週（計画は %d週）。定植の消化を確認',
                $openRunway,
                $planRunway
            ),
            'href' => 'capacity.php',
            'badge' => '定植済 ' . $openRunway . '週',
        ];
    }

    // 3) 定植猶予超過
    $delays = supply_plant_delay_rows($link);
    if ($delays) {
        $maxDelay = (int)max(array_column($delays, 'delay_days'));
        $n = count($delays);
        $names = array_slice(array_map(
            static fn($r) => (string)$r['bed_name'] . '(+' . (int)$r['delay_days'] . '日)',
            $delays
        ), 0, 5);
        $items[] = [
            'id' => 'plant_delay',
            'category' => 'plant_delay',
            'severity' => $maxDelay >= 5 ? 'critical' : 'warn',
            'title' => sprintf('定植猶予を超えた空きが %d 床', $n),
            'detail' => '空き後' . (int)GF_REPLANT_GRACE_DAYS . '日以内が目標 · '
                . implode('、', $names)
                . ($n > 5 ? ' ほか' : ''),
            'href' => 'today.php#sec-plant',
            'badge' => '最大+' . $maxDelay . '日',
        ];
    }

    // 4) 破棄候補
    $discards = capacity_discard_candidates($link);
    if ($discards) {
        $n = count($discards);
        $top = $discards[0];
        $strong = count(array_filter(
            $discards,
            static fn($d) => (int)($d['priority'] ?? 0) >= 100
        ));
        $names = array_slice(array_map(
            static fn($d) => (string)$d['bed_name'] . '(+' . (int)$d['days_past'] . '日)',
            $discards
        ), 0, 5);
        $items[] = [
            'id' => 'discard',
            'category' => 'discard',
            'severity' => $strong > 0 ? 'critical' : 'warn',
            'title' => sprintf('破棄候補が %d 床', $n),
            'detail' => (string)($top['reason'] ?? '予測超過') . ' · '
                . implode('、', $names)
                . ($n > 5 ? ' ほか' : ''),
            'href' => 'today.php#sec-discard',
            'badge' => $strong > 0 ? ('強 ' . $strong) : null,
        ];
    }

    $rank = ['critical' => 0, 'warn' => 1, 'info' => 2];
    usort($items, static function ($a, $b) use ($rank) {
        $ra = $rank[$a['severity']] ?? 9;
        $rb = $rank[$b['severity']] ?? 9;
        return $ra <=> $rb ?: strcmp($a['id'], $b['id']);
    });

    $counts = ['critical' => 0, 'warn' => 0, 'info' => 0, 'total' => count($items)];
    foreach ($items as $it) {
        $p = $it['severity'];
        if (isset($counts[$p])) {
            $counts[$p]++;
        }
    }

    return [
        'generated_at' => date('c'),
        'counts' => $counts,
        'items' => $items,
        'trust_summary' => $sum,
    ];
}

/** 件数バッジ用（メニュー等） */
function gf_mgmt_alerts_count(mysqli $link, ?array $trust = null): int
{
    return (int)(gf_mgmt_alerts_bundle($link, $trust)['counts']['total'] ?? 0);
}
