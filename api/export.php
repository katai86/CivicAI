<?php
/**
 * M8 – Központosított export: reports, trees, ESG.
 * GET: dataset=reports|trees|esg, format=csv|geojson|json [, year, authority_id, city, date_from, date_to ]
 * Jog: admin vagy gov user (reports/esg: authority scope; trees: minden nyilvános).
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

start_secure_session();
$uid = current_user_id();
$role = current_user_role() ?: '';
$isAdmin = in_array($role, ['admin', 'superadmin'], true);
if ($uid <= 0 || (!$isAdmin && $role !== 'govuser')) {
  json_response(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$dataset = isset($_GET['dataset']) ? strtolower(trim((string)$_GET['dataset'])) : '';
$format = isset($_GET['format']) ? strtolower(trim((string)$_GET['format'])) : 'json';
if (!in_array($dataset, ['reports', 'trees', 'esg'], true)) {
  json_response(['ok' => false, 'error' => 'Invalid dataset. Use dataset=reports|trees|esg'], 400);
}
if (!in_array($format, ['csv', 'geojson', 'json'], true)) {
  json_response(['ok' => false, 'error' => 'Invalid format. Use format=csv|geojson|json'], 400);
}

$authorityId = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : null;
$city = isset($_GET['city']) ? trim((string)$_GET['city']) : '';
if (!$isAdmin) {
  $stmt = db()->prepare("SELECT authority_id FROM authority_users WHERE user_id = :uid ORDER BY authority_id ASC LIMIT 1");
  $stmt->execute([':uid' => $uid]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  $authorityId = $row ? (int)$row['authority_id'] : null;
  $city = '';
}

// ESG: átirányítás a meglévő végpontra
if ($dataset === 'esg') {
  $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
  $url = app_url('/api/esg_export.php?year=' . $year . '&format=' . $format);
  if ($authorityId > 0) $url .= '&authority_id=' . $authorityId;
  if ($city !== '') $url .= '&city=' . rawurlencode($city);
  header('Location: ' . $url);
  exit;
}

$pdo = db();
$where = '1=1';
$params = [];
if ($dataset === 'reports') {
  if ($authorityId > 0) {
    $where = 'r.authority_id = ?';
    $params[] = $authorityId;
  } elseif ($city !== '') {
    $where = '(r.authority_id IS NULL AND r.city = ?) OR r.city = ?';
    $params[] = $city;
    $params[] = $city;
  }
  $dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
  $dateTo = isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '';
  if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where .= ' AND r.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
  }
  if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where .= ' AND r.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
  }
}

if ($dataset === 'reports') {
  $stmt = $pdo->prepare("
    SELECT r.id, r.category, r.title, r.description, r.lat, r.lng, r.status, r.city, r.address_approx, r.created_at
    FROM reports r
    WHERE $where
    ORDER BY r.id ASC
    LIMIT 10000
  ");
  $stmt->execute($params);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
  // trees: minden nyilvános (nincs authority a trees táblában)
  $stmt = $pdo->query("
    SELECT id, lat, lng, address, species, estimated_age, planting_year, health_status, risk_level, last_watered, created_at
    FROM trees
    WHERE public_visible = 1
    ORDER BY id ASC
    LIMIT 10000
  ");
  $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

if ($format === 'geojson') {
  $features = [];
  foreach ($rows as $r) {
    $lat = isset($r['lat']) ? (float)$r['lat'] : null;
    $lng = isset($r['lng']) ? (float)$r['lng'] : null;
    if ($lat === null || $lng === null) continue;
    $props = $r;
    unset($props['lat'], $props['lng']);
    $features[] = [
      'type' => 'Feature',
      'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
      'properties' => $props,
    ];
  }
  $geojson = ['type' => 'FeatureCollection', 'features' => $features];
  header('Content-Type: application/geo+json; charset=utf-8');
  header('Content-Disposition: inline; filename="' . $dataset . '.geojson"');
  echo json_encode($geojson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}

if ($format === 'json') {
  json_response(['ok' => true, 'dataset' => $dataset, 'count' => count($rows), 'data' => $rows]);
  exit;
}

// CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $dataset . '.csv"');
$out = fopen('php://output', 'w');
if (count($rows) > 0) {
  fputcsv($out, array_keys($rows[0]));
  foreach ($rows as $r) {
    fputcsv($out, $r);
  }
}
fclose($out);
exit;
