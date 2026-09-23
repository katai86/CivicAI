<?php
/**
 * City Intelligence – recent observations.
 * GET: authority_id, source_key?, indicator_type?, limit?
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/cityintel/CityObservationStore.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
$scope = city_intel_require_gov();
$aid = city_intel_primary_authority($scope);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$source = isset($_GET['source_key']) ? trim((string)$_GET['source_key']) : null;
$ind = isset($_GET['indicator_type']) ? trim((string)$_GET['indicator_type']) : null;
$store = new CityObservationStore();
json_response([
    'ok' => true,
    'data' => [
        'authority_id' => $aid,
        'observations' => $store->listRecent($aid, $limit, $source !== '' ? $source : null, $ind !== '' ? $ind : null),
    ],
]);
