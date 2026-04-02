<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $layerId = isset($_GET['layer_id']) ? (int)$_GET['layer_id'] : 0;
  $withPoints = !empty($_GET['with_points']);
  try {
    if ($layerId > 0) {
      $stmt = db()->prepare("SELECT id, layer_id, name, lat, lng, address, meta_json FROM map_layer_points WHERE layer_id = :id ORDER BY id DESC");
      $stmt->execute([':id'=>$layerId]);
      $rows = $stmt->fetchAll() ?: [];
      json_response(['ok'=>true,'data'=>$rows]);
    }

    try {
      $sql = "
        SELECT l.id, l.layer_key, l.name, l.category, l.is_active, l.is_temporary,
               l.visible_from, l.visible_to, l.authority_id, l.layer_type,
               a.name AS authority_name, a.city AS authority_city,
               COALESCE(c.cnt, 0) AS point_count
        FROM map_layers l
        LEFT JOIN (SELECT layer_id, COUNT(*) AS cnt FROM map_layer_points GROUP BY layer_id) c ON c.layer_id = l.id
        LEFT JOIN authorities a ON a.id = l.authority_id
        ORDER BY l.id DESC
      ";
      $rows = db()->query($sql)->fetchAll() ?: [];
    } catch (Throwable $e) {
      $sql = "
        SELECT l.id, l.layer_key, l.name, l.category, l.is_active, l.is_temporary,
               l.visible_from, l.visible_to, COUNT(p.id) AS point_count
        FROM map_layers l
        LEFT JOIN map_layer_points p ON p.layer_id = l.id
        GROUP BY l.id
        ORDER BY l.id DESC
      ";
      $rows = db()->query($sql)->fetchAll() ?: [];
      foreach ($rows as &$r) { $r['authority_id'] = null; $r['layer_type'] = null; $r['authority_name'] = null; $r['authority_city'] = null; }
    }
    if (!$withPoints) {
      json_response(['ok'=>true,'data'=>$rows]);
    }

    $activeIds = array_map(fn($l) => (int)$l['id'], array_filter($rows, fn($l) => (int)$l['is_active'] === 1));
    if (!$activeIds) {
      json_response(['ok'=>true,'data'=>['layers'=>$rows,'points'=>[]]]);
    }
    $in = implode(',', array_fill(0, count($activeIds), '?'));
    $stmt = db()->prepare("SELECT id, layer_id, name, lat, lng, address, meta_json FROM map_layer_points WHERE layer_id IN ($in)");
    $stmt->execute($activeIds);
    $points = $stmt->fetchAll() ?: [];
    json_response(['ok'=>true,'data'=>['layers'=>$rows,'points'=>$points]]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>t('api.layer_tables_missing')], 500);
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok'=>false,'error'=>t('api.method_not_allowed')], 405);
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
  $authorityId = isset($body['authority_id']) ? (int)$body['authority_id'] : null;
  $layerType = safe_str($body['layer_type'] ?? null, 32);
  if (!$category) json_response(['ok'=>false,'error'=>t('api.missing_category')], 400);
  if ($category === 'trees') {
    $key = 'trees';
    $name = $name ?: 'F?k (fakataszter)';
    $layerType = 'trees';
  }
  if (!$key || !$name) json_response(['ok'=>false,'error'=>t('api.missing_fields')], 400);
  if (!preg_match('/^[a-z0-9_\\-]+$/i', $key)) json_response(['ok'=>false,'error'=>t('api.invalid_key')], 400);
  if ($authorityId !== null && $authorityId <= 0) $authorityId = null;
  try {
    $stmt = db()->prepare("
      INSERT INTO map_layers (layer_key, name, category, is_active, is_temporary, visible_from, visible_to, authority_id, layer_type)
      VALUES (:k,:n,:c,:a,:t,:f,:to,:aid,:ltype)
    ");
    $stmt->execute([
      ':k'=>$key,':n'=>$name,':c'=>$category,':a'=>$isActive,':t'=>$isTemp,':f'=>$from ?: null,':to'=>$to ?: null,
      ':aid'=>$authorityId,':ltype'=>$layerType ?: null
    ]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) json_response(['ok'=>false,'error'=>t('api.layer_key_exists')], 400);
    json_response(['ok'=>false,'error'=>t('api.layer_tables_missing')], 500);
  }
}

if ($action === 'update_layer') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok'=>false,'error'=>t('api.invalid_id')], 400);
  $name = safe_str($body['name'] ?? null, 120);
  $category = safe_str($body['category'] ?? null, 32);
  $isActive = !empty($body['is_active']) ? 1 : 0;
  $isTemp = !empty($body['is_temporary']) ? 1 : 0;
  $from = safe_str($body['visible_from'] ?? null, 10);
  $to = safe_str($body['visible_to'] ?? null, 10);
  $authorityId = isset($body['authority_id']) ? (int)$body['authority_id'] : null;
  if ($authorityId !== null && $authorityId <= 0) $authorityId = null;
  try {
    $pdo = db();
    $pdo->prepare("
      UPDATE map_layers
      SET name=:n, category=:c, is_active=:a, is_temporary=:t, visible_from=:f, visible_to=:to, authority_id=:aid
      WHERE id=:id
    ")->execute([
      ':n'=>$name,':c'=>$category,':a'=>$isActive,':t'=>$isTemp,':f'=>$from ?: null,':to'=>$to ?: null,':aid'=>$authorityId,':id'=>$id
    ]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    if (strpos($e->getMessage(), 'Unknown column') !== false) {
      db()->prepare("
        UPDATE map_layers
        SET name=:n, category=:c, is_active=:a, is_temporary=:t, visible_from=:f, visible_to=:to
        WHERE id=:id
      ")->execute([
        ':n'=>$name,':c'=>$category,':a'=>$isActive,':t'=>$isTemp,':f'=>$from ?: null,':to'=>$to ?: null,':id'=>$id
      ]);
      json_response(['ok'=>true]);
    } else {
      json_response(['ok'=>false,'error'=>t('api.layer_tables_missing')], 500);
    }
  }
}

if ($action === 'toggle_layer') {
  $id = (int)($body['id'] ?? 0);
  $isActive = !empty($body['is_active']) ? 1 : 0;
  if ($id <= 0) json_response(['ok'=>false,'error'=>t('api.invalid_id')], 400);
  try {
    db()->prepare("UPDATE map_layers SET is_active=:a WHERE id=:id")->execute([':a'=>$isActive,':id'=>$id]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>t('api.layer_tables_missing')], 500);
  }
}

if ($action === 'delete_layer') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok'=>false,'error'=>t('api.invalid_id')], 400);
  try {
    db()->prepare("DELETE FROM map_layer_points WHERE layer_id=:id")->execute([':id'=>$id]);
    db()->prepare("DELETE FROM map_layers WHERE id=:id")->execute([':id'=>$id]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>t('api.layer_tables_missing')], 500);
  }
}

if ($action === 'create_point') {
  $layerId = (int)($body['layer_id'] ?? 0);
  $name = safe_str($body['name'] ?? null, 120);
  $lat = $body['lat'] ?? null;
  $lng = $body['lng'] ?? null;
  $address = safe_str($body['address'] ?? null, 255);
  $meta = $body['meta_json'] ?? null;

  if ($layerId <= 0) json_response(['ok'=>false,'error'=>t('api.missing_fields')], 400);
  try {
    $layerRow = db()->prepare("SELECT layer_type FROM map_layers WHERE id = :id LIMIT 1");
    $layerRow->execute([':id' => $layerId]);
    $layer = $layerRow->fetch(PDO::FETCH_ASSOC);
    if ($layer && (string)($layer['layer_type'] ?? '') === 'trees') {
      json_response(['ok'=>false,'error'=>t('api.trees_no_point')], 400);
    }
  } catch (Throwable $e) { /* no layer_type column */ }

  if (!is_numeric($lat) || !is_numeric($lng)) {
    if ($address) {
      $point = nominatim_geocode_to_point($address);
      if ($point) {
        $lat = $point[0];
        $lng = $point[1];
      }
    }
  }
  if (!is_numeric($lat) || !is_numeric($lng)) {
    json_response(['ok'=>false,'error'=>t('api.add_lat_lng_or_address')], 400);
  }

  try {
    db()->prepare("
      INSERT INTO map_layer_points (layer_id, name, lat, lng, address, meta_json)
      VALUES (:lid,:n,:lat,:lng,:a,:m)
    ")->execute([
      ':lid'=>$layerId,':n'=>$name,':lat'=>(float)$lat,':lng'=>(float)$lng,':a'=>$address,':m'=>$meta
    ]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>t('api.layer_tables_missing')], 500);
  }
}

if ($action === 'delete_point') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok'=>false,'error'=>t('api.invalid_id')], 400);
  try {
    db()->prepare("DELETE FROM map_layer_points WHERE id=:id")->execute([':id'=>$id]);
    json_response(['ok'=>true]);
  } catch (Throwable $e) {
    json_response(['ok'=>false,'error'=>t('api.layer_tables_missing')], 500);
  }
}

json_response(['ok'=>false,'error'=>t('api.invalid_action')], 400);
