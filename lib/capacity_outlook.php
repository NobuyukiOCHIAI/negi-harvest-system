<?php
/**
 * 栽培能力見通し → 営業リコメンド / 破棄候補
 *
 * 方針（2026-08-11 改訂）:
 * - 能力 = オープン予測 + 予定定植 + 「空き後猶予5日で必ず定植」する仮想回転
 * - 出荷に合わせるのではなく、フル回転能力を先に見通し、差分を営業へ返す
 * - 昨対プラスを週次で監視
 */
require_once __DIR__ . '/gcal_shipments.php';
require_once __DIR__ . '/plant_schedule.php';
require_once __DIR__ . '/overgrow_metrics.php';
require_once __DIR__ . '/rotation_capacity.php';

const GF_CAPACITY_HORIZON_WEEKS = 16;
const GF_SURPLUS_WEEK_KG = 50.0;
const GF_TIGHTEN_WEEK_KG = 50.0;
const GF_OUTLOOK_MIN_RUN_WEEKS = 2;
/** あるべき出荷 = 能力 × この比率（確定: 1.0 = バッファなし） */
const GF_TARGET_SHIP_RATIO = 1.0;
const GF_DISCARD_DAYS_STRONG = 14;
const GF_DISCARD_DAYS_PARTIAL = 7;
const GF_DISCARD_RATIO_MIN = 0.30;

/**
 * 週次バランス（回転込み能力）
 *
 * @return list<array>
 */
function capacity_weekly_balance(mysqli $link, int $weeksAhead = GF_CAPACITY_HORIZON_WEEKS): array
{
    $outlook = rotation_capacity_outlook($link, $weeksAhead);
    return $outlook['weeks'];
}

/**
 * @param list<array> $weeks
 * @return list<array>
 */
function capacity_sales_recommendations(array $weeks): array
{
    $runs = [];
    $curType = null;
    $buf = [];

    $flush = static function () use (&$runs, &$curType, &$buf): void {
        if ($curType === null || $curType === 'flat' || count($buf) < GF_OUTLOOK_MIN_RUN_WEEKS) {
            $buf = [];
            $curType = null;
            return;
        }
        $balances = array_column($buf, 'balance_kg');
        $avg = array_sum($balances) / count($balances);
        $total = array_sum($balances);
        $start = $buf[0]['week'];
        $end = $buf[count($buf) - 1]['week'];
        $n = count($buf);
        if ($curType === 'expand') {
            $kg = round(max(0.0, $avg), 0);
            $runs[] = [
                'type' => 'expand',
                'start_week' => $start,
                'end_week' => $end,
                'weeks' => $n,
                'avg_kg' => $kg,
                'total_kg' => round(max(0.0, $total), 0),
                'label' => '出荷拡大',
                'detail' => sprintf(
                    '%s週から約%d週間、週あたり +%.0fkg（フル回転能力の余剰）。営業拡大を推奨',
                    date('n/j', strtotime($start)),
                    $n,
                    $kg
                ),
            ];
        } else {
            $kg = round(max(0.0, -$avg), 0);
            $runs[] = [
                'type' => 'tighten',
                'start_week' => $start,
                'end_week' => $end,
                'weeks' => $n,
                'avg_kg' => $kg,
                'total_kg' => round(max(0.0, -$total), 0),
                'label' => '出荷絞り',
                'detail' => sprintf(
                    '%s週から約%d週間、週あたり −%.0fkg（能力不足）。出荷抑制か定植加速を検討',
                    date('n/j', strtotime($start)),
                    $n,
                    $kg
                ),
            ];
        }
        $buf = [];
        $curType = null;
    };

    foreach ($weeks as $w) {
        $sig = $w['signal'];
        if ($sig !== $curType) {
            $flush();
            $curType = $sig;
            $buf = [$w];
        } else {
            $buf[] = $w;
        }
    }
    $flush();

    // 昨対割れブロックも営業ではなく「栽培側」リコメンドとして追加
    $yoyBuf = [];
    foreach ($weeks as $w) {
        if (($w['yoy_signal'] ?? '') === 'yoy_miss') {
            $yoyBuf[] = $w;
        } elseif ($yoyBuf) {
            if (count($yoyBuf) >= 1) {
                $start = $yoyBuf[0]['week'];
                $end = $yoyBuf[count($yoyBuf) - 1]['week'];
                $avgMiss = 0.0;
                foreach ($yoyBuf as $y) {
                    $avgMiss += max(0.0, -$y['yoy_diff_kg']);
                }
                $avgMiss /= count($yoyBuf);
                $runs[] = [
                    'type' => 'yoy_miss',
                    'start_week' => $start,
                    'end_week' => $end,
                    'weeks' => count($yoyBuf),
                    'avg_kg' => round($avgMiss, 0),
                    'total_kg' => round($avgMiss * count($yoyBuf), 0),
                    'label' => '昨対割れ',
                    'detail' => sprintf(
                        '%s〜%s週で能力が昨対を下回る見込み（平均 −%.0fkg/週）。空き放置・破棄遅延・過栽培を点検し定植を維持',
                        date('n/j', strtotime($start)),
                        date('n/j', strtotime($end)),
                        $avgMiss
                    ),
                ];
            }
            $yoyBuf = [];
        }
    }
    if (count($yoyBuf) >= 1) {
        $start = $yoyBuf[0]['week'];
        $end = $yoyBuf[count($yoyBuf) - 1]['week'];
        $avgMiss = 0.0;
        foreach ($yoyBuf as $y) {
            $avgMiss += max(0.0, -$y['yoy_diff_kg']);
        }
        $avgMiss /= count($yoyBuf);
        $runs[] = [
            'type' => 'yoy_miss',
            'start_week' => $start,
            'end_week' => $end,
            'weeks' => count($yoyBuf),
            'avg_kg' => round($avgMiss, 0),
            'total_kg' => round($avgMiss * count($yoyBuf), 0),
            'label' => '昨対割れ',
            'detail' => sprintf(
                '%s〜%s週で能力が昨対を下回る見込み（平均 −%.0fkg/週）。定植維持・回転を優先',
                date('n/j', strtotime($start)),
                date('n/j', strtotime($end)),
                $avgMiss
            ),
        ];
    }

    return $runs;
}

/**
 * @return list<array>
 */
function capacity_discard_candidates(mysqli $link): array
{
    $open = open_cycle_progress($link);
    $shortageAhead = false;
    foreach (capacity_weekly_balance($link, 8) as $w) {
        if ($w['balance_kg'] <= -GF_TIGHTEN_WEEK_KG) {
            $shortageAhead = true;
            break;
        }
    }
    $emptyN = count(plant_schedule_empty_beds($link));

    $out = [];
    foreach ($open as $op) {
        $past = (int)$op['days_past_expected'];
        $ratio = (float)$op['ratio_sum'];
        $reason = null;
        $priority = 0;

        if ($past >= GF_DISCARD_DAYS_STRONG) {
            $reason = sprintf('予測超過 +%d日（ベッドを空けて次定植へ）', $past);
            $priority = 100 + $past;
        } elseif ($past >= GF_DISCARD_DAYS_PARTIAL && $ratio >= GF_DISCARD_RATIO_MIN) {
            $reason = sprintf(
                '予測超過 +%d日かつ部分収穫 %.0f%% — 残りを破棄して回転を優先可',
                $past,
                $ratio * 100
            );
            $priority = 50 + $past;
        }

        if ($reason === null) {
            continue;
        }

        $pred = $op['postproc_yield'] ?? $op['pred_yield'];
        $remain = null;
        if ($pred !== null) {
            $remain = max(0.0, (float)$pred - (float)$op['harvested_kg']);
        }

        $out[] = [
            'cycle_id' => $op['cycle_id'],
            'bed_name' => $op['bed_name'],
            'group_type' => $op['group_type'],
            'plant_date' => $op['plant_date'],
            'expected_harvest' => $op['expected_harvest'],
            'days_past' => $past,
            'harvested_kg' => (float)$op['harvested_kg'],
            'ratio_sum' => $ratio,
            'remain_kg' => $remain !== null ? round($remain, 1) : null,
            'reason' => $reason,
            'priority' => $priority,
            'next_plant_pressure' => $shortageAhead && $emptyN < 3,
            'buffer_label' => $op['buffer_label'],
        ];
    }

    usort($out, static fn($a, $b) => $b['priority'] <=> $a['priority']);
    return $out;
}

/**
 * @return array{ok:bool,message:string}
 */
function capacity_discard_cycle(mysqli $link, int $cycleId, ?float $remainKgOverride = null): array
{
    if ($cycleId <= 0) {
        return ['ok' => false, 'message' => 'cycle_id 不正'];
    }

    $stmt = mysqli_prepare(
        $link,
        "SELECT c.id, c.harvest_end, c.harvest_start,
                (SELECT COALESCE(SUM(h.harvest_kg),0) FROM harvests h WHERE h.cycle_id = c.id) AS harvested_kg,
                (SELECT COALESCE(SUM(h.harvest_ratio),0) FROM harvests h WHERE h.cycle_id = c.id) AS ratio_sum,
                (SELECT COALESCE(p.postproc_total_kg, p.pred_total_kg) FROM predictions p
                  WHERE p.cycle_id = c.id
                  ORDER BY p.created_at DESC LIMIT 1) AS pred_kg
         FROM cycles c WHERE c.id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'i', $cycleId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$row) {
        return ['ok' => false, 'message' => 'サイクルが見つかりません'];
    }
    if ($row['harvest_end'] !== null && $row['harvest_end'] !== '') {
        return ['ok' => false, 'message' => 'すでに収穫完了しています'];
    }

    $today = date('Y-m-d');
    $harvested = (float)$row['harvested_kg'];
    $pred = $row['pred_kg'] !== null ? (float)$row['pred_kg'] : null;
    $remain = $remainKgOverride;
    if ($remain === null && $pred !== null) {
        $remain = max(0.0, $pred - $harvested);
    }
    if ($remain === null) {
        $remain = 0.0;
    }
    $remain = round($remain, 2);

    $gomiId = null;
    $lt = mysqli_query($link, "SELECT id FROM loss_types WHERE name IN ('GOMI','ゴミ','gomi') ORDER BY id ASC LIMIT 1");
    if ($lt && ($lr = mysqli_fetch_assoc($lt))) {
        $gomiId = (int)$lr['id'];
    }
    if ($lt) {
        mysqli_free_result($lt);
    }
    if ($gomiId === null) {
        $gomiId = 2;
    }

    mysqli_begin_transaction($link);
    try {
        if ($remain > 0.01) {
            $ratioLeft = max(0.01, 1.0 - (float)$row['ratio_sum']);
            $note = '能力画面から破棄（次定植機会優先）';
            $ins = mysqli_prepare(
                $link,
                "INSERT INTO harvests (cycle_id, harvest_date, harvest_kg, loss_type_id, harvest_ratio, note)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($ins, 'isdids', $cycleId, $today, $remain, $gomiId, $ratioLeft, $note);
            if (!mysqli_stmt_execute($ins)) {
                throw new RuntimeException('harvest insert failed');
            }
            mysqli_stmt_close($ins);
        }

        $harvestStart = $row['harvest_start'];
        if ($harvestStart === null || $harvestStart === '') {
            $harvestStart = $today;
        }
        $upd = mysqli_prepare(
            $link,
            "UPDATE cycles SET harvest_start = COALESCE(harvest_start, ?), harvest_end = ? WHERE id = ? AND harvest_end IS NULL"
        );
        mysqli_stmt_bind_param($upd, 'ssi', $harvestStart, $today, $cycleId);
        if (!mysqli_stmt_execute($upd) || mysqli_stmt_affected_rows($upd) < 1) {
            throw new RuntimeException('cycle close failed');
        }
        mysqli_stmt_close($upd);

        mysqli_commit($link);
    } catch (Throwable $e) {
        mysqli_rollback($link);
        return ['ok' => false, 'message' => '破棄処理に失敗: ' . $e->getMessage()];
    }

    $msg = $remain > 0.01
        ? sprintf('破棄完了（GOMI %.1fkg 記録・ベッドを空けました）', $remain)
        : '破棄完了（残量0・ベッドを空けました）';
    return ['ok' => true, 'message' => $msg];
}

/**
 * @return array
 */
function capacity_outlook_summary(mysqli $link): array
{
    $outlook = rotation_capacity_outlook($link, GF_CAPACITY_HORIZON_WEEKS);
    $weeks = $outlook['weeks'];
    $recs = capacity_sales_recommendations($weeks);
    $discards = capacity_discard_candidates($link);
    $cum8 = 0.0;
    foreach (array_slice($weeks, 0, 8) as $w) {
        $cum8 += $w['balance_kg'];
    }
    return [
        'weeks' => $weeks,
        'recommendations' => $recs,
        'discards' => $discards,
        'expand_n' => count(array_filter($recs, static fn($r) => $r['type'] === 'expand')),
        'tighten_n' => count(array_filter($recs, static fn($r) => $r['type'] === 'tighten')),
        'yoy_miss_n' => count(array_filter($recs, static fn($r) => $r['type'] === 'yoy_miss')),
        'discard_n' => count($discards),
        'cum_balance_8w' => round($cum8, 1),
        'empty_beds' => count(plant_schedule_empty_beds($link)),
        'zero_weeks' => $outlook['zero_weeks'],
        'yoy_beat_weeks' => $outlook['yoy_beat_weeks'],
        'yoy_miss_weeks' => $outlook['yoy_miss_weeks'],
        'sim_cycles' => $outlook['sim_cycles'],
        'open_kg_total' => $outlook['open_kg_total'],
        'rotation_kg_total' => $outlook['rotation_kg_total'],
        'planned_kg_total' => $outlook['planned_kg_total'],
        'defaults' => $outlook['defaults'],
        'grace_days' => $outlook['grace_days'],
        'plant_events' => $outlook['plant_events'],
    ];
}
