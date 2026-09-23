<?php
/**
 * City Intelligence – spatial grid + zone hotspots.
 * GET: authority_id?, grid_div?, days?
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/cityintel/CitySpatialEngine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
$scope = city_intel_require_gov();
$aid = city_intel_primary_authority($scope);
$div = isset($_GET['grid_div']) ? (int)$_GET['grid_div'] : 3;
$days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
$eng = new CitySpatialEngine();
json_response(['ok' => true, 'data' => $eng->analyze($aid, $div, $days)]);
