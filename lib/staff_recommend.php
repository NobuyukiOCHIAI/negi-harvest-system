<?php
/**
 * 圃場スタッフ向け・自動リコメンド（優先度付き）
 */
require_once __DIR__ . '/plant_schedule.php';
require_once __DIR__ . '/capacity_outlook.php';
require_once __DIR__ . '/rotation_capacity.php';
require_once __DIR__ . '/overgrow_metrics.php';

/**
 * @return list<array{
 *   priority:int, urgency:string, type:string, title:string, detail:string,
 *   href:string, bed_name:?string
 * }>
 */
function staff_auto_recommendations(mysqli $link): array
{
    $today = date('Y-m-d');
    $out = [];

    // 1) 猶予切れ間近・超過の空きベッド → 今日定植
    $states = rotation_bed_states($link);
    $reserved = plant_schedule_reserved_beds($link);
    foreach ($states['beds'] as $b) {
        if ($b['source'] !== 'empty') {
            continue;
        }
        $free = $b['free_date'] ?: $today;
        $due = date('Y-m-d', strtotime($free . ' +' . GF_REPLANT_GRACE_DAYS . ' days'));
        $daysLeft = (int)floor((strtotime($due) - strtotime($today)) / 86400);
        if (isset($reserved[(int)$b['bed_id']])) {
            continue;
        }
        if ($daysLeft <= 0) {
            $out[] = [
                'priority' => 100 - $daysLeft,
                'urgency' => 'critical',
                'type' => 'plant',
                'title' => '定植期限超過: ' . $b['name'],
                'detail' => '空きから' . GF_REPLANT_GRACE_DAYS . '日超過。本日定植して回転を維持',
                'href' => 'data_entry/planting.php?bed_id=' . (int)$b['bed_id'],
                'bed_name' => $b['name'],
            ];
        } elseif ($daysLeft <= 2) {
            $out[] = [
                'priority' => 80 - $daysLeft,
                'urgency' => 'high',
                'type' => 'plant',
                'title' => '定植猶予残り' . $daysLeft . '日: ' . $b['name'],
                'detail' => '空き後' . GF_REPLANT_GRACE_DAYS . '日ルール。早めに定植',
                'href' => 'data_entry/planting.php?bed_id=' . (int)$b['bed_id'],
                'bed_name' => $b['name'],
            ];
        }
    }

    // 2) 承認済み・予定の今日までの定植
    $chk = mysqli_query($link, "SHOW TABLES LIKE 'plant_schedule'");
    $has = $chk && mysqli_num_rows($chk) > 0;
    if ($chk) {
        mysqli_free_result($chk);
    }
    if ($has) {
        $sql = "
SELECT s.*, b.name AS bed_name
FROM plant_schedule s
JOIN beds b ON b.id = s.bed_id
WHERE s.status IN ('planned','approved')
  AND s.planned_plant_date <= ?
  AND NOT EXISTS (SELECT 1 FROM cycles c WHERE c.bed_id = s.bed_id AND c.harvest_end IS NULL)
ORDER BY s.planned_plant_date ASC
LIMIT 20";
        $stmt = mysqli_prepare($link, $sql);
        mysqli_stmt_bind_param($stmt, 's', $today);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $late = $row['planned_plant_date'] < $today;
            $out[] = [
                'priority' => $late ? 95 : 70,
                'urgency' => $late ? 'critical' : 'high',
                'type' => 'plant_plan',
                'title' => ($late ? '定植遅れ: ' : '定植予定: ') . $row['bed_name'],
                'detail' => '計画日 ' . $row['planned_plant_date'] . ($row['note'] ? ' · ' . $row['note'] : ''),
                'href' => 'data_entry/planting.php?bed_id=' . (int)$row['bed_id'] . '&schedule_id=' . (int)$row['id'],
                'bed_name' => $row['bed_name'],
            ];
        }
        mysqli_stmt_close($stmt);
    }

    // 3) 収穫候補
    foreach (open_cycle_progress($link) as $op) {
        $due = false;
        if (!empty($op['harvest_start'])) {
            $due = true;
        } elseif (!empty($op['expected_harvest']) && $op['expected_harvest'] <= $today) {
            $due = true;
        }
        if (!$due) {
            continue;
        }
        $out[] = [
            'priority' => (int)$op['risk'] ? 90 : 60,
            'urgency' => (int)$op['risk'] ? 'high' : 'normal',
            'type' => 'harvest',
            'title' => '収穫: ' . $op['bed_name'],
            'detail' => $op['buffer_label'],
            'href' => 'data_entry/harvest.php?cycle_id=' . (int)$op['cycle_id'],
            'bed_name' => $op['bed_name'],
        ];
    }

    // 4) 破棄候補（上位）
    foreach (array_slice(capacity_discard_candidates($link), 0, 5) as $d) {
        $out[] = [
            'priority' => 85,
            'urgency' => 'high',
            'type' => 'discard',
            'title' => '破棄検討: ' . $d['bed_name'],
            'detail' => $d['reason'],
            'href' => 'capacity.php',
            'bed_name' => $d['bed_name'],
        ];
    }

    usort($out, static fn($a, $b) => $b['priority'] <=> $a['priority']);
    // 同一ベッドは最優先1件
    $seen = [];
    $uniq = [];
    foreach ($out as $item) {
        $key = ($item['bed_name'] ?? '') . '|' . $item['type'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $uniq[] = $item;
    }
    return array_slice($uniq, 0, 25);
}
