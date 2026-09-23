<?php
/**
 * City Intelligence – source registry status.
 * GET: authority_id optional. Response: ok, sources[], freshness{}
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/cityintel/CityDataSourceRegistry.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
city_intel_require_gov();
$reg = new CityDataSourceRegistry();
json_response([
    'ok' => true,
    'data' => [
        'sources' => $reg->listAll(false),
        'freshness' => $reg->freshnessSummary(),
    ],
]);
