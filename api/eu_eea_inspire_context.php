<?php
/**
 * EU / EEA kiemelések (RSS) + INSPIRE portál linkek (bbox középpont opcionális).
 * GET, csak gov/admin.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/EeaInspireContextService.php';

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

try {
    $svc = new EeaInspireContextService();
    $data = $svc->fetch($bbox);
    $sources = [];
    if (!empty($data['eea_highlights'])) {
        $sources[] = 'eea_featured_articles_rss';
    }
    if (!empty($data['inspire'])) {
        $sources[] = 'inspire_geoportal_static';
    }
    json_response([
        'ok' => (bool)$data['ok'],
        'source' => 'eea_inspire',
        'scope' => [
            'authority_id' => $authorityId,
            'bbox' => $bbox,
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
        log_error('eu_eea_inspire_context: ' . $e->getMessage());
    }
    json_response(['ok' => false, 'error' => t('common.error_load')]);
}
