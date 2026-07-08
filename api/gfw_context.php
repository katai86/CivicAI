<?php
/**
 * Global Forest Watch kontextus (gov/admin) – Klíma modul.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/GlobalForestWatchService.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$bbox = null;
$aid = gov_primary_authority_id();
if ($aid) {
    try {
        $st = db()->prepare('SELECT min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ? LIMIT 1');
        $st->execute([$aid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['min_lat'] !== null && $row['max_lat'] !== null) {
            $bbox = [
                'min_lat' => (float)$row['min_lat'],
                'max_lat' => (float)$row['max_lat'],
                'min_lng' => (float)$row['min_lng'],
                'max_lng' => (float)$row['max_lng'],
            ];
        }
    } catch (Throwable $e) {
    }
}

$svc = new GlobalForestWatchService();
$data = $svc->fetchContext($bbox);

json_response([
    'ok' => !empty($data['ok']),
    'data' => $data,
    'meta' => ['authority_id' => $aid, 'bbox' => $bbox],
]);
