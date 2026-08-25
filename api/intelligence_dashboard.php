<?php
/**
 * Intelligence Platform dashboard – klímaindex, modulstátusz, ajánlások (M2).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/ClimateIndexService.php';
require_once __DIR__ . '/../services/IntelligenceModuleRegistry.php';
require_once __DIR__ . '/../services/IntelligenceHub.php';
require_once __DIR__ . '/../services/ExternalDataCache.php';

require_gov_or_admin();
preload_module_settings();

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

$cacheKey = 'dash_' . (int)($aid ?? 0);
$hit = ExternalDataCache::getValid('intel_dashboard', $cacheKey);
if ($hit && !empty($hit['payload']) && is_array($hit['payload'])) {
    $payload = $hit['payload'];
    $payload['cached'] = true;
    json_response(['ok' => true, 'data' => $payload]);
}

if (function_exists('set_time_limit')) {
    @set_time_limit(90);
}

try {
    // Teljes ClimateIndex (modul merge) – 15 perc cache védi a demót a lassú újrahívástól
    $climate = (new ClimateIndexService())->compute($aid, false);
    $climate['mode'] = 'full';
} catch (Throwable $e) {
    if (function_exists('log_error')) {
        log_error('intelligence_dashboard: ' . $e->getMessage());
    }
    try {
        $climate = (new ClimateIndexService())->compute($aid, true);
        $climate['mode'] = 'fast_fallback';
    } catch (Throwable $e2) {
        $climate = ['score' => 0, 'category' => 'moderate', 'label' => '—', 'recommendations' => [], 'components' => [], 'active_modules' => 0, 'error_modules' => 0, 'mode' => 'error'];
    }
}
$modules = IntelligenceModuleRegistry::listWithStatus();

$contextLite = null;
$ctxKey = 'ctx_' . (int)($aid ?? 0) . '_lite';
$ctxHit = ExternalDataCache::getValid('intel_context', $ctxKey);
if ($ctxHit && !empty($ctxHit['payload']) && is_array($ctxHit['payload'])) {
    $contextLite = $ctxHit['payload'];
} else {
    try {
        $contextLite = (new IntelligenceHub())->fetchFullContext($aid, true);
        ExternalDataCache::set('intel_context', $ctxKey, $contextLite, 15, 'ok', null);
    } catch (Throwable $e) {
        if (function_exists('log_error')) {
            log_error('intelligence_dashboard context_lite: ' . $e->getMessage());
        }
    }
}

$payload = [
    'climate_index' => $climate,
    'modules' => $modules,
    'context_lite' => $contextLite,
    'authority_id' => $aid,
    'cached' => false,
];
ExternalDataCache::set('intel_dashboard', $cacheKey, $payload, 15, 'ok', null);

json_response([
    'ok' => true,
    'data' => $payload,
]);
