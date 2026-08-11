<?php
/**
 * Local Ridge + baseline inference (特徴量仕様_v2).
 * - ridge_serve.json     … 定植時（plant_plus_w 学習）
 * - ridge_serve_mid.json … 栽培中再予測（pre_harvest 学習）
 */

function ridge_serve_path(string $variant = 'plant'): string
{
    $name = ($variant === 'mid') ? 'ridge_serve_mid.json' : 'ridge_serve.json';
    $p = __DIR__ . '/../models/' . $name;
    if (!is_readable($p) && $variant === 'mid') {
        // mid が無ければ plant にフォールバック
        $p = __DIR__ . '/../models/ridge_serve.json';
    }
    if (!is_readable($p)) {
        $p = __DIR__ . '/../ml/artifacts/' . $name;
    }
    return $p;
}

function load_ridge_serve(string $variant = 'plant'): array
{
    static $cache = [];
    if (isset($cache[$variant])) {
        return $cache[$variant];
    }
    $path = ridge_serve_path($variant);
    if (!is_readable($path)) {
        throw new RuntimeException('ridge serve json not found: ' . $path);
    }
    $json = json_decode(file_get_contents($path), true);
    if (!is_array($json) || empty($json['feature_order'])) {
        throw new RuntimeException('ridge serve json invalid: ' . $path);
    }
    $cache[$variant] = $json;
    return $json;
}

function ridge_residual(array $model, array $x): float
{
    $n = count($x);
    $sum = (float)$model['intercept'];
    for ($i = 0; $i < $n; $i++) {
        $scale = (float)$model['scale'][$i];
        if ($scale == 0.0) {
            $scale = 1.0;
        }
        $z = ($x[$i] - (float)$model['mean'][$i]) / $scale;
        $sum += $z * (float)$model['coef'][$i];
    }
    return $sum;
}

/**
 * @param array $features feature dict from build_features_array
 * @param string $variant 'plant'|'mid'
 * @return array{days:float,yield:float,baseline_days:float,baseline_yield:float,model_id:string}
 */
function predict_ridge_from_features(array $features, string $variant = 'plant'): array
{
    $serve = load_ridge_serve($variant);
    $order = $serve['feature_order'];
    $x = [];
    foreach ($order as $key) {
        if (!array_key_exists($key, $features)) {
            throw new RuntimeException("missing feature: {$key}");
        }
        $x[] = (float)$features[$key];
    }

    $bDays = (float)($features[$serve['baseline_keys']['days']] ?? 0);
    $bYield = (float)($features[$serve['baseline_keys']['yield']] ?? 0);

    $days = $bDays + ridge_residual($serve['days'], $x);
    $yield = $bYield + ridge_residual($serve['yield'], $x);

    $daysMin = (float)($serve['clip']['days_min'] ?? 1);
    $yieldMin = (float)($serve['clip']['yield_min'] ?? 0);
    if ($days < $daysMin) {
        $days = $daysMin;
    }
    if ($yield < $yieldMin) {
        $yield = $yieldMin;
    }

    return [
        'days' => round($days, 3),
        'yield' => round($yield, 3),
        'baseline_days' => $bDays,
        'baseline_yield' => $bYield,
        'model_id' => (string)($serve['model_id'] ?? 'ridge_v2'),
    ];
}

/**
 * ⑤: SUM(kg)/SUM(ratio). Returns null if ratio too small.
 */
function compute_yield_postproc_from_harvests(mysqli $link, int $cycleId, float $minRatio = 0.05): ?float
{
    $stmt = mysqli_prepare(
        $link,
        "SELECT COALESCE(SUM(harvest_kg),0) AS kg_sum,
                COALESCE(SUM(harvest_ratio),0) AS ratio_sum
         FROM harvests WHERE cycle_id = ?"
    );
    mysqli_stmt_bind_param($stmt, 'i', $cycleId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);
    $ratio = (float)($row['ratio_sum'] ?? 0);
    $kg = (float)($row['kg_sum'] ?? 0);
    if ($ratio < $minRatio) {
        return null;
    }
    return round($kg / $ratio, 3);
}

/**
 * Insert prediction row. pred_* from model; postproc_total_kg optional (⑤). Never overwrites prior rows.
 */
function insert_prediction_row(
    mysqli $link,
    int $cycleId,
    array $pred,
    ?float $postprocTotalKg = null
): void {
    $modelId = $pred['model_id'];
    $days = (float)$pred['days'];
    $yield = (float)$pred['yield'];
    if ($postprocTotalKg === null) {
        $stmt = mysqli_prepare(
            $link,
            "INSERT INTO predictions (cycle_id, model_id, pred_days, pred_total_kg, postproc_total_kg)
             VALUES (?, ?, ?, ?, NULL)"
        );
        mysqli_stmt_bind_param($stmt, 'isdd', $cycleId, $modelId, $days, $yield);
    } else {
        $stmt = mysqli_prepare(
            $link,
            "INSERT INTO predictions (cycle_id, model_id, pred_days, pred_total_kg, postproc_total_kg)
             VALUES (?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, 'isddd', $cycleId, $modelId, $days, $yield, $postprocTotalKg);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

/**
 * Rebuild features → predict → optional ⑤ postproc → INSERT predictions.
 *
 * @param string $variant 'plant' (定植=HGB/GBR) | 'mid' (栽培中=Ridge)
 * @return array{features:array,pred:array,postproc_total_kg:?float}
 */
function rebuild_and_predict_cycle(
    mysqli $link,
    int $cycleId,
    bool $withPostproc = true,
    string $variant = 'plant'
): array {
    require_once __DIR__ . '/predict_hgb_plant.php';
    $features = rebuild_features_for_cycle($link, $cycleId);
    if ($variant === 'plant') {
        try {
            $pred = predict_hgb_plant_from_features($features);
        } catch (Throwable $e) {
            // fallback to ridge plant
            $pred = predict_ridge_from_features($features, 'plant');
            $pred['model_id'] = ($pred['model_id'] ?? 'ridge') . '_fallback';
        }
    } else {
        $pred = predict_ridge_from_features($features, 'mid');
    }
    $post = null;
    if ($withPostproc) {
        $post = compute_yield_postproc_from_harvests($link, $cycleId);
    }
    insert_prediction_row($link, $cycleId, $pred, $post);
    return [
        'features' => $features,
        'pred' => $pred,
        'postproc_total_kg' => $post,
    ];
}
