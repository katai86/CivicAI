<?php
/**
 * M3 Ideation – ötletek listája térképhez és listanézethez.
 * GET: minLat, maxLat, minLng, maxLng, limit, status (opcionális szűrés).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

$minLat = $_GET['minLat'] ?? null;
$maxLat = $_GET['maxLat'] ?? null;
$minLng = $_GET['minLng'] ?? null;
$maxLng = $_GET['maxLng'] ?? null;
$limit = (int)($_GET['limit'] ?? 500);
if ($limit < 50 || $limit > 2000) $limit = 500;

$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$allowedStatuses = ['submitted', 'under_review', 'planned', 'in_progress', 'completed'];
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
  $statusFilter = '';
}

$uid = current_user_id();
$uid = $uid ? (int)$uid : 0;

$rows = [];
try {
  $sql = "
    SELECT
      i.id, i.user_id, i.title, i.description, i.lat, i.lng, i.address, i.status, i.created_at, i.updated_at,
      u.display_name AS author_name,
      COALESCE((SELECT COUNT(*) FROM idea_votes v WHERE v.idea_id = i.id), 0) AS vote_count,
      (SELECT 1 FROM idea_votes v WHERE v.idea_id = i.id AND v.user_id = :uid LIMIT 1) AS voted_by_me
    FROM ideas i
    LEFT JOIN users u ON u.id = i.user_id
    WHERE 1=1
  ";
  $params = [':uid' => $uid];

  if ($statusFilter !== '') {
    $sql .= " AND i.status = :status";
    $params[':status'] = $statusFilter;
  }

  if (is_numeric($minLat) && is_numeric($maxLat) && is_numeric($minLng) && is_numeric($maxLng)) {
    $sql .= " AND i.lat BETWEEN :minLat AND :maxLat AND i.lng BETWEEN :minLng AND :maxLng";
    $params[':minLat'] = (float)$minLat;
    $params[':maxLat'] = (float)$maxLat;
    $params[':minLng'] = (float)$minLng;
    $params[':maxLng'] = (float)$maxLng;
  }

  $sql .= " ORDER BY i.created_at DESC LIMIT " . (int)$limit;

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rows as &$r) {
    $r['vote_count'] = (int)($r['vote_count'] ?? 0);
    $r['voted_by_me'] = !empty($r['voted_by_me']);
  }
  unset($r);
} catch (Throwable $e) {
  // ideas tábla hiányozhat
}

json_response(['ok' => true, 'data' => $rows]);
