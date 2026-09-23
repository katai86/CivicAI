<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/cityintel/CityChangeIntelligence.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}
$scope = city_intel_require_gov();
$aid = city_intel_primary_authority($scope);
if (!$aid) {
    json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
}
$snapshot = isset($_GET['snapshot']) && $_GET['snapshot'] === '1';
$data = (new CityChangeIntelligence())->snapshot($aid);
json_response(['ok' => !empty($data['ok']), 'authority_id' => $aid, 'data' => $data]);
