<?php
/**
 * 定植時予測の直近キャリブレーション（正本 §6.2）
 *
 * HGB/Ridge の系統バイアスを、植えた時点で見えていた完了実績の中央値で補正する。
 * 完了サイクルへの後付けINSERTはしない。評価・新規定植・未収穫の定植行にだけ使う。
 */

const GF_PLANT_CALIB_WINDOW_DAYS = 90;
const GF_PLANT_CALIB_MIN_N = 8;
const GF_PLANT_CALIB_KG_RATIO_MIN = 0.80;
const GF_PLANT_CALIB_KG_RATIO_MAX = 1.30;
const GF_PLANT_CALIB_DAY_ABS_MAX = 20.0;

/**
 * @param list<float> $vals
 */
function gf_plant_calib_median(array $vals): ?float
{
    $xs = [];
    foreach ($vals as $v) {
        $xs[] = (float)$v;
    }
    if ($xs === []) {
        return null;
    }
    sort($xs, SORT_NUMERIC);
    $n = count($xs);
    $mid = intdiv($n, 2);
    if ($n % 2 === 1) {
        return $xs[$mid];
    }
    return ($xs[$mid - 1] + $xs[$mid]) / 2;
}

/**
 * 定植モデル行だけ（pre_harvest / mid / lock / cohort 除外）。
 *
 * @return array{days:?float,kg:?float,model_id:?string}
 */
function gf_plant_calib_stored_plant_pred(mysqli $link, int $cycleId): array
{
    $stmt = mysqli_prepare(
        $link,
        "SELECT model_id, pred_days, pred_total_kg
         FROM predictions
         WHERE cycle_id = ?
           AND pred_total_kg IS NOT NULL
           AND model_id NOT LIKE '%lock_plant_kg%'
           AND model_id NOT LIKE '%cohort%'
           AND model_id NOT LIKE '%pre_harvest%'
           AND model_id NOT LIKE '%_mid%'
           AND (
             model_id LIKE '%hgb_plant%'
             OR model_id LIKE '%plant_plus%'
             OR model_id LIKE '%_fallback%'
             OR model_id LIKE '%calib%'
           )
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
    if (!$row) {
        return ['days' => null, 'kg' => null, 'model_id' => null];
    }
    $days = $row['pred_days'] !== null ? (float)$row['pred_days'] : null;
    $kg = $row['pred_total_kg'] !== null ? (float)$row['pred_total_kg'] : null;
    // calib 済み行は二重補正しないよう、呼び出し側で model_id を見る
    return [
        'days' => ($days !== null && $days > 0) ? $days : null,
        'kg' => ($kg !== null && $kg > 0) ? $kg : null,
        'model_id' => (string)($row['model_id'] ?? ''),
    ];
}

/**
 * 定植時モデルを plant+14 asof で再計算（DB非書き込み）。
 *
 * @return array{days:float,yield:float,model_id:string}|null
 */
function gf_plant_calib_recompute_raw(mysqli $link, int $cycleId, string $plantDate): ?array
{
    require_once __DIR__ . '/build_features.php';
    require_once __DIR__ . '/predict_ridge.php';
    require_once __DIR__ . '/predict_hgb_plant.php';
    try {
        $harvestStart = null;
        $st = mysqli_prepare($link, 'SELECT harvest_start FROM cycles WHERE id=? LIMIT 1');
        mysqli_stmt_bind_param($st, 'i', $cycleId);
        mysqli_stmt_execute($st);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        mysqli_stmt_close($st);
        if ($row && !empty($row['harvest_start'])) {
            $harvestStart = (string)$row['harvest_start'];
        }
        $asof = plant_feature_asof($link, $plantDate, $harvestStart);
        list($features,) = build_features_array($link, $cycleId, $asof);
        try {
            $pred = predict_hgb_plant_from_features($features);
        } catch (Throwable $e) {
            $pred = predict_ridge_from_features($features, 'plant');
            $pred['model_id'] = ($pred['model_id'] ?? 'ridge') . '_fallback';
        }
        $days = (float)($pred['days'] ?? 0);
        $yield = (float)($pred['yield'] ?? 0);
        if ($days <= 0 || $yield <= 0) {
            return null;
        }
        return [
            'days' => $days,
            'yield' => $yield,
            'model_id' => (string)$pred['model_id'],
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * 証拠インデックス（request内＋ファイルキャッシュ6時間）。
 * raw 定植予測（calib前）と実績の残差。
 *
 * @return list<array{plant_date:string,harvest_end:string,group:string,day_err:float,kg_ratio:float}>
 */
function gf_plant_calib_evidence_index(mysqli $link): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $dir = __DIR__ . '/../tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $path = $dir . '/plant_calib_evidence_w14_hold.json';
    if (is_readable($path) && (time() - (int)filemtime($path)) < 6 * 3600) {
        $decoded = json_decode((string)file_get_contents($path), true);
        if (is_array($decoded) && isset($decoded['rows']) && is_array($decoded['rows'])) {
            $cache = $decoded['rows'];
            return $cache;
        }
    }

    require_once __DIR__ . '/supply_ops.php';

    $cache = [];
    $sql = "
SELECT
  c.id AS cycle_id,
  c.plant_date,
  c.harvest_start,
  c.harvest_end,
  b.group_type,
  (
    SELECT COALESCE(SUM(h.harvest_kg), 0)
    FROM harvests h
    WHERE h.cycle_id = c.id
      AND (
        h.loss_type_id IS NULL
        OR h.loss_type_id NOT IN (
          SELECT id FROM loss_types WHERE name IN ('GOMI','ゴミ','gomi')
        )
      )
  ) AS actual_kg
FROM cycles c
JOIN beds b ON b.id = c.bed_id
WHERE c.harvest_end IS NOT NULL
  AND c.harvest_start IS NOT NULL
  AND c.plant_date IS NOT NULL
  AND c.harvest_end >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
ORDER BY c.harvest_end ASC
";
    $res = mysqli_query($link, $sql);
    if (!$res) {
        return $cache;
    }
    while ($row = mysqli_fetch_assoc($res)) {
        $cid = (int)$row['cycle_id'];
        $plant = (string)$row['plant_date'];
        $hs = (string)$row['harvest_start'];
        $actDays = (int)((strtotime($hs) - strtotime($plant)) / 86400);
        $actKg = (float)$row['actual_kg'];
        if ($actDays <= 0 || $actKg < 5) {
            continue;
        }
        // calib済み行は補正前に戻せないので raw 再計算
        $raw = gf_plant_calib_recompute_raw($link, $cid, $plant);
        if ($raw === null) {
            continue;
        }
        $predDays = $raw['days'];
        $predKg = $raw['yield'];
        if ($predDays <= 0 || $predKg <= 0) {
            continue;
        }
        // 意図在庫: 日数証拠から除外（kgは残す）
        $plantExp = date('Y-m-d', strtotime($plant . ' +' . (int)round($predDays) . ' day'));
        $hold = supply_is_intentional_hold($plantExp, $hs, (string)$row['harvest_end']);
        $entry = [
            'plant_date' => $plant,
            'harvest_end' => (string)$row['harvest_end'],
            'group' => (string)$row['group_type'],
            'kg_ratio' => $actKg / $predKg,
            'intentional_hold' => $hold,
        ];
        if (!$hold) {
            $entry['day_err'] = $actDays - $predDays; // 実績 − 予測
        }
        $cache[] = $entry;
    }
    mysqli_free_result($res);
    @file_put_contents(
        $path,
        json_encode(['built_at' => date('c'), 'rows' => $cache], JSON_UNESCAPED_UNICODE)
    );
    return $cache;
}

/**
 * 植えた日時点で見えていた証拠から補正係数を作る。
 *
 * @return array{day_offset:float,kg_ratio:float,n:int}|null
 */
function gf_plant_calib_at(mysqli $link, string $plantDate, string $groupType): ?array
{
    if ($plantDate === '') {
        return null;
    }
    $from = date('Y-m-d', strtotime($plantDate . ' -' . GF_PLANT_CALIB_WINDOW_DAYS . ' days'));
    $dayErrs = [];
    $kgRatios = [];
    $dayErrsAll = [];
    $kgRatiosAll = [];
    foreach (gf_plant_calib_evidence_index($link) as $ev) {
        // 植えた時点ではまだ終わっていない床は使わない
        if ($ev['harvest_end'] >= $plantDate) {
            continue;
        }
        if ($ev['harvest_end'] < $from) {
            continue;
        }
        $kgRatiosAll[] = $ev['kg_ratio'];
        if ($ev['group'] === $groupType) {
            $kgRatios[] = $ev['kg_ratio'];
        }
        // 意図在庫は日数証拠に混ぜない
        if (!isset($ev['day_err'])) {
            continue;
        }
        $dayErrsAll[] = $ev['day_err'];
        if ($ev['group'] === $groupType) {
            $dayErrs[] = $ev['day_err'];
        }
    }
    if (count($kgRatios) < GF_PLANT_CALIB_MIN_N) {
        $kgRatios = $kgRatiosAll;
    }
    if (count($dayErrs) < GF_PLANT_CALIB_MIN_N) {
        $dayErrs = $dayErrsAll;
    }
    if (count($dayErrs) < GF_PLANT_CALIB_MIN_N || count($kgRatios) < GF_PLANT_CALIB_MIN_N) {
        return null;
    }
    $dayOffset = gf_plant_calib_median($dayErrs);
    $kgRatio = gf_plant_calib_median($kgRatios);
    if ($dayOffset === null || $kgRatio === null) {
        return null;
    }
    if (abs($dayOffset) > GF_PLANT_CALIB_DAY_ABS_MAX) {
        $dayOffset = $dayOffset > 0 ? GF_PLANT_CALIB_DAY_ABS_MAX : -GF_PLANT_CALIB_DAY_ABS_MAX;
    }
    $kgRatio = max(GF_PLANT_CALIB_KG_RATIO_MIN, min(GF_PLANT_CALIB_KG_RATIO_MAX, $kgRatio));
    return [
        'day_offset' => round($dayOffset, 2),
        'kg_ratio' => round($kgRatio, 4),
        'n' => count($dayErrs),
    ];
}

/**
 * @param array{days:float,yield:float,model_id:string} $pred
 * @param string|null $asof 補正の基準日。null なら今日（運用のいまの季節バイアス）
 * @return array{days:float,yield:float,model_id:string,calib?:array}
 */
function gf_plant_apply_calib(mysqli $link, int $cycleId, array $pred, ?string $asof = null): array
{
    $modelId = (string)($pred['model_id'] ?? '');
    if (strpos($modelId, 'calib') !== false) {
        return $pred;
    }
    $st = mysqli_prepare(
        $link,
        "SELECT c.plant_date, b.group_type
         FROM cycles c JOIN beds b ON b.id = c.bed_id
         WHERE c.id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($st, 'i', $cycleId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    if (!$row || empty($row['plant_date'])) {
        return $pred;
    }
    $asofUse = $asof ?: date('Y-m-d');
    $hit = gf_plant_calib_at($link, $asofUse, (string)$row['group_type']);
    if ($hit === null) {
        return $pred;
    }
    $days = max(1.0, (float)$pred['days'] + (float)$hit['day_offset']);
    $yield = max(0.0, (float)$pred['yield'] * (float)$hit['kg_ratio']);
    if (strpos($modelId, 'calib') === false) {
        $modelId .= '_calib';
    }
    $pred['days'] = round($days, 3);
    $pred['yield'] = round($yield, 3);
    $pred['model_id'] = $modelId;
    $pred['calib'] = $hit;
    return $pred;
}

/**
 * mid（pre_harvest）用の直近キャリブ。収穫開始前の mid 行 vs 実績。
 *
 * @return array{day_offset:float,kg_ratio:float,n:int}|null
 */
function gf_mid_calib_current(mysqli $link, string $groupType = ''): ?array
{
    static $cache = [];
    $key = $groupType !== '' ? $groupType : '*';
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $asof = date('Y-m-d');
    $from = date('Y-m-d', strtotime($asof . ' -' . GF_PLANT_CALIB_WINDOW_DAYS . ' days'));
    require_once __DIR__ . '/supply_ops.php';

    $sql = "
SELECT
  c.plant_date,
  c.harvest_start,
  c.harvest_end,
  b.group_type,
  DATEDIFF(c.harvest_start, c.plant_date) AS act_days,
  (
    SELECT COALESCE(SUM(h.harvest_kg),0) FROM harvests h
    WHERE h.cycle_id = c.id
      AND (h.loss_type_id IS NULL OR h.loss_type_id NOT IN (
        SELECT id FROM loss_types WHERE name IN ('GOMI','ゴミ','gomi')))
  ) AS act_kg,
  (
    SELECT p.pred_days FROM predictions p
    WHERE p.cycle_id = c.id
      AND p.model_id LIKE '%pre_harvest%'
      AND p.model_id NOT LIKE '%lock%'
      AND p.model_id NOT LIKE '%cohort%'
      AND p.model_id NOT LIKE '%calib%'
      AND p.created_at < TIMESTAMP(c.harvest_start)
    ORDER BY p.created_at DESC, p.id DESC LIMIT 1
  ) AS mid_days,
  (
    SELECT COALESCE(p.postproc_total_kg, p.pred_total_kg) FROM predictions p
    WHERE p.cycle_id = c.id
      AND p.model_id LIKE '%pre_harvest%'
      AND p.model_id NOT LIKE '%lock%'
      AND p.model_id NOT LIKE '%cohort%'
      AND p.model_id NOT LIKE '%calib%'
      AND p.created_at < TIMESTAMP(c.harvest_start)
    ORDER BY p.created_at DESC, p.id DESC LIMIT 1
  ) AS mid_kg,
  (
    SELECT p.pred_days FROM predictions p
    WHERE p.cycle_id = c.id
      AND p.model_id NOT LIKE '%lock_plant_kg%'
      AND p.model_id NOT LIKE '%cohort%'
      AND p.model_id NOT LIKE '%pre_harvest%'
      AND p.model_id NOT LIKE '%_mid%'
    ORDER BY
      CASE WHEN p.model_id LIKE '%plant_plus_w%' OR p.model_id LIKE '%hgb_plant%' THEN 0 ELSE 1 END,
      p.created_at ASC, p.id ASC
    LIMIT 1
  ) AS plant_days
FROM cycles c
JOIN beds b ON b.id = c.bed_id
WHERE c.harvest_end IS NOT NULL
  AND c.harvest_start IS NOT NULL
  AND c.harvest_end >= ?
  AND c.harvest_end < ?
";
    $stmt = mysqli_prepare($link, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $from, $asof);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $daySame = []; $kgSame = []; $dayAll = []; $kgAll = [];
    while ($row = mysqli_fetch_assoc($res)) {
        if ($row['mid_days'] === null || $row['mid_kg'] === null) {
            continue;
        }
        $actDays = (float)$row['act_days'];
        $actKg = (float)$row['act_kg'];
        $midDays = (float)$row['mid_days'];
        $midKg = (float)$row['mid_kg'];
        if ($actDays <= 0 || $actKg < 5 || $midDays <= 0 || $midKg <= 0) {
            continue;
        }
        $kr = $actKg / $midKg;
        $kgAll[] = $kr;
        if ($groupType !== '' && (string)$row['group_type'] === $groupType) {
            $kgSame[] = $kr;
        }
        // 意図在庫は日数証拠に混ぜない
        $pdPlant = $row['plant_days'] !== null ? (float)$row['plant_days'] : $midDays;
        $plantExp = date(
            'Y-m-d',
            strtotime((string)$row['plant_date'] . ' +' . (int)round($pdPlant) . ' day')
        );
        if (supply_is_intentional_hold($plantExp, (string)$row['harvest_start'], (string)$row['harvest_end'])) {
            continue;
        }
        $de = $actDays - $midDays;
        $dayAll[] = $de;
        if ($groupType !== '' && (string)$row['group_type'] === $groupType) {
            $daySame[] = $de;
        }
    }
    mysqli_stmt_close($stmt);
    $dayErrs = count($daySame) >= GF_PLANT_CALIB_MIN_N ? $daySame : $dayAll;
    $kgRatios = count($kgSame) >= GF_PLANT_CALIB_MIN_N ? $kgSame : $kgAll;
    if (count($dayErrs) < GF_PLANT_CALIB_MIN_N || count($kgRatios) < GF_PLANT_CALIB_MIN_N) {
        $cache[$key] = null;
        return null;
    }
    $dayOffset = gf_plant_calib_median($dayErrs);
    $kgRatio = gf_plant_calib_median($kgRatios);
    if ($dayOffset === null || $kgRatio === null) {
        $cache[$key] = null;
        return null;
    }
    if (abs($dayOffset) > GF_PLANT_CALIB_DAY_ABS_MAX) {
        $dayOffset = $dayOffset > 0 ? GF_PLANT_CALIB_DAY_ABS_MAX : -GF_PLANT_CALIB_DAY_ABS_MAX;
    }
    $kgRatio = max(GF_PLANT_CALIB_KG_RATIO_MIN, min(GF_PLANT_CALIB_KG_RATIO_MAX, $kgRatio));
    $cache[$key] = [
        'day_offset' => round($dayOffset, 2),
        'kg_ratio' => round($kgRatio, 4),
        'n' => count($dayErrs),
    ];
    return $cache[$key];
}

/**
 * @param array{days:float,yield:float,model_id:string} $pred
 * @return array{days:float,yield:float,model_id:string,calib?:array}
 */
function gf_mid_apply_calib(mysqli $link, int $cycleId, array $pred): array
{
    $modelId = (string)($pred['model_id'] ?? '');
    if (strpos($modelId, 'calib') !== false) {
        return $pred;
    }
    $st = mysqli_prepare(
        $link,
        "SELECT b.group_type FROM cycles c JOIN beds b ON b.id = c.bed_id WHERE c.id = ? LIMIT 1"
    );
    mysqli_stmt_bind_param($st, 'i', $cycleId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    mysqli_stmt_close($st);
    $group = $row ? (string)$row['group_type'] : '';
    $hit = gf_mid_calib_current($link, $group);
    if ($hit === null) {
        return $pred;
    }
    $days = max(1.0, (float)$pred['days'] + (float)$hit['day_offset']);
    $yield = max(0.0, (float)$pred['yield'] * (float)$hit['kg_ratio']);
    $pred['days'] = round($days, 3);
    $pred['yield'] = round($yield, 3);
    $pred['model_id'] = $modelId . '_calib';
    $pred['calib'] = $hit;
    return $pred;
}
