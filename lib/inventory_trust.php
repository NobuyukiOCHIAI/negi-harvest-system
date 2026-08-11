<?php
/**
 * 需給信頼 — 累計在庫の先割れリスク。営業は一時余剰 vs 数か月トレンド（supply_ops）。
 *
 * 最重要: 仲卸との約束は週次小回り不可。先の在庫割れを可視化し、
 * 累計在庫の先割れ検知＝信頼の核。営業アクションは一時余剰 vs 数か月トレンド（supply_ops）。
 */
require_once __DIR__ . '/gcal_shipments.php';
require_once __DIR__ . '/rotation_capacity.php';

/** 営業約束の最小単位（週） */
const GF_COMMIT_BLOCK_WEEKS = 3;

/** 在庫割れを「要交渉」とみなす何週先まで */
const GF_TRUST_ALERT_WEEKS = 10;

/** 累計不足がこのkgを下回ったら深刻 */
const GF_TRUST_CRITICAL_CUM_KG = -100.0;

/**
 * 週次系列から累計余剰を付与
 * surplus_t = surplus_{t-1} + capacity_t - ship_t
 *
 * @param list<array{week:string,capacity_kg:float,ship_kg:float}> $weeks
 * @return list<array>
 */
function trust_attach_cumulative(array $weeks): array
{
    $cum = 0.0;
    $out = [];
    foreach ($weeks as $w) {
        $cap = (float)($w['capacity_kg'] ?? $w['forecast_kg'] ?? 0);
        $ship = (float)($w['ship_kg'] ?? 0);
        $cum = round($cum + $cap - $ship, 1);
        $row = $w;
        $row['capacity_kg'] = $cap;
        $row['ship_kg'] = $ship;
        $row['week_delta_kg'] = round($cap - $ship, 1);
        $row['cum_surplus_kg'] = $cum;
        $row['broken'] = ($cum < -1e-6);
        $out[] = $row;
    }
    return $out;
}

/**
 * フル回転能力ベースの累計在庫系列
 *
 * @return list<array>
 */
function trust_cumulative_with_rotation(mysqli $link, int $weeksAhead = 16): array
{
    $outlook = rotation_capacity_outlook($link, $weeksAhead);
    $base = [];
    foreach ($outlook['weeks'] as $w) {
        $base[] = [
            'week' => $w['week'],
            'capacity_kg' => (float)$w['capacity_kg'],
            'open_kg' => (float)($w['open_kg'] ?? 0),
            'rotation_kg' => (float)($w['rotation_kg'] ?? 0) + (float)($w['planned_kg'] ?? 0),
            'ship_kg' => (float)$w['ship_kg'],
            'yoy_kg' => (float)($w['yoy_kg'] ?? 0),
        ];
    }
    return trust_attach_cumulative($base);
}

/**
 * オープン予測のみ（従来の収穫予測に近い）累計
 *
 * @return list<array>
 */
function trust_cumulative_open_only(mysqli $link, int $weeksAhead = 16): array
{
    $outlook = rotation_capacity_outlook($link, $weeksAhead);
    $base = [];
    foreach ($outlook['weeks'] as $w) {
        $base[] = [
            'week' => $w['week'],
            'capacity_kg' => (float)($w['open_kg'] ?? 0),
            'open_kg' => (float)($w['open_kg'] ?? 0),
            'rotation_kg' => 0.0,
            'ship_kg' => (float)$w['ship_kg'],
        ];
    }
    return trust_attach_cumulative($base);
}

/**
 * 最初の在庫割れ週など
 *
 * @param list<array> $cumWeeks
 * @return array{
 *   first_break_week:?string,first_break_index:?int,runway_weeks:int,
 *   min_cum_kg:float,min_cum_week:?string,end_cum_kg:float,
 *   broken_week_count:int,status:string,status_label:string
 * }
 */
function trust_break_summary(array $cumWeeks): array
{
    $firstBreak = null;
    $firstIdx = null;
    $minCum = null;
    $minWeek = null;
    $brokenN = 0;
    foreach ($cumWeeks as $i => $w) {
        $c = (float)$w['cum_surplus_kg'];
        if ($minCum === null || $c < $minCum) {
            $minCum = $c;
            $minWeek = $w['week'];
        }
        if ($w['broken'] && $firstBreak === null) {
            $firstBreak = $w['week'];
            $firstIdx = $i;
        }
        if ($w['broken']) {
            $brokenN++;
        }
    }
    $endCum = $cumWeeks ? (float)$cumWeeks[count($cumWeeks) - 1]['cum_surplus_kg'] : 0.0;
    $runway = $firstIdx === null ? count($cumWeeks) : $firstIdx;

    $status = 'ok';
    $label = '先行き在庫は維持見込み';
    if ($firstBreak !== null) {
        if ($runway <= 4 || $minCum <= GF_TRUST_CRITICAL_CUM_KG) {
            $status = 'critical';
            $label = '在庫割れリスク高 — 需給のトレンドアラートを確認';
        } elseif ($runway <= GF_TRUST_ALERT_WEEKS) {
            $status = 'warn';
            $label = '先に在庫割れ見込み — 減少トレンドなら早めに営業連絡';
        } else {
            $status = 'watch';
            $label = '遠い先で割れ見込み — 監視継続';
        }
    }

    return [
        'first_break_week' => $firstBreak,
        'first_break_index' => $firstIdx,
        'runway_weeks' => $runway,
        'min_cum_kg' => $minCum !== null ? round($minCum, 1) : 0.0,
        'min_cum_week' => $minWeek,
        'end_cum_kg' => round($endCum, 1),
        'broken_week_count' => $brokenN,
        'status' => $status,
        'status_label' => $label,
    ];
}

/**
 * 数週間ブロックの営業調整案（週次の小回りではない）
 *
 * 考え方: 割れ開始の数週間前から、COMMIT_BLOCK 週ぶんの出荷を均一に増減する約束。
 *
 * @param list<array> $cumWeeks rotation付き
 * @return list<array>
 */
function trust_multiweek_commit_actions(array $cumWeeks): array
{
    // 新ロジック: 一時余剰 vs 数か月トレンド（supply_ops）
    // $link が無い互換のため、呼び出し側は trust_outlook_bundle 経由を推奨
    return [];
}

/**
 * @deprecated use trust_outlook_bundle → supply_classify
 */
function trust_multiweek_commit_actions_with_link(mysqli $link, array $cumWeeks): array
{
    require_once __DIR__ . '/supply_ops.php';
    $c = supply_classify_surplus_deficit($link, $cumWeeks);
    return $c['actions'];
}

/**
 * 画面用サマリ一式
 *
 * @return array
 */
function trust_outlook_bundle(mysqli $link, int $weeksAhead = 16): array
{
    require_once __DIR__ . '/supply_ops.php';
    supply_ensure_full_rotation($link);

    $withRot = trust_cumulative_with_rotation($link, $weeksAhead);
    $openOnly = trust_cumulative_open_only($link, $weeksAhead);
    $sumRot = trust_break_summary($withRot);
    $sumOpen = trust_break_summary($openOnly);
    $classified = supply_classify_surplus_deficit($link, $withRot);
    $actions = $classified['actions'];

    return [
        'with_rotation' => $withRot,
        'open_only' => $openOnly,
        'summary' => $sumRot,
        'summary_open_only' => $sumOpen,
        'actions' => $actions,
        'spot' => $classified['spot'],
        'trends' => $classified['trends'],
        'grace_days' => $classified['grace_days'],
        'commit_block_weeks' => GF_TREND_MIN_WEEKS,
        'principle' => 'デフォルトは常時フル稼働。GCALは確定コミット、能力はシステム計画。一時余剰はスポット営業、数か月トレンドのみベース交渉。減少フェーズは早めに伝達。',
    ];
}
