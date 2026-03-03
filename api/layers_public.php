<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$sql = "
  SELECT l.id, l.layer_key, l.name, l.category, l.is_active, l.is_temporary,
         l.visible_from, l.visible_to
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
  json_response(['ok'=>true,'data'=>[]]);
}

if (!$layers) {
  json_response(['ok'=>true,'data'=>[]]);
}

$ids = array_map(fn($l) => (int)$l['id'], $layers);
$in = implode(',', array_fill(0, count($ids), '?'));

$points = [];
try {
  $stmt = db()->prepare("SELECT id, layer_id, name, lat, lng, address, meta_json FROM map_layer_points WHERE layer_id IN ($in)");
  $stmt->execute($ids);
  $points = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
  $points = [];
}

json_response(['ok'=>true,'data'=>['layers'=>$layers,'points'=>$points]]);
