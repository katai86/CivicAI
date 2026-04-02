<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

$uid = current_user_id();
$uid = $uid ? (int)$uid : 0;

$category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$category = $category === 'all' ? '' : $category;
$limit = (int)($_GET['limit'] ?? 800);
if ($limit < 100 || $limit > 2000) $limit = 800;

$minLat = $_GET['minLat'] ?? null;
$maxLat = $_GET['maxLat'] ?? null;
$minLng = $_GET['minLng'] ?? null;
$maxLng = $_GET['maxLng'] ?? null;

// Publikus térképen csak "élő" státuszok jelenjenek meg
$visibleStatuses = [
  'approved',
  'needs_info',
  'forwarded',
  'waiting_reply',
  'in_progress',
];

$in = implode(',', array_fill(0, count($visibleStatuses), '?'));

$sql = "
  SELECT
    r.id, r.category, r.title, r.description, r.lat, r.lng,
    r.status,
    r.created_at,
    r.created_at AS updated_at,
    r.reporter_is_anonymous,
    CASE
      WHEN r.reporter_is_anonymous = 0 THEN r.reporter_name
      ELSE NULL
    END AS reporter_name_public,
    u.id AS reporter_user_id,
    u.display_name AS reporter_display_name,
    u.profile_public AS reporter_profile_public,
    u.level AS reporter_level,
    COALESCE((SELECT COUNT(*) FROM report_likes rl WHERE rl.report_id = r.id), 0) AS like_count,
    (SELECT 1 FROM report_likes rl WHERE rl.report_id = r.id AND rl.user_id = ? LIMIT 1) AS liked_by_me
  FROM reports r
  LEFT JOIN users u ON u.id = r.user_id
  WHERE r.status IN ($in)
";

$rows = [];
try {

if ($category !== '') {
  $sql .= " AND r.category = ?";
}

if (is_numeric($minLat) && is_numeric($maxLat) && is_numeric($minLng) && is_numeric($maxLng)) {
  $sql .= " AND r.lat BETWEEN ? AND ? AND r.lng BETWEEN ? AND ?";
}

$sql .= " ORDER BY r.created_at DESC LIMIT $limit";

$stmt = db()->prepare($sql);

$exec = [$uid];
$exec = array_merge($exec, array_values($visibleStatuses));
if ($category !== '') {
  $exec[] = $category;
}
if (is_numeric($minLat) && is_numeric($maxLat) && is_numeric($minLng) && is_numeric($maxLng)) {
  $exec[] = (float)$minLat;
  $exec[] = (float)$maxLat;
  $exec[] = (float)$minLng;
  $exec[] = (float)$maxLng;
}

$stmt->execute($exec);
$rows = $stmt->fetchAll();
} catch (Throwable $e) {
  $sql = "
    SELECT
      r.id, r.category, r.title, r.description, r.lat, r.lng,
      r.status, r.created_at, r.created_at AS updated_at,
      r.reporter_is_anonymous,
      CASE WHEN r.reporter_is_anonymous = 0 THEN r.reporter_name ELSE NULL END AS reporter_name_public,
      u.id AS reporter_user_id, u.display_name AS reporter_display_name,
      u.profile_public AS reporter_profile_public, u.level AS reporter_level,
      0 AS like_count, NULL AS liked_by_me
    FROM reports r
    LEFT JOIN users u ON u.id = r.user_id
    WHERE r.status IN ($in)
  ";
  if ($category !== '') $sql .= " AND r.category = ?";
  if (is_numeric($minLat) && is_numeric($maxLat) && is_numeric($minLng) && is_numeric($maxLng)) {
    $sql .= " AND r.lat BETWEEN ? AND ? AND r.lng BETWEEN ? AND ?";
  }
  $sql .= " ORDER BY r.created_at DESC LIMIT $limit";
  $stmt = db()->prepare($sql);
  $execFallback = array_values($visibleStatuses);
  if ($category !== '') $execFallback[] = $category;
  if (is_numeric($minLat) && is_numeric($maxLat) && is_numeric($minLng) && is_numeric($maxLng)) {
    $execFallback[] = (float)$minLat;
    $execFallback[] = (float)$maxLat;
    $execFallback[] = (float)$minLng;
    $execFallback[] = (float)$maxLng;
  }
  $stmt->execute($execFallback);
  $rows = $stmt->fetchAll();
}

json_response(['ok' => true, 'data' => $rows]);