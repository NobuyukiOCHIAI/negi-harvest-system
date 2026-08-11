<?php
/**
 * 過栽培・在庫バッファ指標（運用指標_生育と過栽培.md）
 */

/**
 * 定植週（月曜始まり）ごとの集約。直近 $limitWeeks 週（定植日基準）。
 *
 * @return list<array{
 *   week:string,n:int,mean_delay:?float,pct_delay_ge7:?float,pct_judge_late:float,
 *   gomi_kg:float,total_kg:float,gomi_ratio:float,risk:int
 * }>
 */
function overgrow_weekly_stats(mysqli $link, int $limitWeeks = 16): array
{
    $sql = "
SELECT
  DATE_SUB(c.plant_date, INTERVAL WEEKDAY(c.plant_date) DAY) AS week_mon,
  c.id AS cycle_id,
  DATEDIFF(c.harvest_start, c.plant_date) AS y_days,
  (
    SELECT p.pred_days FROM predictions p
    WHERE p.cycle_id = c.id
    ORDER BY
      CASE WHEN p.model_id LIKE '%plant_plus_w%' THEN 0 ELSE 1 END,
      p.created_at ASC
    LIMIT 1
  ) AS pred_days,
  (
    SELECT h.timing_judge FROM harvests h
    WHERE h.cycle_id = c.id
    ORDER BY h.harvest_date ASC, h.id ASC
    LIMIT 1
  ) AS judge,
  (
    SELECT COALESCE(SUM(h2.harvest_kg),0) FROM harvests h2
    WHERE h2.cycle_id = c.id AND h2.loss_type_id = 2
  ) AS gomi_kg,
  (
    SELECT COALESCE(SUM(h3.harvest_kg),0) FROM harvests h3
    WHERE h3.cycle_id = c.id
  ) AS total_kg
FROM cycles c
WHERE c.harvest_end IS NOT NULL
  AND c.harvest_start IS NOT NULL
  AND DATEDIFF(c.harvest_start, c.plant_date) > 0
ORDER BY week_mon DESC, c.id DESC
";
    $res = mysqli_query($link, $sql);
    $buckets = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $week = $row['week_mon'];
        if (!isset($buckets[$week])) {
            $buckets[$week] = [
                'week' => $week,
                'n' => 0,
                'delay_sum' => 0.0,
                'n_pred' => 0,
                'delay_ge7' => 0,
                'late_judge' => 0,
                'gomi_kg' => 0.0,
                'total_kg' => 0.0,
            ];
        }
        $b =& $buckets[$week];
        $b['n']++;
        $b['gomi_kg'] += (float)$row['gomi_kg'];
        $b['total_kg'] += (float)$row['total_kg'];
        if (($row['judge'] ?? '') === '遅い') {
            $b['late_judge']++;
        }
        if ($row['pred_days'] !== null && $row['pred_days'] !== '') {
            $delay = (float)$row['y_days'] - (float)$row['pred_days'];
            $b['delay_sum'] += $delay;
            $b['n_pred']++;
            if ($delay >= 7) {
                $b['delay_ge7']++;
            }
        }
        unset($b);
    }
    mysqli_free_result($res);

    $out = [];
    foreach ($buckets as $b) {
        $n = $b['n'];
        $np = $b['n_pred'];
        $pctLate = $n > 0 ? 100.0 * $b['late_judge'] / $n : 0.0;
        $pct7 = $np > 0 ? 100.0 * $b['delay_ge7'] / $np : null;
        $mean = $np > 0 ? $b['delay_sum'] / $np : null;
        $gomiR = $b['total_kg'] > 0 ? $b['gomi_kg'] / $b['total_kg'] : 0.0;
        $risk = 0;
        if ($pctLate >= 40.0) {
            $risk = 1;
        }
        if ($pct7 !== null && $pct7 >= 50.0) {
            $risk = 1;
        }
        if ($gomiR >= 0.08) {
            $risk = 1;
        }
        $out[] = [
            'week' => $b['week'],
            'n' => $n,
            'mean_delay' => $mean !== null ? round($mean, 1) : null,
            'pct_delay_ge7' => $pct7 !== null ? round($pct7, 0) : null,
            'pct_judge_late' => round($pctLate, 0),
            'gomi_kg' => round($b['gomi_kg'], 1),
            'total_kg' => round($b['total_kg'], 1),
            'gomi_ratio' => round($gomiR, 3),
            'risk' => $risk,
        ];
        if (count($out) >= $limitWeeks) {
            break;
        }
    }
    return $out;
}

/**
 * 未完了サイクルの進捗（予測日との差＝在庫バッファ／遅延）。
 *
 * @return list<array>
 */
function open_cycle_progress(mysqli $link): array
{
    $sql = "
SELECT
  c.id AS cycle_id,
  b.name AS bed_name,
  b.group_type,
  c.plant_date,
  c.harvest_start,
  (
    SELECT p.pred_days FROM predictions p
    WHERE p.cycle_id = c.id
    ORDER BY
      CASE WHEN p.model_id LIKE '%plant_plus_w%' THEN 0 ELSE 1 END,
      p.created_at ASC
    LIMIT 1
  ) AS pred_days_plant,
  (
    SELECT p.pred_total_kg FROM predictions p
    WHERE p.cycle_id = c.id
    ORDER BY p.created_at DESC
    LIMIT 1
  ) AS pred_yield_latest,
  (
    SELECT p.postproc_total_kg FROM predictions p
    WHERE p.cycle_id = c.id
    ORDER BY p.created_at DESC
    LIMIT 1
  ) AS postproc_yield,
  (
    SELECT COALESCE(SUM(h.harvest_kg),0) FROM harvests h WHERE h.cycle_id = c.id
  ) AS harvested_kg,
  (
    SELECT COALESCE(SUM(h.harvest_ratio),0) FROM harvests h WHERE h.cycle_id = c.id
  ) AS ratio_sum
FROM cycles c
JOIN beds b ON b.id = c.bed_id
WHERE c.harvest_end IS NULL
ORDER BY c.plant_date ASC, b.name ASC
";
    $res = mysqli_query($link, $sql);
    $today = new DateTimeImmutable('today');
    $out = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $plant = $row['plant_date'] ? new DateTimeImmutable($row['plant_date']) : null;
        $predDays = $row['pred_days_plant'] !== null && $row['pred_days_plant'] !== ''
            ? (float)$row['pred_days_plant'] : null;
        $expected = null;
        $delayVsToday = null;
        $bufferLabel = '予測なし';
        if ($plant && $predDays !== null) {
            $expected = $plant->modify('+' . (int)round($predDays) . ' day');
            $delayVsToday = (int)floor(($expected->getTimestamp() - $today->getTimestamp()) / 86400);
            // 正=まだ予測日より前、負=予測日を過ぎている（在庫側）
            if ($delayVsToday > 0) {
                $bufferLabel = '予測まであと' . $delayVsToday . '日';
            } elseif ($delayVsToday === 0) {
                $bufferLabel = '予測日当日';
            } else {
                $bufferLabel = '予測超過+' . abs($delayVsToday) . '日（在庫側）';
            }
        }
        $riskOpen = ($delayVsToday !== null && $delayVsToday <= -7) ? 1 : 0;
        $out[] = [
            'cycle_id' => (int)$row['cycle_id'],
            'bed_name' => $row['bed_name'],
            'group_type' => $row['group_type'],
            'plant_date' => $row['plant_date'],
            'harvest_start' => $row['harvest_start'],
            'pred_days' => $predDays,
            'expected_harvest' => $expected ? $expected->format('Y-m-d') : null,
            'buffer_label' => $bufferLabel,
            'days_past_expected' => ($delayVsToday !== null && $delayVsToday < 0) ? abs($delayVsToday) : 0,
            'risk' => $riskOpen,
            'pred_yield' => $row['pred_yield_latest'] !== null ? (float)$row['pred_yield_latest'] : null,
            'postproc_yield' => $row['postproc_yield'] !== null ? (float)$row['postproc_yield'] : null,
            'harvested_kg' => (float)$row['harvested_kg'],
            'ratio_sum' => (float)$row['ratio_sum'],
        ];
    }
    mysqli_free_result($res);
    return $out;
}

/**
 * 昨年同じ定植週（±0）の risk 参照用。
 */
function overgrow_same_week_last_year(mysqli $link, string $weekMon): ?array
{
    $dt = new DateTimeImmutable($weekMon);
    $ly = $dt->modify('-1 year')->format('Y-m-d');
    foreach (overgrow_weekly_stats($link, 60) as $w) {
        if ($w['week'] === $ly) {
            return $w;
        }
    }
    return null;
}
