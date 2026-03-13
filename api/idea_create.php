<?php
/**
 * M3 Ideation – új ötlet beküldése. Bejelentkezés kötelező.
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

start_secure_session();
require_user();
$uid = current_user_id();
if (!$uid) {
  json_response(['ok' => false, 'error' => t('api.auth_required')], 401);
}

$body = read_json_body();
$title = safe_str($body['title'] ?? null, 200);
$description = safe_str($body['description'] ?? null, 5000);
$lat = $body['lat'] ?? null;
$lng = $body['lng'] ?? null;
$address = safe_str($body['address'] ?? null, 255);

if (!$title || trim($title) === '') {
  json_response(['ok' => false, 'error' => t('api.facility_name_required')], 400);
}
if (!is_numeric($lat) || !is_numeric($lng)) {
  json_response(['ok' => false, 'error' => t('api.facility_lat_lng_required')], 400);
}

$lat = (float)$lat;
$lng = (float)$lng;

try {
  $pdo = db();
  $pdo->prepare("
    INSERT INTO ideas (user_id, title, description, lat, lng, address, status)
    VALUES (:uid, :title, :desc, :lat, :lng, :addr, 'submitted')
  ")->execute([
    ':uid' => $uid,
    ':title' => $title,
    ':desc' => $description ?: null,
    ':lat' => $lat,
    ':lng' => $lng,
    ':addr' => $address ?: null,
  ]);
  $id = (int)$pdo->lastInsertId();
  json_response(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
  $msg = $e->getMessage();
  if (strpos($msg, 'ideas') !== false || strpos($msg, "doesn't exist") !== false) {
    json_response(['ok' => false, 'error' => t('api.report_save_failed')], 503);
  }
  throw $e;
}
