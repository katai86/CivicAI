<?php
/**
 * City Intelligence – anomalies list.
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
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
$eng = new CityAnomalyEngine();
json_response(['ok' => true, 'data' => ['anomalies' => $eng->listRecent($aid, $limit)]]);
