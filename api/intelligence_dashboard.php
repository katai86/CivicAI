<?php
/**
 * Intelligence Platform dashboard – klímaindex, modulstátusz, ajánlások (M2).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/ClimateIndexService.php';
require_once __DIR__ . '/../services/IntelligenceModuleRegistry.php';

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

$climate = (new ClimateIndexService())->compute($aid);
$modules = IntelligenceModuleRegistry::listWithStatus();

json_response([
    'ok' => true,
    'data' => [
        'climate_index' => $climate,
        'modules' => $modules,
        'authority_id' => $aid,
    ],
]);
