<?php
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['cycle_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'cycle_id required']);
    exit;
}

$cycleId = (int)$input['cycle_id'];
$cmd = 'python3 ' . escapeshellarg(__DIR__ . '/predict_model.py') . ' --cycle_id ' . escapeshellarg($cycleId);

if (!empty($input['apply_partial'])) {
    if (!isset($input['partial_yield']) || !isset($input['partial_ratio'])) {
        echo json_encode(['status' => 'error', 'message' => 'partial_yield and partial_ratio required when apply_partial=true']);
        exit;
    }
    $cmd .= ' --apply_partial --partial_yield ' . escapeshellarg($input['partial_yield']) . ' --partial_ratio ' . escapeshellarg($input['partial_ratio']);
}

exec($cmd, $output, $ret);
$response = json_decode(implode("\n", $output), true);
if (!$response) {
    echo json_encode(['status' => 'error', 'message' => 'Prediction failed']);
    exit;
}

echo json_encode($response);
