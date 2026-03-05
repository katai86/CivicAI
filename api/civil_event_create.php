<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

require_user();
start_secure_session();

$role = current_user_role() ?: '';
if (!in_array($role, ['civiluser','admin','superadmin'], true)) {
  json_response(['ok'=>false,'error'=>'Nincs jogosultság.'], 403);
}

$body = read_json_body();
$title = safe_str($body['title'] ?? null, 160);
$desc = safe_str($body['description'] ?? null, 2000);
$start = safe_str($body['start_date'] ?? null, 10);
$end = safe_str($body['end_date'] ?? null, 10);
$lat = $body['lat'] ?? null;
$lng = $body['lng'] ?? null;
$address = safe_str($body['address'] ?? null, 255);

if (!$title || !$start || !$end) json_response(['ok'=>false,'error'=>'Hiányzó mezők.'], 400);
if (!is_numeric($lat) || !is_numeric($lng)) json_response(['ok'=>false,'error'=>'Lat/Lng kötelező.'], 400);

$userId = current_user_id();

db()->prepare("INSERT INTO civil_events (user_id, title, description, start_date, end_date, lat, lng, address)
               VALUES (:uid,:t,:d,:sd,:ed,:lat,:lng,:addr)")
  ->execute([
    ':uid'=>$userId,
    ':t'=>$title,
    ':d'=>$desc,
    ':sd'=>$start,
    ':ed'=>$end,
    ':lat'=>(float)$lat,
    ':lng'=>(float)$lng,
    ':addr'=>$address
  ]);

json_response(['ok'=>true,'id'=>(int)db()->lastInsertId()]);
