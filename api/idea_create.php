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
  // Hatóság hozzárendelése a koordináta alapján (bbox), hogy a gov oldal város szerint szűrhasson
  try {
    $st = $pdo->prepare("
      SELECT id FROM authorities
      WHERE min_lat IS NOT NULL AND max_lat IS NOT NULL AND min_lng IS NOT NULL AND max_lng IS NOT NULL
        AND ? BETWEEN min_lat AND max_lat AND ? BETWEEN min_lng AND max_lng
      ORDER BY id LIMIT 1
    ");
    $st->execute([$lat, $lng]);
    $auth = $st->fetch(PDO::FETCH_ASSOC);
    if ($auth && !empty($auth['id'])) {
      $pdo->prepare("UPDATE ideas SET authority_id = ? WHERE id = ?")->execute([(int)$auth['id'], $id]);
    }
  } catch (Throwable $ignored) { /* authorities tábla vagy oszlopok hiányozhatnak */ }
  json_response(['ok' => true, 'id' => $id]);
} catch (Throwable $e) {
  $msg = $e->getMessage();
  if (strpos($msg, 'ideas') !== false || strpos($msg, "doesn't exist") !== false) {
    json_response(['ok' => false, 'error' => t('api.report_save_failed')], 503);
  }
  throw $e;
}
