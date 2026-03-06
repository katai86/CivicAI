<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

require_admin();
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $auth = [];
  $contacts = [];
  $assign = [];
  try {
    $auth = db()->query("SELECT * FROM authorities ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { log_error('admin_authorities auth: ' . $e->getMessage()); }
  try {
    $contacts = db()->query("SELECT * FROM authority_contacts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) { log_error('admin_authorities contacts: ' . $e->getMessage()); }
  try {
    $assign = db()->query("
      SELECT au.id, au.authority_id, a.name AS authority_name, au.user_id, u.email,
        COALESCE(u.display_name, u.email) AS display_name
      FROM authority_users au
      JOIN users u ON u.id = au.user_id
      JOIN authorities a ON a.id = au.authority_id
      ORDER BY au.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    log_error('admin_authorities assign: ' . $e->getMessage());
  }
  json_response(['ok' => true, 'authorities' => $auth, 'contacts' => $contacts, 'assignments' => $assign]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$body = read_json_body();
$action = safe_str($body['action'] ?? null, 32);

if ($action === 'create_authority') {
  $name = safe_str($body['name'] ?? null, 160);
  $country = safe_str($body['country'] ?? null, 80);
  $region = safe_str($body['region'] ?? null, 80);
  $city = safe_str($body['city'] ?? null, 80);
  $address = safe_str($body['address'] ?? null, 255);
  $email = safe_str($body['contact_email'] ?? null, 190);
  $phone = safe_str($body['contact_phone'] ?? null, 40);
  $website = safe_str($body['website'] ?? null, 190);
  $minLat = is_numeric($body['min_lat'] ?? null) ? (float)$body['min_lat'] : null;
  $maxLat = is_numeric($body['max_lat'] ?? null) ? (float)$body['max_lat'] : null;
  $minLng = is_numeric($body['min_lng'] ?? null) ? (float)$body['min_lng'] : null;
  $maxLng = is_numeric($body['max_lng'] ?? null) ? (float)$body['max_lng'] : null;
  if ($address && ($minLat === null || $maxLat === null || $minLng === null || $maxLng === null)) {
    $bbox = nominatim_geocode_address($address);
    if ($bbox) {
      $minLat = $bbox[0];
      $maxLat = $bbox[1];
      $minLng = $bbox[2];
      $maxLng = $bbox[3];
    }
  }
  $isActive = !empty($body['is_active']) ? 1 : 0;

  if (!$name) json_response(['ok' => false, 'error' => 'Name required'], 400);

  $pdo = db();
  try {
    $pdo->prepare("
      INSERT INTO authorities (name, country, region, city, contact_email, contact_phone, website, is_active, min_lat, max_lat, min_lng, max_lng)
      VALUES (:name, :country, :region, :city, :email, :phone, :website, :active, :minlat, :maxlat, :minlng, :maxlng)
    ")->execute([
      ':name' => $name,
      ':country' => $country,
      ':region' => $region,
      ':city' => $city,
      ':email' => $email,
      ':phone' => $phone,
      ':website' => $website,
      ':active' => $isActive,
      ':minlat' => $minLat,
      ':maxlat' => $maxLat,
      ':minlng' => $minLng,
      ':maxlng' => $maxLng,
    ]);
  } catch (Throwable $e) {
    $pdo->prepare("
      INSERT INTO authorities (name, country, region, city, contact_email, contact_phone, website, is_active)
      VALUES (:name, :country, :region, :city, :email, :phone, :website, :active)
    ")->execute([
      ':name' => $name,
      ':country' => $country,
      ':region' => $region,
      ':city' => $city,
      ':email' => $email,
      ':phone' => $phone,
      ':website' => $website,
      ':active' => $isActive,
    ]);
  }
  json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'delete_authority') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid id'], 400);
  db()->prepare("DELETE FROM authority_contacts WHERE authority_id=:id")->execute([':id' => $id]);
  db()->prepare("DELETE FROM authorities WHERE id=:id")->execute([':id' => $id]);
  json_response(['ok' => true]);
}

if ($action === 'create_contact') {
  $authorityId = (int)($body['authority_id'] ?? 0);
  $serviceCode = safe_str($body['service_code'] ?? null, 64);
  $name = safe_str($body['name'] ?? null, 160);
  $description = safe_str($body['description'] ?? null, 2000);
  $isActive = !empty($body['is_active']) ? 1 : 0;
  if ($authorityId <= 0 || !$serviceCode || !$name) {
    json_response(['ok' => false, 'error' => 'Invalid data'], 400);
  }
  db()->prepare("
    INSERT INTO authority_contacts (authority_id, service_code, name, description, is_active)
    VALUES (:aid, :code, :name, :desc, :active)
  ")->execute([
    ':aid' => $authorityId,
    ':code' => $serviceCode,
    ':name' => $name,
    ':desc' => $description,
    ':active' => $isActive,
  ]);
  json_response(['ok' => true, 'id' => (int)db()->lastInsertId()]);
}

if ($action === 'delete_contact') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid id'], 400);
  db()->prepare("DELETE FROM authority_contacts WHERE id=:id")->execute([':id' => $id]);
  json_response(['ok' => true]);
}

if ($action === 'assign_user') {
  $authorityId = (int)($body['authority_id'] ?? 0);
  $email = safe_str($body['email'] ?? null, 190);
  if ($authorityId <= 0 || !$email) {
    json_response(['ok' => false, 'error' => 'Invalid data'], 400);
  }
  $stmt = db()->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
  $stmt->execute([':email' => $email]);
  $uid = (int)$stmt->fetchColumn();
  if ($uid <= 0) json_response(['ok' => false, 'error' => 'User not found'], 404);

  db()->prepare("
    INSERT INTO authority_users (authority_id, user_id, role)
    VALUES (:aid, :uid, 'member')
    ON DUPLICATE KEY UPDATE role=VALUES(role)
  ")->execute([
    ':aid' => $authorityId,
    ':uid' => $uid
  ]);
  json_response(['ok' => true]);
}

if ($action === 'remove_user') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok' => false, 'error' => 'Invalid id'], 400);
  db()->prepare("DELETE FROM authority_users WHERE id=:id")->execute([':id' => $id]);
  json_response(['ok' => true]);
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
