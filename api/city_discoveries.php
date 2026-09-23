<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/cityintel/CityDiscoveryEngine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}
$scope = city_intel_require_gov();
$aid = city_intel_primary_authority($scope);
if (!$aid) {
    json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
}
$regen = isset($_GET['regenerate']) && $_GET['regenerate'] === '1';
$engine = new CityDiscoveryEngine();
$items = $regen ? $engine->generate($aid) : $engine->generate($aid);
json_response(['ok' => true, 'authority_id' => $aid, 'discoveries' => $items]);
