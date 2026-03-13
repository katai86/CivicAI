<?php
/**
 * M4 Participatory Budgeting – projektek listája (nyilvános).
 * GET: authority_id (opcionális), status (opcionális; üres = csak published), limit.
 * Válasz: data[] (id, title, description, budget, status, authority_id, vote_count, voted_by_me).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$authorityId = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : null;
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : 'published';
$allowedStatuses = ['draft', 'published', 'closed'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
  $statusFilter = 'published';
}
$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 1 || $limit > 500) $limit = 200;

$uid = current_user_id();
$uid = $uid ? (int)$uid : 0;

$rows = [];
try {
  $sql = "
    SELECT
      p.id, p.title, p.description, p.budget, p.status, p.authority_id, p.created_at,
      a.name AS authority_name,
      COALESCE((SELECT COUNT(*) FROM budget_votes v WHERE v.project_id = p.id), 0) AS vote_count,
      (SELECT 1 FROM budget_votes v WHERE v.project_id = p.id AND v.user_id = :uid LIMIT 1) AS voted_by_me
    FROM budget_projects p
    LEFT JOIN authorities a ON a.id = p.authority_id
    WHERE 1=1
  ";
  $params = [':uid' => $uid];

  if ($statusFilter !== '') {
    $sql .= " AND p.status = :status";
    $params[':status'] = $statusFilter;
  }
  if ($authorityId !== null && $authorityId > 0) {
    $sql .= " AND p.authority_id = :aid";
    $params[':aid'] = $authorityId;
  }

  $sql .= " ORDER BY vote_count DESC, p.created_at DESC LIMIT " . (int)$limit;

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as &$r) {
    $r['budget'] = (float)($r['budget'] ?? 0);
    $r['vote_count'] = (int)($r['vote_count'] ?? 0);
    $r['voted_by_me'] = !empty($r['voted_by_me']);
  }
  unset($r);
} catch (Throwable $e) {
  // táblák hiányozhatnak
}

json_response(['ok' => true, 'data' => $rows]);
