<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

// Ez az endpoint GET-tel működik, mert a frontend query stringgel hívja
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$lat = $_GET['lat'] ?? null;
$lng = $_GET['lng'] ?? null;
$category = isset($_GET['category']) ? trim((string)$_GET['category']) : '';
$radius = isset($_GET['radius']) ? (int)$_GET['radius'] : 50;

$allowedCats = ['road','sidewalk','lighting','trash','green','traffic','idea'];

if (!is_numeric($lat) || !is_numeric($lng)) {
  json_response(['ok' => false, 'error' => 'Invalid coordinates'], 400);
}
if (!$category || !in_array($category, $allowedCats, true)) {
  json_response(['ok' => false, 'error' => 'Invalid category'], 400);
}
if ($radius < 10) $radius = 10;
if ($radius > 300) $radius = 300;

$lat = (float)$lat;
$lng = (float)$lng;

// Orosháza környéke sanity check (ugyanaz a logika, mint create-nél)
if ($lat < 46.3 || $lat > 46.8 || $lng < 20.3 || $lng > 21.1) {
  json_response(['ok' => false, 'error' => 'Out of allowed area'], 400);
}

// Gyors bounding box (fölösleges rekordok kiszűrésére)
$degLat = $radius / 111320.0;
$degLng = $radius / (111320.0 * max(0.1, cos(deg2rad($lat))));

$minLat = $lat - $degLat;
$maxLat = $lat + $degLat;
$minLng = $lng - $degLng;
$maxLng = $lng + $degLng;

// Haversine méterben – duplikációnak számít minden "nem lezárt" ügy.
// (elutasítva/lezárva/megoldva nem számítson blokkoló duplikációnak)
$sql = "
  SELECT
    id, status, title, description, lat, lng, created_at,
    (6371000 * 2 * ASIN(SQRT(
      POWER(SIN(RADIANS(lat - :lat) / 2), 2) +
      COS(RADIANS(:lat)) * COS(RADIANS(lat)) *
      POWER(SIN(RADIANS(lng - :lng) / 2), 2)
    ))) AS distance_m
  FROM reports
  WHERE category = :cat
    AND status NOT IN ('rejected','closed','solved')
    AND lat BETWEEN :minLat AND :maxLat
    AND lng BETWEEN :minLng AND :maxLng
  HAVING distance_m <= :radius
  ORDER BY distance_m ASC
  LIMIT 5
";

$stmt = db()->prepare($sql);
$stmt->execute([
  ':lat' => $lat,
  ':lng' => $lng,
  ':cat' => $category,
  ':minLat' => $minLat,
  ':maxLat' => $maxLat,
  ':minLng' => $minLng,
  ':maxLng' => $maxLng,
  ':radius' => $radius,
]);

$rows = $stmt->fetchAll();

json_response([
  'ok' => true,
  'radius' => $radius,
  'count' => count($rows),
  'data' => $rows,
]);