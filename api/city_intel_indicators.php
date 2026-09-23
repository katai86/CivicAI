<?php
/**
 * City Intelligence – indicators (+ optional history).
 * GET: authority_id, indicator_key? (history), limit?
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/cityintel/CityIndicatorEngines.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
$scope = city_intel_require_gov();
$aid = city_intel_primary_authority($scope);
$eng = new CityIndicatorEngine();
$base = new CityBaselineEngine();
$trend = new CityTrendEngine();
$key = isset($_GET['indicator_key']) ? trim((string)$_GET['indicator_key']) : '';
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

if ($key !== '') {
    $hist = $eng->history($aid, $key, $limit);
    $tr = $trend->compute($aid, $key, 8);
    $bl = $base->get($aid, $key, 90);
    json_response([
        'ok' => true,
        'data' => [
            'indicator_key' => $key,
            'history' => $hist,
            'trend' => $tr,
            'baseline' => $bl,
        ],
    ]);
}

$latest = $eng->latest($aid, $limit);
$out = [];
foreach ($latest as $row) {
    $k = (string)$row['indicator_key'];
    $row['trend'] = $trend->compute($aid, $k, 8);
    $row['baseline'] = $base->get($aid, $k, 90);
    $out[] = $row;
}
json_response(['ok' => true, 'data' => ['authority_id' => $aid, 'indicators' => $out]]);
