<?php
/**
 * City Intelligence – insights list + detail (?id=).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/city_intel_bootstrap.php';
require_once __DIR__ . '/../services/cityintel/CityInsightEngine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}
$scope = city_intel_require_gov();
$aid = city_intel_primary_authority($scope);
$eng = new CityInsightEngine();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    $row = $eng->getById($id);
    if (!$row) {
        json_response(['ok' => false, 'error' => 'not_found'], 404);
    }
    if (!$scope['isAdmin'] && $aid && (int)($row['authority_id'] ?? 0) !== $aid) {
        json_response(['ok' => false, 'error' => 'Forbidden'], 403);
    }
    $evidence = json_decode((string)($row['evidence_json'] ?? '{}'), true);
    $cross = json_decode((string)($row['cross_signals_json'] ?? '{}'), true);
    json_response([
        'ok' => true,
        'data' => [
            'insight' => $row,
            'evidence_chain' => is_array($evidence) ? $evidence : [],
            'cross_signals' => is_array($cross) ? $cross : [],
            'layers' => [
                'measured_fact' => (string)($row['fact_text'] ?? ''),
                'ai_interpretation' => (string)($row['interpretation_text'] ?? ''),
            ],
        ],
    ]);
}
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
json_response(['ok' => true, 'data' => ['insights' => $eng->listActive($aid, $limit)]]);
