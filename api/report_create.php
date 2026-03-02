<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

start_secure_session();

$body = read_json_body();

$category = safe_str($body['category'] ?? null, 32);
$title    = safe_str($body['title'] ?? null, 120);
$desc     = safe_str($body['description'] ?? null, 5000);

$lat = $body['lat'] ?? null;
$lng = $body['lng'] ?? null;

$forceDuplicate = !empty($body['force_duplicate']); // bool

// === Bejelentő adatok (vendég esetén) ===
$reporterEmail = safe_str($body['reporter_email'] ?? null, 190);
$reporterName  = safe_str($body['reporter_name'] ?? null, 80);

// alapból anonim publikálás
$reporterIsAnonymous = array_key_exists('reporter_is_anonymous', $body)
  ? (!empty($body['reporter_is_anonymous']) ? 1 : 0)
  : 1;

// értesítés jelzés: fogadjuk el a régi és az új kulcsot is
$wantsNotify = null;
if (array_key_exists('notify_enabled', $body)) {
  $wantsNotify = !empty($body['notify_enabled']) ? 1 : 0;
} elseif (array_key_exists('notify', $body)) {
  $wantsNotify = !empty($body['notify']) ? 1 : 0;
}
if ($wantsNotify === null) $wantsNotify = 0;

// regisztráció a beküldéskor (vendégnél)
$createAccount = !empty($body['create_account']) ? 1 : 0;
$password = (string)($body['password'] ?? '');

// GDPR (vendégnél)
$consentData = !empty($body['consent_data']) ? 1 : 0;
$consentShare = array_key_exists('consent_share_thirdparty', $body)
  ? (!empty($body['consent_share_thirdparty']) ? 1 : 0)
  : 1;
$consentMarketing = !empty($body['consent_marketing']) ? 1 : 0;

// === Session user (ha belépett, akkor a regisztráció/GDPR már megtörtént) ===
$sessionUserId = null;
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
  $tmp = (int)$_SESSION['user_id'];
  if ($tmp > 0) $sessionUserId = $tmp;
}
if ($sessionUserId) {
  // belépett usernél ne próbáljunk "beküldéskor regisztrálni"
  $createAccount = 0;
}

// Validációk – kategória, leírás, koordináta
$allowedCats = ['road','sidewalk','lighting','trash','green','traffic','idea','civil_event'];
if (!$category || !in_array($category, $allowedCats, true)) {
  json_response(['ok' => false, 'error' => 'Invalid category'], 400);
}
if (!$desc) {
  json_response(['ok' => false, 'error' => 'Description required'], 400);
}
if (!is_numeric($lat) || !is_numeric($lng)) {
  json_response(['ok' => false, 'error' => 'Invalid coordinates'], 400);
}

$lat = (float)$lat;
$lng = (float)$lng;

// Orosháza környéke sanity check
if ($lat < 46.3 || $lat > 46.8 || $lng < 20.3 || $lng > 21.1) {
  json_response(['ok' => false, 'error' => 'Out of allowed area'], 400);
}

// Ha nem anonim -> legyen név (vendégnél kötelező; belépett usernél később a profilból töltjük)
if (!$sessionUserId && $reporterIsAnonymous === 0 && !$reporterName) {
  json_response(['ok' => false, 'error' => 'Name required if not anonymous'], 400);
}

// GDPR kötelező, ha vendég személyes adatot ad meg:
// - értesítést kér (email)
// - regisztrál (email+jelszó)
// - nem anonim (név publikus)
$needsPersonal = ($wantsNotify === 1) || ($createAccount === 1) || ($reporterIsAnonymous === 0);
if (!$sessionUserId && $needsPersonal && $consentData !== 1) {
  json_response(['ok' => false, 'error' => 'GDPR consent required'], 400);
}

// Email validáció, ha kell (értesítés vagy regisztráció) – vendégnél
if (!$sessionUserId && (($wantsNotify === 1) || ($createAccount === 1))) {
  if (!$reporterEmail || !filter_var($reporterEmail, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => 'Valid email required for notifications/registration'], 400);
  }
}

// === User session / regisztráció kezelése ===
$userId = $sessionUserId;
$userRole = $sessionUserId ? (current_user_role() ?: null) : null;

// ha regisztrálni akar a beküldéskor és nincs session
if ($createAccount === 1 && !$userId) {
  if (strlen($password) < 8) {
    json_response(['ok' => false, 'error' => 'Password must be at least 8 characters'], 400);
  }

  $emailLc = mb_strtolower($reporterEmail);

  // van már user?
  $stmt = db()->prepare("SELECT id, pass_hash FROM users WHERE email=:e LIMIT 1");
  $stmt->execute([':e' => $emailLc]);
  $u = $stmt->fetch();

  if ($u) {
    // ha létezik, jelszóval igazoljuk
    if (!password_verify($password, (string)$u['pass_hash'])) {
      json_response([
        'ok' => false,
        'error' => 'Ezzel az e-maillel már van fiók, de a jelszó nem egyezik. Inkább lépj be.'
      ], 400);
    }
    $userId = (int)$u['id'];
  } else {
    // új user létrehozása
    $hash = password_hash($password, PASSWORD_DEFAULT);

    try {
      $ins = db()->prepare("INSERT INTO users (email, pass_hash, display_name, role, is_verified, verify_token)
                            VALUES (:e,:h,:n,:role,1,NULL)");
      $ins->execute([
        ':e' => $emailLc,
        ':h' => $hash,
        ':n' => $reporterName,
        ':role' => 'user'
      ]);
      $userId = (int)db()->lastInsertId();
    } catch (Throwable $e) {
      json_response(['ok' => false, 'error' => 'User create failed'], 500);
    }
  }

  // session beállítás
  session_regenerate_id(true);
  $_SESSION['user_id'] = $userId;
  $_SESSION['user_role'] = 'user';
}

// Belépett user esetén: ha kell, töltsük ki a hiányzó mezőket a profilból
if ($userId) {
  $stmt = db()->prepare("SELECT email, display_name, role FROM users WHERE id=:id LIMIT 1");
  $stmt->execute([':id' => $userId]);
  $u = $stmt->fetch();

  $uEmail = $u ? safe_str($u['email'] ?? null, 190) : null;
  $uName  = $u ? safe_str($u['display_name'] ?? null, 80) : null;
  $uRole  = $u ? (string)($u['role'] ?? '') : '';
  if (!$userRole && $uRole !== '') {
    $userRole = $uRole;
    $_SESSION['user_role'] = $uRole;
  }

  // ha nem anonim és nem adott nevet, legyen a fiók neve
  if ($reporterIsAnonymous === 0 && !$reporterName) {
    $reporterName = $uName;
  }

  // ha értesítést kér, emailt a fiókból (különben maradhat NULL)
  if ($wantsNotify === 1 && !$reporterEmail) {
    $reporterEmail = $uEmail;
  }

  if ($reporterIsAnonymous === 0 && !$reporterName) {
    json_response(['ok' => false, 'error' => 'Name missing in profile (set display name)'], 400);
  }
  if ($wantsNotify === 1 && (!$reporterEmail || !filter_var($reporterEmail, FILTER_VALIDATE_EMAIL))) {
    json_response(['ok' => false, 'error' => 'Valid email missing in profile'], 400);
  }
}

// Civil kategória: csak civil/admin/superadmin használhatja
if ($category === 'civil_event') {
  if (!$userId) {
    json_response(['ok' => false, 'error' => 'Civil kategóriához bejelentkezés szükséges.'], 401);
  }
  $role = $userRole ?: (current_user_role() ?: '');
  if (!in_array($role, ['civil','admin','superadmin'], true)) {
    json_response(['ok' => false, 'error' => 'Nincs jogosultság a civil kategóriához.'], 403);
  }
}

// értesítés token
$notifyEnabled = 0;
$notifyToken = null;

if ($wantsNotify === 1) {
  $notifyEnabled = 1;

  try {
    $notifyToken = bin2hex(random_bytes(16));
  } catch (Throwable $e) {
    $bytes = function_exists('openssl_random_pseudo_bytes')
      ? openssl_random_pseudo_bytes(16)
      : null;

    if (!$bytes) {
      $notifyToken = hash('sha256', uniqid('tok', true) . microtime(true));
    } else {
      $notifyToken = bin2hex($bytes);
    }
  }
}

// IP + UA
$ip = client_ip();
$ipHash = $ip ? ip_hash($ip) : null;
$userAgent = safe_str($_SERVER['HTTP_USER_AGENT'] ?? null, 255);

// ===== RATE LIMIT (IP HASH) =====
if ($ipHash) {
  // 5 / 10 perc
  $stmt = db()->prepare("
    SELECT COUNT(*)
    FROM reports
    WHERE ip_hash = :ip
      AND created_at >= (NOW() - INTERVAL 10 MINUTE)
  ");
  $stmt->execute([':ip' => $ipHash]);
  $c10 = (int)$stmt->fetchColumn();

  if ($c10 >= 5) {
    json_response([
      'ok' => false,
      'error' => 'Túl sok bejelentés rövid idő alatt. Kérlek várj pár percet, majd próbáld újra.'
    ], 429);
  }

  // 20 / 24 óra
  $stmt = db()->prepare("
    SELECT COUNT(*)
    FROM reports
    WHERE ip_hash = :ip
      AND created_at >= (NOW() - INTERVAL 1 DAY)
  ");
  $stmt->execute([':ip' => $ipHash]);
  $c24 = (int)$stmt->fetchColumn();

  if ($c24 >= 20) {
    json_response([
      'ok' => false,
      'error' => 'Elérted a napi limitet. Kérlek próbáld holnap.'
    ], 429);
  }
}
// ===== /RATE LIMIT =====

// ===== DUPLIKÁCIÓ (50m) =====
if (!$forceDuplicate) {
  $radius = 50;

  $degLat = $radius / 111320.0;
  $degLng = $radius / (111320.0 * max(0.1, cos(deg2rad($lat))));

  $minLat = $lat - $degLat;
  $maxLat = $lat + $degLat;
  $minLng = $lng - $degLng;
  $maxLng = $lng + $degLng;

  $dupSql = "
    SELECT
      id, status,
      (6371000 * 2 * ASIN(SQRT(
        POWER(SIN(RADIANS(lat - :lat) / 2), 2) +
        COS(RADIANS(:lat)) * COS(RADIANS(lat)) *
        POWER(SIN(RADIANS(lng - :lng) / 2), 2)
      ))) AS distance_m
    FROM reports
    WHERE category = :cat
      AND status IN ('new','pending','approved','in_progress')
      AND lat BETWEEN :minLat AND :maxLat
      AND lng BETWEEN :minLng AND :maxLng
    HAVING distance_m <= :radius
    ORDER BY distance_m ASC
    LIMIT 1
  ";

  $dupStmt = db()->prepare($dupSql);
  $dupStmt->execute([
    ':lat' => $lat,
    ':lng' => $lng,
    ':cat' => $category,
    ':minLat' => $minLat,
    ':maxLat' => $maxLat,
    ':minLng' => $minLng,
    ':maxLng' => $maxLng,
    ':radius' => $radius
  ]);

  $dup = $dupStmt->fetch();
  if ($dup) {
    json_response([
      'ok' => false,
      'error' => 'Van már ilyen bejelentés 50 méteren belül.',
      'duplicate' => [
        'id' => (int)$dup['id'],
        'status' => (string)$dup['status'],
        'distance_m' => (float)$dup['distance_m']
      ]
    ], 409);
  }
}

// Reverse geocode (best effort)
$geo = nominatim_reverse($lat, $lng);

$addressApprox = null;
$houseNumber = null;
$road = null;
$suburb = null;
$city = null;
$postcode = null;

if ($geo) {
  $addressApprox = safe_str($geo['display_name'] ?? null, 255);
  $addr = $geo['address'] ?? [];
  if (is_array($addr)) {
    $houseNumber = safe_str($addr['house_number'] ?? null, 64);
    $road        = safe_str($addr['road'] ?? null, 128);
    $suburb      = safe_str($addr['suburb'] ?? ($addr['neighbourhood'] ?? null), 128);
    $city        = safe_str($addr['city'] ?? ($addr['town'] ?? ($addr['village'] ?? null)), 128);
    $postcode    = safe_str($addr['postcode'] ?? null, 32);
  }
}

// email csak akkor kerüljön eltárolásra, ha értesítést kér (különben NULL)
$storeEmail = ($wantsNotify === 1) ? $reporterEmail : null;

$stmt = db()->prepare("
  INSERT INTO reports
    (category, title, description, lat, lng,
     address_approx, house_number_approx, road, suburb, city, postcode,
     status, ip_hash, user_agent,
     user_id, reporter_email, reporter_name, reporter_is_anonymous,
     notify_token, notify_enabled)
  VALUES
    (:category, :title, :description, :lat, :lng,
     :address_approx, :house_number, :road, :suburb, :city, :postcode,
     'new', :ip_hash, :user_agent,
     :user_id, :reporter_email, :reporter_name, :reporter_is_anonymous,
     :notify_token, :notify_enabled)
");
$stmt->execute([
  ':category' => $category,
  ':title' => $title,
  ':description' => $desc,
  ':lat' => $lat,
  ':lng' => $lng,
  ':address_approx' => $addressApprox,
  ':house_number' => $houseNumber,
  ':road' => $road,
  ':suburb' => $suburb,
  ':city' => $city,
  ':postcode' => $postcode,
  ':ip_hash' => $ipHash,
  ':user_agent' => $userAgent,
  ':user_id' => $userId,
  ':reporter_email' => $storeEmail,
  ':reporter_name' => $reporterName,
  ':reporter_is_anonymous' => $reporterIsAnonymous,
  ':notify_token' => $notifyToken,
  ':notify_enabled' => $notifyEnabled,
]);

$id = (int)db()->lastInsertId();

json_response([
  'ok' => true,
  'message' => 'Köszönjük! A bejelentés ellenőrzés után fog megjelenni a térképen.',
  'id' => $id
]);
