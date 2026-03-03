<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $layerId = isset($_GET['layer_id']) ? (int)$_GET['layer_id'] : 0;
  try {
    if ($layerId > 0) {
      $stmt = db()->prepare("SELECT id, layer_id, name, lat, lng, address, meta_json FROM map_layer_points WHERE layer_id = :id ORDER BY id DESC");
      $stmt->execute([':id'=>$layerId]);
      $rows = $stmt->fetchAll() ?: [];
      json_response(['ok'=>true,'data'=>$rows]);
    }

    $sql = "
      SELECT l.id, l.layer_key, l.name, l.category, l.is_active, l.is_temporary,
             l.visible_from, l.visible_to, COUNT(p.id) AS point_count
      FROM map_layers l
      LEFT JOIN map_layer_points p ON p.layer_id = l.id
      GROUP BY l.id
      ORDER BY l.id DESC
    ";
    $rows = db()->query($sql)->fetchAll() ?: [];
    json_response(['ok'=>true,'data'=>$rows]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>'Layer táblák hiányoznak. Futtasd az SQL-t.'], 500);
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

$body = read_json_body();
$action = (string)($body['action'] ?? '');

if ($action === 'create_layer') {
  $key = safe_str($body['layer_key'] ?? null, 64);
  $name = safe_str($body['name'] ?? null, 120);
  $category = safe_str($body['category'] ?? null, 32);
  $isActive = !empty($body['is_active']) ? 1 : 0;
  $isTemp = !empty($body['is_temporary']) ? 1 : 0;
  $from = safe_str($body['visible_from'] ?? null, 10);
  $to = safe_str($body['visible_to'] ?? null, 10);
  if (!$key || !$name || !$category) json_response(['ok'=>false,'error'=>'Missing fields'], 400);
  if (!preg_match('/^[a-z0-9_\\-]+$/i', $key)) json_response(['ok'=>false,'error'=>'Invalid key'], 400);
  try {
    db()->prepare("
      INSERT INTO map_layers (layer_key, name, category, is_active, is_temporary, visible_from, visible_to)
      VALUES (:k,:n,:c,:a,:t,:f,:to)
    ")->execute([
      ':k'=>$key,':n'=>$name,':c'=>$category,':a'=>$isActive,':t'=>$isTemp,':f'=>$from ?: null,':to'=>$to ?: null
    ]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>'Layer táblák hiányoznak. Futtasd az SQL-t.'], 500);
  }
}

if ($action === 'update_layer') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok'=>false,'error'=>'Invalid id'], 400);
  $name = safe_str($body['name'] ?? null, 120);
  $category = safe_str($body['category'] ?? null, 32);
  $isActive = !empty($body['is_active']) ? 1 : 0;
  $isTemp = !empty($body['is_temporary']) ? 1 : 0;
  $from = safe_str($body['visible_from'] ?? null, 10);
  $to = safe_str($body['visible_to'] ?? null, 10);
  try {
    db()->prepare("
      UPDATE map_layers
      SET name=:n, category=:c, is_active=:a, is_temporary=:t, visible_from=:f, visible_to=:to
      WHERE id=:id
    ")->execute([
      ':n'=>$name,':c'=>$category,':a'=>$isActive,':t'=>$isTemp,':f'=>$from ?: null,':to'=>$to ?: null,':id'=>$id
    ]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>'Layer táblák hiányoznak. Futtasd az SQL-t.'], 500);
  }
}

if ($action === 'toggle_layer') {
  $id = (int)($body['id'] ?? 0);
  $isActive = !empty($body['is_active']) ? 1 : 0;
  if ($id <= 0) json_response(['ok'=>false,'error'=>'Invalid id'], 400);
  try {
    db()->prepare("UPDATE map_layers SET is_active=:a WHERE id=:id")->execute([':a'=>$isActive,':id'=>$id]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>'Layer táblák hiányoznak. Futtasd az SQL-t.'], 500);
  }
}

if ($action === 'delete_layer') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok'=>false,'error'=>'Invalid id'], 400);
  try {
    db()->prepare("DELETE FROM map_layer_points WHERE layer_id=:id")->execute([':id'=>$id]);
    db()->prepare("DELETE FROM map_layers WHERE id=:id")->execute([':id'=>$id]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>'Layer táblák hiányoznak. Futtasd az SQL-t.'], 500);
  }
}

if ($action === 'create_point') {
  $layerId = (int)($body['layer_id'] ?? 0);
  $name = safe_str($body['name'] ?? null, 120);
  $lat = $body['lat'] ?? null;
  $lng = $body['lng'] ?? null;
  $address = safe_str($body['address'] ?? null, 255);
  $meta = $body['meta_json'] ?? null;
  if ($layerId <= 0 || !is_numeric($lat) || !is_numeric($lng)) json_response(['ok'=>false,'error'=>'Missing fields'], 400);
  try {
    db()->prepare("
      INSERT INTO map_layer_points (layer_id, name, lat, lng, address, meta_json)
      VALUES (:lid,:n,:lat,:lng,:a,:m)
    ")->execute([
      ':lid'=>$layerId,':n'=>$name,':lat'=>(float)$lat,':lng'=>(float)$lng,':a'=>$address,':m'=>$meta
    ]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>'Layer táblák hiányoznak. Futtasd az SQL-t.'], 500);
  }
}

if ($action === 'delete_point') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok'=>false,'error'=>'Invalid id'], 400);
  try {
    db()->prepare("DELETE FROM map_layer_points WHERE id=:id")->execute([':id'=>$id]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>'Layer táblák hiányoznak. Futtasd az SQL-t.'], 500);
  }
}

json_response(['ok'=>false,'error'=>'Invalid action'], 400);
