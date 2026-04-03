<?php
/**
 * Milestone 1 – Executive summary for Gov dashboard (aggregates existing services).
 * GET ?authority_id= (admin only) – same semantics as city_health / predictions.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/ExecutiveSummaryService.php';

require_gov_or_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$role = current_user_role() ?: '';
$uid = current_user_id() ? (int)current_user_id() : 0;

$adminRequested = null;
if (in_array($role, ['admin', 'superadmin'], true)) {
  $aid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
  $adminRequested = $aid > 0 ? $aid : null;
}

$cacheKey = gov_api_cache_scope_key('exec', $role, $uid, $adminRequested);
$cacheHit = gov_api_cache_get($cacheKey);
if ($cacheHit !== null) {
  header('X-Gov-Api-Cache: HIT');
  json_response($cacheHit);
}

try {
  $svc = new ExecutiveSummaryService();
  $data = $svc->build($role, $uid, $adminRequested);
  $out = ['ok' => true, 'data' => $data];
  gov_api_cache_set($cacheKey, $out);
  header('X-Gov-Api-Cache: MISS');
  json_response($out);
} catch (Throwable $e) {
  if (function_exists('log_error')) {
    log_error('executive_summary: ' . $e->getMessage());
  }
  json_response(['ok' => false, 'error' => t('common.error_load')], 500);
}
