<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok'=>false,'error'=>'Method not allowed'], 405);
}

require_user();
start_secure_session();

$role = current_user_role() ?: '';
// communityuser = közület (pl. háziorvos, fogorvos) – csak a saját profilját és egy „buborék” (facility) szerkesztheti
if (!in_array($role, ['communityuser','admin','superadmin'], true)) {
  json_response(['ok'=>false,'error'=>'Nincs jogosultság.'], 403);
}

$body = read_json_body();
$name = safe_str($body['name'] ?? null, 160);
$serviceType = safe_str($body['service_type'] ?? null, 80);
$lat = $body['lat'] ?? null;
$lng = $body['lng'] ?? null;
$address = safe_str($body['address'] ?? null, 255);
$phone = safe_str($body['phone'] ?? null, 40);
$email = safe_str($body['email'] ?? null, 190);
$hoursJson = isset($body['hours_json']) ? json_encode($body['hours_json'], JSON_UNESCAPED_UNICODE) : null;
$replacementJson = isset($body['replacement_json']) ? json_encode($body['replacement_json'], JSON_UNESCAPED_UNICODE) : null;

if (!$name) json_response(['ok'=>false,'error'=>'Név kötelező.'], 400);
if (!is_numeric($lat) || !is_numeric($lng)) json_response(['ok'=>false,'error'=>'Lat/Lng kötelező.'], 400);

$userId = current_user_id();

// upsert by user_id
$stmt = db()->prepare("SELECT id FROM facilities WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid'=>$userId]);
$existing = (int)($stmt->fetchColumn() ?: 0);

if ($existing > 0) {
  db()->prepare("UPDATE facilities
    SET name=:n, service_type=:st, lat=:lat, lng=:lng, address=:addr, phone=:ph, email=:em,
        hours_json=:hj, replacement_json=:rj, updated_at=NOW(), is_active=1
    WHERE id=:id")
    ->execute([
      ':n'=>$name,':st'=>$serviceType,':lat'=>(float)$lat,':lng'=>(float)$lng,':addr'=>$address,
      ':ph'=>$phone,':em'=>$email,':hj'=>$hoursJson,':rj'=>$replacementJson,':id'=>$existing
    ]);
} else {
  db()->prepare("INSERT INTO facilities
    (user_id, name, service_type, lat, lng, address, phone, email, hours_json, replacement_json, updated_at)
    VALUES (:uid,:n,:st,:lat,:lng,:addr,:ph,:em,:hj,:rj,NOW())")
    ->execute([
      ':uid'=>$userId,':n'=>$name,':st'=>$serviceType,':lat'=>(float)$lat,':lng'=>(float)$lng,':addr'=>$address,
      ':ph'=>$phone,':em'=>$email,':hj'=>$hoursJson,':rj'=>$replacementJson
    ]);
}

json_response(['ok'=>true]);
