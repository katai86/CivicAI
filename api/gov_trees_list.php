<?php
/**
 * Gov/Admin: fák listája szerkesztéshez (hatóság scope: authority_id + bbox/város).
 * GET: limit, offset, authority_id (admin). Jog: admin, superadmin, govuser.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

start_secure_session();
$uid = current_user_id();
$role = current_user_role() ?: '';
if ($uid <= 0 || !in_array($role, ['admin', 'superadmin', 'govuser'], true)) {
  json_response(['ok' => false, 'error' => t('api.unauthorized')], 401);
}

$limit = (int)($_GET['limit'] ?? 100);
$offset = (int)($_GET['offset'] ?? 0);
if ($limit < 1 || $limit > 500) {
  $limit = 100;
}
if ($offset < 0) {
  $offset = 0;
}

$scopeIds = gov_tree_list_scope_authority_ids();
$rows = [];
$total = 0;

try {
  $pdo = db();
  [$scopeWhere, $scopeParams] = gov_trees_scope_where_sql($pdo, $scopeIds, 't');

  $countSql = "SELECT COUNT(*) FROM trees t WHERE $scopeWhere";
  $stmt = $pdo->prepare($countSql);
  $stmt->execute($scopeParams);
  $total = (int) $stmt->fetchColumn();

  $sql = "
    SELECT t.id, t.lat, t.lng, t.address, t.species, t.estimated_age, t.planting_year,
           t.trunk_diameter, t.canopy_diameter, t.health_status, t.risk_level,
           t.last_inspection, t.last_watered, t.adopted_by_user_id, t.public_visible, t.gov_validated,
           t.notes, t.created_at, t.updated_at,
           u.display_name AS adopter_name
    FROM trees t
    LEFT JOIN users u ON u.id = t.adopted_by_user_id
    WHERE $scopeWhere
    ORDER BY t.id DESC
    LIMIT " . (int) $limit . ' OFFSET ' . (int) $offset;
  $stmt = $pdo->prepare($sql);
  $stmt->execute($scopeParams);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  if (function_exists('log_error')) {
    log_error('gov_trees_list: ' . $e->getMessage());
  }
  $rows = [];
  $total = 0;
}

json_response(['ok' => true, 'data' => $rows, 'total' => $total]);
