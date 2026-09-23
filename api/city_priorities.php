<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/cityintel/UnifiedPriorityEngine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}
$scope = city_intel_require_gov();
$aid = city_intel_primary_authority($scope);
if (!$aid) {
    json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
}
$limit = min(100, max(5, (int)($_GET['limit'] ?? 30)));
$items = (new UnifiedPriorityEngine())->computeForAuthority($aid, $limit);
json_response(['ok' => true, 'authority_id' => $aid, 'priorities' => $items]);
