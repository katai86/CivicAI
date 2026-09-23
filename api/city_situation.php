<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/cityintel/CitySituationEngine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}
$scope = city_intel_require_gov();
$aid = city_intel_primary_authority($scope);
if (!$aid) {
    json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
}
json_response(['ok' => true, 'data' => (new CitySituationEngine())->build($aid)]);
