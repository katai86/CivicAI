<?php
/**
 * City Intelligence scheduled sync – HTTP cron.
 * ?token=ADMIN_TOKEN&authority_id=15 (optional)
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/cityintel/CityIntelligenceOrchestrator.php';

if (defined('ADMIN_TOKEN') && ADMIN_TOKEN !== '') {
    $token = $_GET['token'] ?? '';
    if (!hash_equals((string)ADMIN_TOKEN, (string)$token)) {
        json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
}

$aid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : null;
$forceOsm = isset($_GET['force_osm']) && $_GET['force_osm'] === '1';
$orch = new CityIntelligenceOrchestrator();

if ($aid && $aid > 0) {
    json_response($orch->run($aid, $forceOsm));
}

// Sync all active authorities with bbox
$results = [];
try {
    $rows = db()->query('SELECT id FROM authorities WHERE is_active = 1 AND min_lat IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {
    $rows = [];
}
foreach ($rows as $id) {
    $results[] = $orch->run((int)$id, $forceOsm);
}
json_response(['ok' => true, 'authorities' => count($results), 'results' => $results]);
