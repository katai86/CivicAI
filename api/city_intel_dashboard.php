<?php
/**
 * City Intelligence – dashboard aggregate + optional sync trigger (?sync=1).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/cityintel/CityIntelligenceOrchestrator.php';

$scope = city_intel_require_gov();
$aid = city_intel_primary_authority($scope);
$orch = new CityIntelligenceOrchestrator();

if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['sync']) && $_GET['sync'] === '1')) {
    $forceOsm = isset($_GET['force_osm']) && $_GET['force_osm'] === '1';
    // Admin sync without authority_id → all scoped authorities (matches admin UI label).
    if (!empty($scope['isAdmin']) && (int)($scope['requestedAid'] ?? 0) <= 0 && count($scope['authorityIds'] ?? []) > 1) {
        $runs = [];
        foreach ($scope['authorityIds'] as $id) {
            $id = (int)$id;
            if ($id <= 0) {
                continue;
            }
            $runs[] = $orch->run($id, $forceOsm);
        }
        $dashAid = $aid ?: ((int)($scope['authorityIds'][0] ?? 0) ?: null);
        json_response([
            'ok' => true,
            'data' => [
                'sync' => ['ok' => true, 'authorities' => count($runs), 'results' => $runs],
                'dashboard' => $dashAid ? $orch->dashboard($dashAid) : null,
            ],
        ]);
    }
    $run = $orch->run($aid, $forceOsm);
    json_response(['ok' => !empty($run['ok']), 'data' => ['sync' => $run, 'dashboard' => $orch->dashboard($aid)]]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

json_response(['ok' => true, 'data' => $orch->dashboard($aid)]);
