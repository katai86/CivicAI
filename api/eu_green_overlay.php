<?php
/**
 * EU / Copernicus zöld réteg – GeoJSON pontok (heatmap-súly), gov/admin.
 * GET: layer_type=ndvi|green_deficit|planting_priority|vegetation_health, authority_id?, min_lat?, max_lat?, min_lng?, max_lng?
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/GreenIntelligence.php';
require_once __DIR__ . '/../services/CopernicusDataService.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
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
            $stmt = db()->prepare('SELECT authority_id FROM authority_users WHERE user_id = ? ORDER BY authority_id LIMIT 1');
            $stmt->execute([$uid]);
            $row = $stmt->fetch(PDO::FETCH_COLUMN);
            $authorityId = $row !== false ? (int)$row : null;
        } catch (Throwable $e) {}
    }
}

$layerType = preg_replace('/[^a-z_]/', '', strtolower((string)($_GET['layer_type'] ?? 'planting_priority')));
$allowed = ['ndvi', 'green_deficit', 'planting_priority', 'vegetation_health'];
if (!in_array($layerType, $allowed, true)) {
    json_response(['ok' => false, 'error' => 'invalid layer_type'], 400);
}

$bbox = null;
if (isset($_GET['min_lat'], $_GET['max_lat'], $_GET['min_lng'], $_GET['max_lng'])) {
    $bbox = [
        'min_lat' => (float)$_GET['min_lat'],
        'max_lat' => (float)$_GET['max_lat'],
        'min_lng' => (float)$_GET['min_lng'],
        'max_lng' => (float)$_GET['max_lng'],
    ];
} elseif ($authorityId !== null && $authorityId > 0) {
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

if (!function_exists('eu_open_data_module_enabled') || !eu_open_data_module_enabled()) {
    json_response([
        'ok' => true,
        'source' => 'copernicus',
        'scope' => ['authority_id' => $authorityId, 'bbox' => $bbox, 'reference_period' => gmdate('Y-m')],
        'data' => ['type' => 'FeatureCollection', 'features' => []],
        'meta' => [
            'fetched_at' => gmdate('c'),
            'cached' => false,
            'confidence' => 'low',
            'notes' => ['eu_module_disabled'],
        ],
    ]);
}

$cop = new CopernicusDataService();
if (!$cop->isActive()) {
    json_response([
        'ok' => true,
        'source' => 'copernicus',
        'scope' => ['authority_id' => $authorityId, 'bbox' => $bbox, 'reference_period' => gmdate('Y-m')],
        'data' => ['type' => 'FeatureCollection', 'features' => []],
        'meta' => [
            'fetched_at' => gmdate('c'),
            'cached' => false,
            'confidence' => 'low',
            'notes' => ['copernicus_submodule_off'],
        ],
    ]);
}

try {
    $gi = new GreenIntelligence();
    $metrics = $gi->compute($authorityId);
    if (!empty($metrics['eu_notes'])) {
        unset($metrics['eu_notes']);
    }
    $geo = $cop->buildOverlayGeoJson($layerType, $authorityId, $bbox, $metrics);
    json_response([
        'ok' => true,
        'source' => 'copernicus',
        'scope' => [
            'authority_id' => $authorityId,
            'bbox' => $bbox,
            'reference_period' => gmdate('Y-m'),
        ],
        'data' => $geo,
        'meta' => [
            'fetched_at' => gmdate('c'),
            'cached' => false,
            'confidence' => 'medium',
            'notes' => ['imported_context_and_local_grid'],
        ],
    ]);
} catch (Throwable $e) {
    if (function_exists('log_error')) {
        log_error('eu_green_overlay: ' . $e->getMessage());
    }
    json_response(['ok' => false, 'error' => t('common.error_load')]);
}
