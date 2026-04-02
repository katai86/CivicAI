<?php
/**
 * EU / CDS–ERA5 klíma kontextus (Open-Meteo ERA5 archive, napi összesítők).
 * GET, csak gov/admin. Hatóság bbox közepe, elmúlt 30 nap (tegnapig).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/CdsEra5ClimateService.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$role = current_user_role();
$uid = current_user_id() ? (int)current_user_id() : 0;
$authorityId = null;

if (in_array($role, ['admin', 'superadmin'], true)) {
    $aid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
    $authorityId = $aid > 0 ? $aid : null;
} else {
    if ($uid > 0) {
        try {
            $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = ? ORDER BY authority_id LIMIT 1");
            $stmt->execute([$uid]);
            $row = $stmt->fetch(PDO::FETCH_COLUMN);
            $authorityId = $row !== false ? (int)$row : null;
        } catch (Throwable $e) {}
    }
}

$bbox = null;
if ($authorityId !== null && $authorityId > 0) {
    try {
        $st = db()->prepare('SELECT min_lat, max_lat, min_lng, max_lng FROM authorities WHERE id = ? LIMIT 1');
        $st->execute([$authorityId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['min_lat'] !== null && $row['max_lat'] !== null && $row['min_lng'] !== null && $row['max_lng'] !== null) {
            $bbox = [
                'min_lat' => (float)$row['min_lat'],
                'max_lat' => (float)$row['max_lat'],
                'min_lng' => (float)$row['min_lng'],
                'max_lng' => (float)$row['max_lng'],
            ];
        }
    } catch (Throwable $e) {}
}

if (!$bbox) {
    json_response(['ok' => false, 'error' => 'authority_bbox_required']);
}

try {
    $svc = new CdsEra5ClimateService();
    $data = $svc->fetchForBBox($bbox);
    $sources = $data['ok'] ? ['era5_open_meteo_archive'] : [];
    json_response([
        'ok' => $data['ok'],
        'source' => 'cds_era5',
        'scope' => [
            'authority_id' => $authorityId,
            'bbox' => $bbox,
            'reference_period' => ($data['period_start'] ?? '') . '..' . ($data['period_end'] ?? ''),
        ],
        'data' => $data,
        'meta' => [
            'fetched_at' => gmdate('c'),
            'cached' => (bool)($data['cached'] ?? false),
            'confidence' => $data['ok'] ? 'medium' : 'low',
            'notes' => $data['notes'] ?? [],
            'data_sources' => $sources,
        ],
    ]);
} catch (Throwable $e) {
    if (function_exists('log_error')) {
        log_error('eu_climate_context: ' . $e->getMessage());
    }
    json_response(['ok' => false, 'error' => t('common.error_load')]);
}
