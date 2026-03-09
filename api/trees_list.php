<?php
/**
 * Trees list for map (Urban Tree Cadastre – M1).
 * GET: minLat, maxLat, minLng, maxLng, limit, filter=all|adopted|needs_water|dangerous
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$minLat = $_GET['minLat'] ?? null;
$maxLat = $_GET['maxLat'] ?? null;
$minLng = $_GET['minLng'] ?? null;
$maxLng = $_GET['maxLng'] ?? null;
$limit = (int)($_GET['limit'] ?? 500);
if ($limit < 50 || $limit > 2000) $limit = 500;
$filter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : 'all';
if (!in_array($filter, ['all', 'adopted', 'needs_water', 'dangerous'], true)) $filter = 'all';

$rows = [];
try {
  $pdo = db();
  $sql = "
    SELECT t.id, t.lat, t.lng, t.address, t.species, t.estimated_age, t.planting_year,
           t.health_status, t.risk_level, t.last_inspection, t.last_watered,
           t.adopted_by_user_id, t.gov_validated, t.public_visible, t.created_at, t.updated_at,
           u.display_name AS adopter_name
    FROM trees t
    LEFT JOIN users u ON u.id = t.adopted_by_user_id
    WHERE t.public_visible = 1
  ";
  $params = [];

  if ($filter === 'adopted') {
    $sql .= " AND t.adopted_by_user_id IS NOT NULL";
  } elseif ($filter === 'needs_water') {
    $sql .= " AND (t.last_watered IS NULL OR t.last_watered < DATE_SUB(CURDATE(), INTERVAL 7 DAY))";
  } elseif ($filter === 'dangerous') {
    $sql .= " AND t.risk_level IN ('high', 'medium')";
  }

  if (is_numeric($minLat) && is_numeric($maxLat) && is_numeric($minLng) && is_numeric($maxLng)) {
    $sql .= " AND t.lat BETWEEN ? AND ? AND t.lng BETWEEN ? AND ?";
    $params[] = (float)$minLat;
    $params[] = (float)$maxLat;
    $params[] = (float)$minLng;
    $params[] = (float)$maxLng;
  }

  $sql .= " ORDER BY t.id DESC LIMIT " . (int)$limit;
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  // tábla még nincs: üres lista
  $rows = [];
}

json_response(['ok' => true, 'data' => $rows]);
