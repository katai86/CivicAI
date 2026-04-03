<?php
/**
 * GET – prioritised open backlog by category and zone (gov/admin scope).
 * Query: authority_id optional for admin (same as other gov APIs).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/ExecutiveSummaryService.php';
require_once __DIR__ . '/../services/PrioritizationEngine.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$role = current_user_role() ?: '';
$uid = current_user_id() ? (int)current_user_id() : 0;
$adminAid = null;
if (in_array($role, ['admin', 'superadmin'], true)) {
  $a = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  $adminAid = $a > 0 ? $a : null;
}

$pdo = db();
[$reportWhere, $reportParams, $_treeScopeIds, $healthAuthorityId] = ExecutiveSummaryService::resolveScopes($pdo, $role, $uid, $adminAid);

$engine = new PrioritizationEngine();
$data = $engine->compute($pdo, $reportWhere, $reportParams, $healthAuthorityId);

json_response([
  'ok' => true,
  'data' => $data,
]);
