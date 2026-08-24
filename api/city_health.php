<?php
/**
 * M3 – City Health Score API.
 * GET, csak gov/admin. authority_id opcionális (admin: konkrét hatóság vagy összesített; gov: saját első hatóság).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../services/CityHealthScore.php';

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

$adminRequested = null;
if (in_array($role, ['admin', 'superadmin'], true)) {
  $adminRequested = $authorityId;
}

$cacheKey = gov_api_cache_scope_key('city_health', $role ?: '', $uid, $adminRequested);
$cacheHit = gov_api_cache_get($cacheKey);
if ($cacheHit !== null) {
  header('X-Gov-Api-Cache: HIT');
  json_response($cacheHit);
}

try {
  $service = new CityHealthScore();
  $data = $service->compute($authorityId);
  $out = ['ok' => true, 'data' => $data];
  gov_api_cache_set($cacheKey, $out);
  header('X-Gov-Api-Cache: MISS');
  json_response($out);
} catch (Throwable $e) {
  if (function_exists('log_error')) {
    log_error('city_health: ' . $e->getMessage());
  }
  json_response(['ok' => false, 'error' => t('common.error_load')]);
}
