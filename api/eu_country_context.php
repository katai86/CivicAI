<?php
/**
 * EU / Eurostat country context.
 * GET, csak gov/admin. authority country -> Eurostat geo.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/EurostatService.php';

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

try {
    $svc = new EurostatService();
    $data = $svc->fetchCountryContext($authorityId, db());
    $sources = $data['ok'] ? ['eurostat_api_dissemination'] : [];
    json_response([
        'ok' => (bool)$data['ok'],
        'source' => 'eurostat',
        'scope' => [
            'authority_id' => $authorityId,
            'geo' => $data['geo'] ?? null,
            'reference_period' => $data['year'] ?? null,
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
        log_error('eu_country_context: ' . $e->getMessage());
    }
    json_response(['ok' => false, 'error' => t('common.error_load')]);
}

