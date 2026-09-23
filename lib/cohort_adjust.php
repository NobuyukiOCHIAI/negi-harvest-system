<?php
/**
 * 同世代補正（正本 §6.1）
 *
 * 完了床の実績が定植時予測を下回り続けているとき、未収穫の予定kgに上限を掛ける。
 * 完了実績と plant_schedule は書き換えない。
 */

const GF_COHORT_WINDOW_DAYS = 5;
const GF_COHORT_RATIO_LT = 0.90;
const GF_COHORT_FLOOR = 0.75;
const GF_COHORT_MIN_N = 2;

/**
 * @param list<float> $ratios
 */
function gf_cohort_median(array $ratios): ?float
{
    $vals = [];
    foreach ($ratios as $r) {
        $vals[] = (float)$r;
    }
    if ($vals === []) {
        return null;
    }
    sort($vals, SORT_NUMERIC);
    $n = count($vals);
    $mid = intdiv($n, 2);
    if ($n % 2 === 1) {
        return $vals[$mid];
    }
    return ($vals[$mid - 1] + $vals[$mid]) / 2;
}

/**
 * @param list<float> $ratios すでに 0.90 未満だけ
 */
function gf_cohort_factor_from_ratios(array $ratios): ?float
{
    if (count($ratios) < GF_COHORT_MIN_N) {
        return null;
    }
    $median = gf_cohort_median($ratios);
    if ($median === null) {
        return null;
    }
    return max(GF_COHORT_FLOOR, $median);
}

/**
 * 同じ group_type を優先。証拠が2床未満なら窓内全体。
 *
 * @param list<array{group:string,ratio:float}> $peers
 * @return list<float>
 */
function gf_cohort_pick_ratios(array $peers, string $groupType): array
{
    $weak = static function (array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $ratio = (float)$row['ratio'];
            if ($ratio < GF_COHORT_RATIO_LT) {
                $out[] = $ratio;
            }
        }
        return $out;
    };
    $same = [];
    foreach ($peers as $row) {
        if ((string)$row['group'] === $groupType) {
            $same[] = $row;
        }
    }
    $picked = $weak($same);
    if (count($picked) >= GF_COHORT_MIN_N) {
        return $picked;
    }
    return $weak($peers);
}

/**
 * @return list<array{plant_date:string,group:string,ratio:float,cycle_id:int}>
 */
function gf_cohort_closed_index(mysqli $link): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = [];
    $sql = "
SELECT
  c.id AS cycle_id,
  c.plant_date,
  b.group_type,
  (SELECT COALESCE(SUM(h.harvest_kg), 0) FROM harvests h WHERE h.cycle_id = c.id) AS actual_kg,
  (
    SELECT p.pred_total_kg
    FROM predictions p
    WHERE p.cycle_id = c.id
      AND p.pred_total_kg IS NOT NULL
      AND p.model_id NOT LIKE '%lock_plant_kg%'
      AND p.model_id NOT LIKE '%cohort%'
    ORDER BY
      CASE
        WHEN p.model_id LIKE '%hgb_plant%' OR p.model_id LIKE '%plant_plus%' THEN 0
        ELSE 1
      END,
      p.created_at ASC,
      p.id ASC
    LIMIT 1
  ) AS plant_kg
FROM cycles c
JOIN beds b ON b.id = c.bed_id
WHERE c.harvest_end IS NOT NULL
  AND c.plant_date IS NOT NULL
";
    $res = mysqli_query($link, $sql);
    if (!$res) {
        return $cache;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $plantKg = $row['plant_kg'] !== null ? (float)$row['plant_kg'] : 0.0;
        if ($plantKg <= 0) {
            continue;
        }
        $cache[] = [
            'cycle_id' => (int)$row['cycle_id'],
            'plant_date' => (string)$row['plant_date'],
            'group' => (string)$row['group_type'],
            'ratio' => (float)$row['actual_kg'] / $plantKg,
        ];
    }
    mysqli_free_result($res);
    return $cache;
}

function gf_cohort_plant_kg(mysqli $link, int $cycleId): ?float
{
    $stmt = mysqli_prepare(
        $link,
        "SELECT pred_total_kg
         FROM predictions
         WHERE cycle_id = ?
           AND pred_total_kg IS NOT NULL
           AND model_id NOT LIKE '%lock_plant_kg%'
           AND model_id NOT LIKE '%cohort%'
         ORDER BY
           CASE
             WHEN model_id LIKE '%hgb_plant%' OR model_id LIKE '%plant_plus%' THEN 0
             ELSE 1
           END,
           created_at ASC,
           id ASC
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, 'i', $cycleId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || $row['pred_total_kg'] === null) {
        return null;
    }
    $kg = (float)$row['pred_total_kg'];
    return $kg > 0 ? $kg : null;
}

/**
 * @return array{factor:float,median:float,n:int}|null
 */
function gf_cohort_factor_at(mysqli $link, string $plantDate, string $groupType): ?array
{
    if ($plantDate === '') {
        return null;
    }
    $from = date('Y-m-d', strtotime($plantDate . ' -' . GF_COHORT_WINDOW_DAYS . ' days'));
    $to = date('Y-m-d', strtotime($plantDate . ' +' . GF_COHORT_WINDOW_DAYS . ' days'));
    $peers = [];
    foreach (gf_cohort_closed_index($link) as $row) {
        $d = $row['plant_date'];
        if ($d < $from || $d > $to) {
            continue;
        }
        $peers[] = $row;
    }
    $ratios = gf_cohort_pick_ratios($peers, $groupType);
    $median = gf_cohort_median($ratios);
    $factor = gf_cohort_factor_from_ratios($ratios);
    if ($factor === null || $median === null) {
        return null;
    }
    return [
        'factor' => $factor,
        'median' => $median,
        'n' => count($ratios),
    ];
}

function gf_cohort_scale_yield(mysqli $link, string $plantDate, string $groupType, float $yieldKg): float
{
    if ($yieldKg <= 0) {
        return $yieldKg;
    }
    $hit = gf_cohort_factor_at($link, $plantDate, $groupType);
    if ($hit === null) {
        return $yieldKg;
    }
    return round($yieldKg * $hit['factor'], 1);
}

function gf_cohort_log(array $ctx): void
{
    $dir = '/home/love-media/forc_logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $line = date('c') . ' cohort_adjust ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents($dir . '/app_' . date('Ymd') . '.log', $line, FILE_APPEND | LOCK_EX);
}

/**
 * 上限を超えるときだけ、追加する予測行を返す。部分収穫済みは null。
 *
 * @param array{days:float,yield:float,model_id:string} $pred
 * @return array{days:float,yield:float,model_id:string}|null
 */
function gf_cohort_capped_pred(mysqli $link, int $cycleId, array $pred): ?array
{
    $stmt = mysqli_prepare(
        $link,
        'SELECT c.plant_date, c.harvest_start, b.group_type
         FROM cycles c JOIN beds b ON b.id = c.bed_id
         WHERE c.id = ? LIMIT 1'
    );
    mysqli_stmt_bind_param($stmt, 'i', $cycleId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);
    if (!$row || $row['harvest_start'] !== null) {
        return null;
    }
    $plantDate = (string)$row['plant_date'];
    $plantKg = gf_cohort_plant_kg($link, $cycleId);
    if ($plantKg === null || $plantKg <= 0 || $plantDate === '') {
        return null;
    }
    $hit = gf_cohort_factor_at($link, $plantDate, (string)$row['group_type']);
    if ($hit === null) {
        return null;
    }
    $cap = round($plantKg * $hit['factor'], 3);
    $current = (float)$pred['yield'];
    if ($current <= $cap + 0.05) {
        return null;
    }
    $modelId = (string)($pred['model_id'] ?? 'mid');
    if (strpos($modelId, 'cohort') === false) {
        $modelId .= '_cohort';
    }
    gf_cohort_log([
        'cycle_id' => $cycleId,
        'plant_date' => $plantDate,
        'n' => $hit['n'],
        'median' => round($hit['median'], 4),
        'factor' => round($hit['factor'], 4),
        'plant_kg' => round($plantKg, 3),
        'corrected_kg' => $cap,
    ]);
    return [
        'days' => (float)$pred['days'],
        'yield' => $cap,
        'model_id' => $modelId,
    ];
}

/**
 * 直近の再予測結果が上限を超える未収穫へ、cohort 行を1行足す。
 *
 * @return array{inserted:int,skipped:int}
 */
function gf_cohort_apply_open_caps(mysqli $link): array
{
    require_once __DIR__ . '/predict_ridge.php';
    $inserted = 0;
    $skipped = 0;
    $res = mysqli_query(
        $link,
        "SELECT c.id
         FROM cycles c
         WHERE c.harvest_end IS NULL AND c.harvest_start IS NULL AND c.plant_date IS NOT NULL"
    );
    if (!$res) {
        return ['inserted' => 0, 'skipped' => 0];
    }
    $ids = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $ids[] = (int)$row['id'];
    }
    mysqli_free_result($res);
    foreach ($ids as $cycleId) {
        $stmt = mysqli_prepare(
            $link,
            "SELECT pred_days, pred_total_kg, model_id
             FROM predictions
             WHERE cycle_id = ? AND model_id NOT LIKE '%cohort%'
             ORDER BY created_at DESC, id DESC
             LIMIT 1"
        );
        mysqli_stmt_bind_param($stmt, 'i', $cycleId);
        mysqli_stmt_execute($stmt);
        $latest = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
        if (!$latest || $latest['pred_total_kg'] === null) {
            $skipped++;
            continue;
        }
        $pred = [
            'days' => (float)$latest['pred_days'],
            'yield' => (float)$latest['pred_total_kg'],
            'model_id' => (string)$latest['model_id'],
        ];
        $capped = gf_cohort_capped_pred($link, $cycleId, $pred);
        if ($capped === null) {
            $skipped++;
            continue;
        }
        $chk = mysqli_prepare(
            $link,
            "SELECT pred_total_kg, model_id FROM predictions
             WHERE cycle_id = ? ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        mysqli_stmt_bind_param($chk, 'i', $cycleId);
        mysqli_stmt_execute($chk);
        $top = mysqli_fetch_assoc(mysqli_stmt_get_result($chk));
        mysqli_stmt_close($chk);
        if ($top && strpos((string)$top['model_id'], 'cohort') !== false
            && abs((float)$top['pred_total_kg'] - (float)$capped['yield']) < 0.05) {
            $skipped++;
            continue;
        }
        insert_prediction_row($link, $cycleId, $capped, null);
        $inserted++;
    }
    return ['inserted' => $inserted, 'skipped' => $skipped];
}
