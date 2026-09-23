<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/intelligence/CivicEvidenceLinkStore.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}
city_intel_require_gov();
$rootType = trim((string)($_GET['root_type'] ?? ''));
$rootId = (int)($_GET['root_id'] ?? 0);
if ($rootType === '' || $rootId <= 0) {
    json_response(['ok' => false, 'error' => t('api.missing_fields')], 400);
}
$depth = min(12, max(1, (int)($_GET['depth'] ?? 8)));
$store = new CivicEvidenceLinkStore();
json_response(['ok' => true, 'chain' => $store->chain($rootType, $rootId, $depth)]);
