<?php

require_once '../db.php';                 // $link (mysqli)
require_once '../lib/build_features.php'; // rebuild_features_for_cycle()

$cycleId = $_GET['cid'];
$features = rebuild_features_for_cycle($link, $cycleId);

// 目視確認：営業調整日数が入っているか
echo "SALES_ADJUST_DAYS = " . ($features['営業調整日数'] ?? 'N/A') . PHP_EOL;

?>