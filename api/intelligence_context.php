<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/IntelligenceHub.php';
require_once __DIR__ . '/../services/ExternalDataCache.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$aid = gov_primary_authority_id();
if (isset($_GET['authority_id']) && (int)$_GET['authority_id'] > 0) {
    $req = (int)$_GET['authority_id'];
    $role = current_user_role() ?: '';
    if (in_array($role, ['admin', 'superadmin'], true)) {
        $aid = $req;
    } else {
        $scope = gov_resolve_report_scope(db(), 'r', $req);
        if (!empty($scope['authority_ids']) && in_array($req, $scope['authority_ids'], true)) {
            $aid = $req;
        }
    }
}

$lite = isset($_GET['lite']) && (string)$_GET['lite'] !== '' && (string)$_GET['lite'] !== '0';

$cacheKey = 'ctx_' . (int)($aid ?? 0) . '_' . ($lite ? 'lite' : 'full');
if ($lite) {
    $hit = ExternalDataCache::getValid('intel_context', $cacheKey);
    if ($hit && !empty($hit['payload']) && is_array($hit['payload'])) {
        $payload = $hit['payload'];
        $payload['cached'] = true;
        json_response(['ok' => true, 'data' => $payload]);
    }
}

$hub = new IntelligenceHub();
try {
    $data = $hub->fetchFullContext($aid, $lite);
    if ($lite) {
        ExternalDataCache::set('intel_context', $cacheKey, $data, 15, 'ok', null);
    }
    json_response(['ok' => true, 'data' => $data]);
} catch (Throwable $e) {
    if (function_exists('log_error')) {
        log_error('intelligence_context: ' . $e->getMessage());
    }
    json_response(['ok' => false, 'error' => 'context_unavailable'], 500);
}
