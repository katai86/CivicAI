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
    foreach ($auth as &$a) {
      if (!array_key_exists('contact_email', $a) && array_key_exists('email', $a)) $a['contact_email'] = $a['email'];
      if (!array_key_exists('is_active', $a) && array_key_exists('active', $a)) $a['is_active'] = $a['active'];
    }
    unset($a);
  } catch (Throwable $e) {
    log_error('admin_authorities auth: ' . $e->getMessage());
    $auth = [];
  }
  try {
    $contacts = db()->query("SELECT * FROM authority_contacts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    log_error('admin_authorities contacts: ' . $e->getMessage());
    $contacts = [];
  }
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
    $assign = [];
  }
  json_response(['ok' => true, 'authorities' => $auth, 'contacts' => $contacts, 'assignments' => $assign]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
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

  if (!$name) json_response(['ok' => false, 'error' => t('api.name_required')], 400);

  $pdo = db();
  $inserted = false;
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
    $inserted = true;
  } catch (Throwable $e) {
    log_error('admin_authorities create (with bbox): ' . $e->getMessage());
    try {
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
      $inserted = true;
    } catch (Throwable $e2) {
      log_error('admin_authorities create (no bbox): ' . $e2->getMessage());
      try {
        // Régi schema: name, email (NOT NULL), category, city, active, min_lat, max_lat, min_lng, max_lng
        $pdo->prepare("
          INSERT INTO authorities (name, email, category, city, active, min_lat, max_lat, min_lng, max_lng)
          VALUES (:name, :email, :category, :city, :active, :minlat, :maxlat, :minlng, :maxlng)
        ")->execute([
          ':name' => $name,
          ':email' => $email ?: ' ',
          ':category' => '',
          ':city' => $city,
          ':active' => $isActive,
          ':minlat' => $minLat,
          ':maxlat' => $maxLat,
          ':minlng' => $minLng,
          ':maxlng' => $maxLng,
        ]);
        $inserted = true;
      } catch (Throwable $e3) {
        log_error('admin_authorities create (legacy): ' . $e3->getMessage());
        json_response(['ok' => false, 'error' => t('api.authority_save_failed')], 500);
      }
    }
  }
  if ($inserted) {
    json_response(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
  }
}

if ($action === 'update_authority') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
  $name = safe_str($body['name'] ?? null, 160);
  $city = safe_str($body['city'] ?? null, 80);
  $address = safe_str($body['address'] ?? null, 255);
  $email = safe_str($body['contact_email'] ?? null, 190);
  $phone = safe_str($body['contact_phone'] ?? null, 40);
  $minLat = array_key_exists('min_lat', $body) ? (is_numeric($body['min_lat']) ? (float)$body['min_lat'] : null) : null;
  $maxLat = array_key_exists('max_lat', $body) ? (is_numeric($body['max_lat']) ? (float)$body['max_lat'] : null) : null;
  $minLng = array_key_exists('min_lng', $body) ? (is_numeric($body['min_lng']) ? (float)$body['min_lng'] : null) : null;
  $maxLng = array_key_exists('max_lng', $body) ? (is_numeric($body['max_lng']) ? (float)$body['max_lng'] : null) : null;
  try {
    $pdo = db();
    $pdo->prepare("UPDATE authorities SET name = ?, city = ?, contact_email = ?, contact_phone = ?, min_lat = ?, max_lat = ?, min_lng = ?, max_lng = ? WHERE id = ?")
      ->execute([$name, $city, $email, $phone, $minLat, $maxLat, $minLng, $maxLng, $id]);
    if ($address !== null && $address !== '') {
      try {
        $pdo->prepare("UPDATE authorities SET address = ? WHERE id = ?")->execute([$address, $id]);
      } catch (Throwable $e) { /* address oszlop opcionális */ }
    }
    json_response(['ok' => true]);
  } catch (Throwable $e) {
    if (strpos($e->getMessage(), 'Unknown column') !== false) {
      json_response(['ok' => false, 'error' => 'authorities táblában hiányzó oszlop. Futtasd a migrációt.'], 400);
    }
    log_error('admin_authorities update_authority: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => t('api.authority_save_failed')], 500);
  }
}

if ($action === 'delete_authority') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
  try {
    db()->prepare("DELETE FROM authority_contacts WHERE authority_id=:id")->execute([':id' => $id]);
  } catch (Throwable $e) { /* tábla hiányozhat */ }
  try {
    db()->prepare("DELETE FROM authority_users WHERE authority_id=:id")->execute([':id' => $id]);
  } catch (Throwable $e) { /* tábla hiányozhat */ }
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
    json_response(['ok' => false, 'error' => t('api.invalid_data')], 400);
  }
  try {
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
  } catch (Throwable $e) {
    log_error('admin_authorities create_contact: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => t('api.authority_contacts_missing')], 503);
  }
}

if ($action === 'delete_contact') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
  try {
    db()->prepare("DELETE FROM authority_contacts WHERE id=:id")->execute([':id' => $id]);
    json_response(['ok' => true]);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'error' => t('api.authority_contacts_missing')], 503);
  }
}

if ($action === 'assign_user') {
  $authorityId = (int)($body['authority_id'] ?? 0);
  $email = safe_str($body['email'] ?? null, 190);
  if ($authorityId <= 0 || !$email) {
    json_response(['ok' => false, 'error' => t('api.invalid_data')], 400);
  }
  $stmt = db()->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
  $stmt->execute([':email' => $email]);
  $uid = (int)$stmt->fetchColumn();
  if ($uid <= 0) json_response(['ok' => false, 'error' => t('api.user_not_found')], 404);

  try {
    db()->prepare("
      INSERT INTO authority_users (authority_id, user_id, role)
      VALUES (:aid, :uid, 'member')
      ON DUPLICATE KEY UPDATE role=VALUES(role)
    ")->execute([
      ':aid' => $authorityId,
      ':uid' => $uid
    ]);
    json_response(['ok' => true]);
  } catch (Throwable $e) {
    log_error('admin_authorities assign_user: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => t('api.authority_users_missing')], 503);
  }
}

if ($action === 'remove_user') {
  $id = (int)($body['id'] ?? 0);
  if ($id <= 0) json_response(['ok' => false, 'error' => t('api.invalid_id')], 400);
  try {
    db()->prepare("DELETE FROM authority_users WHERE id=:id")->execute([':id' => $id]);
    json_response(['ok' => true]);
  } catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'Az authority_users tábla hiányozhat.'], 503);
  }
}

json_response(['ok' => false, 'error' => 'Unknown action'], 400);
