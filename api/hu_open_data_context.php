<?php
/**
 * Magyar nyílt adatok – KSH / kozadatportal kontextus (gov/admin).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/HuOpenDataService.php';

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
} elseif ($uid > 0) {
    try {
        $stmt = db()->prepare('SELECT authority_id FROM authority_users WHERE user_id = ? ORDER BY authority_id LIMIT 1');
        $stmt->execute([$uid]);
        $row = $stmt->fetch(PDO::FETCH_COLUMN);
        $authorityId = $row !== false ? (int)$row : null;
    } catch (Throwable $e) {
    }
}

try {
    $svc = new HuOpenDataService();
    $data = $svc->fetchContext($authorityId, db());
    $sources = [];
    if (!empty($data['green'])) {
        $sources[] = 'ksh_kor0011_municipal_green';
    }
    if (!empty($data['forestry'])) {
        $sources[] = 'ksh_kor0004_forestry';
    }
    if (!empty($data['weather_national']) || !empty($data['weather_city'])) {
        $sources[] = 'ksh_weather_stadat';
    }
    if (!empty($data['weather_city'])) {
        $sources[] = 'kozadatportal_ckan';
    }

    json_response([
        'ok' => (bool)($data['ok'] ?? false),
        'source' => 'hu_ksh',
        'scope' => [
            'authority_id' => $authorityId,
        ],
        'data' => $data,
        'meta' => [
            'fetched_at' => gmdate('c'),
            'cached' => (bool)($data['cached'] ?? false),
            'confidence' => ($data['ok'] ?? false) ? 'medium' : 'low',
            'notes' => $data['notes'] ?? [],
            'data_sources' => $sources,
        ],
    ]);
} catch (Throwable $e) {
    if (function_exists('log_error')) {
        log_error('hu_open_data_context: ' . $e->getMessage());
    }
    json_response(['ok' => false, 'error' => t('common.error_load')]);
}
