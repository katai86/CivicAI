<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$minLat = $_GET['minLat'] ?? null;
$maxLat = $_GET['maxLat'] ?? null;
$minLng = $_GET['minLng'] ?? null;
$maxLng = $_GET['maxLng'] ?? null;
$limit = (int)($_GET['limit'] ?? 2000);
if ($limit < 100 || $limit > 5000) $limit = 2000;

$sql = "
  SELECT l.id, l.layer_key, l.name, l.category, l.is_active, l.is_temporary,
         l.visible_from, l.visible_to, l.layer_type
  FROM map_layers l
  WHERE l.is_active = 1
    AND (l.visible_from IS NULL OR l.visible_from <= CURDATE())
    AND (l.visible_to IS NULL OR l.visible_to >= CURDATE())
  ORDER BY l.id DESC
";

$layers = [];
try {
  $layers = db()->query($sql)->fetchAll() ?: [];
} catch (Throwable $e) {
  $sql = "
    SELECT l.id, l.layer_key, l.name, l.category, l.is_active, l.is_temporary,
           l.visible_from, l.visible_to
    FROM map_layers l
    WHERE l.is_active = 1
      AND (l.visible_from IS NULL OR l.visible_from <= CURDATE())
      AND (l.visible_to IS NULL OR l.visible_to >= CURDATE())
    ORDER BY l.id DESC
  ";
  $layers = db()->query($sql)->fetchAll() ?: [];
  foreach ($layers as &$l) { $l['layer_type'] = null; }
}

if (!$layers) {
  json_response(['ok'=>true,'data'=>[]]);
}

$ids = array_map(fn($l) => (int)$l['id'], $layers);
$in = implode(',', array_fill(0, count($ids), '?'));

$points = [];
try {
  $pointSql = "SELECT id, layer_id, name, lat, lng, address, meta_json FROM map_layer_points WHERE layer_id IN ($in)";
  $params = $ids;
  if (is_numeric($minLat) && is_numeric($maxLat) && is_numeric($minLng) && is_numeric($maxLng)) {
    $pointSql .= " AND lat BETWEEN ? AND ? AND lng BETWEEN ? AND ?";
    $params[] = (float)$minLat;
    $params[] = (float)$maxLat;
    $params[] = (float)$minLng;
    $params[] = (float)$maxLng;
  }
  $pointSql .= " LIMIT $limit";
  $stmt = db()->prepare($pointSql);
  $stmt->execute($params);
  $points = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
  $points = [];
}

json_response(['ok'=>true,'data'=>['layers'=>$layers,'points'=>$points]]);
