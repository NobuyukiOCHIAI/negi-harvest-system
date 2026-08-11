<?php
/**
 * Plant-time boosting serve (GBR trees JSON ≈ HGB accuracy).
 * File: models/hgb_plant_serve.json
 */

function hgb_plant_serve_path(): string
{
    $p = __DIR__ . '/../models/hgb_plant_serve.json';
    if (!is_readable($p)) {
        $p = __DIR__ . '/../ml/artifacts/hgb_plant_serve.json';
    }
    return $p;
}

function load_hgb_plant_serve(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $path = hgb_plant_serve_path();
    if (!is_readable($path)) {
        throw new RuntimeException('hgb_plant_serve.json not found');
    }
    $json = json_decode(file_get_contents($path), true);
    if (!is_array($json) || empty($json['feature_order']) || empty($json['yield']['trees'])) {
        throw new RuntimeException('hgb_plant_serve.json invalid');
    }
    $cache = $json;
    return $json;
}

function gbr_tree_predict(array $tree, array $x): float
{
    $nodes = $tree['nodes'];
    $i = 0;
    while (true) {
        $n = $nodes[$i];
        if ((int)$n['left'] < 0) {
            return (float)$n['value'];
        }
        $f = (int)$n['feature'];
        if ($x[$f] <= (float)$n['threshold']) {
            $i = (int)$n['left'];
        } else {
            $i = (int)$n['right'];
        }
    }
}

function gbr_ensemble_predict(array $model, array $x): float
{
    $s = (float)$model['init_score'];
    $lr = (float)$model['learning_rate'];
    foreach ($model['trees'] as $tree) {
        $s += $lr * gbr_tree_predict($tree, $x);
    }
    return $s;
}

/**
 * @return array{days:float,yield:float,baseline_days:float,baseline_yield:float,model_id:string}
 */
function predict_hgb_plant_from_features(array $features): array
{
    $serve = load_hgb_plant_serve();
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

    $days = $bDays + gbr_ensemble_predict($serve['days'], $x);
    $yield = $bYield + gbr_ensemble_predict($serve['yield'], $x);

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
        'model_id' => (string)($serve['model_id'] ?? 'hgb_plant'),
    ];
}
