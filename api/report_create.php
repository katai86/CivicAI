<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  json_response(['ok' => false, 'error' => t('api.method_not_allowed')], 405);
}

start_secure_session();

// Bejelentés csak bejelentkezett felhasználótól (anonim = név rejtése a térképen, nem vendég beküldés)
if (empty($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
  json_response(['ok' => false, 'error' => t('api.report_requires_login')], 401);
}

$body = read_json_body();

$category = safe_str($body['category'] ?? null, 32);
$title    = safe_str($body['title'] ?? null, 120);
$desc     = safe_str($body['description'] ?? null, 5000);
$descLen  = $desc ? (function_exists('mb_strlen') ? mb_strlen($desc) : strlen($desc)) : 0;

$addrZip = safe_str($body['address_zip'] ?? null, 16);
$addrCity = safe_str($body['address_city'] ?? null, 80);
$addrStreet = safe_str($body['address_street'] ?? null, 120);
$addrHouse = safe_str($body['address_house'] ?? null, 20);
$addrNote = safe_str($body['address_note'] ?? null, 160);

$latRaw = $body['lat'] ?? null;
$lngRaw = $body['lng'] ?? null;
$lat = $latRaw;
$lng = $lngRaw;

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
  json_response(['ok' => false, 'error' => t('api.invalid_category')], 400);
}
if (!$desc) {
  json_response(['ok' => false, 'error' => t('api.description_required')], 400);
}
if (!is_numeric($lat) || !is_numeric($lng)) {
  json_response(['ok' => false, 'error' => t('api.invalid_coordinates')], 400);
}

$lat = (float)$lat;
$lng = (float)$lng;

$GLOBALS['__REPORT_LAT'] = $lat;
$GLOBALS['__REPORT_LNG'] = $lng;

// Nincs területi korlát (országos/európai mód)

// Ha nem anonim -> legyen név (vendégnél kötelező; belépett usernél később a profilból töltjük)
if (!$sessionUserId && $reporterIsAnonymous === 0 && !$reporterName) {
  json_response(['ok' => false, 'error' => t('api.name_required_not_anon')], 400);
}

// GDPR kötelező, ha vendég személyes adatot ad meg:
// - értesítést kér (email)
// - regisztrál (email+jelszó)
// - nem anonim (név publikus)
$needsPersonal = ($wantsNotify === 1) || ($createAccount === 1) || ($reporterIsAnonymous === 0);
if (!$sessionUserId && $needsPersonal && $consentData !== 1) {
  json_response(['ok' => false, 'error' => t('api.gdpr_consent_required')], 400);
}

// Email validáció, ha kell (értesítés vagy regisztráció) – vendégnél
if (!$sessionUserId && (($wantsNotify === 1) || ($createAccount === 1))) {
  if (!$reporterEmail || !filter_var($reporterEmail, FILTER_VALIDATE_EMAIL)) {
    json_response(['ok' => false, 'error' => t('api.valid_email_required')], 400);
  }
}

// === User session / regisztráció kezelése ===
$userId = $sessionUserId;
$userRole = $sessionUserId ? (current_user_role() ?: null) : null;
$prevReportCount = 0;

// ha regisztrálni akar a beküldéskor és nincs session
if ($createAccount === 1 && !$userId) {
  if (strlen($password) < 8) {
    json_response(['ok' => false, 'error' => t('api.password_min_8')], 400);
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
        'error' => t('api.email_exists_wrong_password')
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
      json_response(['ok' => false, 'error' => t('api.user_create_failed')], 500);
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
    json_response(['ok' => false, 'error' => t('api.name_missing_profile')], 400);
  }
  if ($wantsNotify === 1 && (!$reporterEmail || !filter_var($reporterEmail, FILTER_VALIDATE_EMAIL))) {
    json_response(['ok' => false, 'error' => t('api.valid_email_missing')], 400);
  }
}

// Role-alapú jogosultságok
if ($category === 'civil_event') {
  if (!$userId) {
    json_response(['ok' => false, 'error' => t('auth.login_required')], 401);
  }
  $role = $userRole ?: (current_user_role() ?: '');
  if (!in_array($role, ['civil','civiluser','admin','superadmin'], true)) {
    json_response(['ok' => false, 'error' => t('api.report_no_permission_civil')], 403);
  }
} else {
  // Normál bejelentés (út, szemét stb.): civil és community user nem jogosult – csak profil / civil esemény / közület buborék.
  if ($userId) {
    $role = $userRole ?: (current_user_role() ?: '');
    if (in_array($role, ['civil','civiluser','communityuser'], true)) {
      json_response(['ok' => false, 'error' => t('api.report_no_permission_account')], 403);
    }
  }
}

// Előző bejelentés-szám (first report XP-hez)
if ($userId) {
  $stmt = db()->prepare("SELECT COUNT(*) FROM reports WHERE user_id = :uid");
  $stmt->execute([':uid' => $userId]);
  $prevReportCount = (int)$stmt->fetchColumn();
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
      'error' => t('api.rate_limit_wait')
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
      'error' => t('api.daily_limit_reached')
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

$clientAdminSnap = (isset($body['admin_subdivision']) && is_array($body['admin_subdivision'])) ? $body['admin_subdivision'] : null;
$rawGeoForAdmin = null;
if (!empty($body['admin_geocode_provider']) && isset($body['admin_geocode_raw']) && is_array($body['admin_geocode_raw'])) {
  $rawGeoForAdmin = [
    'provider' => (string)$body['admin_geocode_provider'],
    'raw' => $body['admin_geocode_raw'],
  ];
}
$adminNorm = admin_subdivision_build_for_report($geo, $lat, $lng, $clientAdminSnap, $rawGeoForAdmin);
if ($suburb === null || $suburb === '') {
  $sn = isset($adminNorm['subcity_name']) ? trim((string)$adminNorm['subcity_name']) : '';
  if ($sn !== '') {
    $suburb = safe_str($sn, 128);
  }
}
if ($city === null || $city === '') {
  $cn = isset($adminNorm['city']) ? trim((string)$adminNorm['city']) : '';
  if ($cn !== '') {
    $city = safe_str($cn, 128);
  }
}
if ($postcode === null || $postcode === '') {
  $pc = isset($adminNorm['postcode']) ? trim((string)$adminNorm['postcode']) : '';
  if ($pc !== '') {
    $postcode = safe_str($pc, 32);
  }
}

// email csak akkor kerüljön eltárolásra, ha értesítést kér (különben NULL)
$storeEmail = ($wantsNotify === 1) ? $reporterEmail : null;

$authorityId = find_authority_for_report($city ?: $addrCity, $category);
$serviceCode = $category;

$baseInsert = "
  INSERT INTO reports
    (category, title, description, lat, lng,
     address_approx, house_number_approx, road, suburb, city, postcode,
     status, ip_hash, user_agent,
     user_id, reporter_email, reporter_name, reporter_is_anonymous,
     notify_token, notify_enabled,
     authority_id, service_code)
  VALUES
    (:category, :title, :description, :lat, :lng,
     :address_approx, :house_number, :road, :suburb, :city, :postcode,
     'new', :ip_hash, :user_agent,
     :user_id, :reporter_email, :reporter_name, :reporter_is_anonymous,
     :notify_token, :notify_enabled,
     :authority_id, :service_code)
";

$baseParams = [
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
  ':authority_id' => $authorityId,
  ':service_code' => $serviceCode,
];

try {
  $stmt = db()->prepare("
    INSERT INTO reports
      (category, title, description, lat, lng,
       address_approx, house_number_approx, road, suburb, city, postcode,
       address_zip_manual, address_city_manual, address_street_manual, address_house_manual, address_note_manual,
       status, ip_hash, user_agent,
       user_id, reporter_email, reporter_name, reporter_is_anonymous,
       notify_token, notify_enabled,
       authority_id, service_code)
    VALUES
      (:category, :title, :description, :lat, :lng,
       :address_approx, :house_number, :road, :suburb, :city, :postcode,
       :addr_zip, :addr_city, :addr_street, :addr_house, :addr_note,
       'new', :ip_hash, :user_agent,
       :user_id, :reporter_email, :reporter_name, :reporter_is_anonymous,
       :notify_token, :notify_enabled,
       :authority_id, :service_code)
  ");
  $stmt->execute(array_merge($baseParams, [
    ':addr_zip' => $addrZip,
    ':addr_city' => $addrCity,
    ':addr_street' => $addrStreet,
    ':addr_house' => $addrHouse,
    ':addr_note' => $addrNote,
  ]));
} catch (Throwable $e) {
  log_error('report_create INSERT (manual cols): ' . $e->getMessage());
  try {
    $stmt = db()->prepare($baseInsert);
    $stmt->execute($baseParams);
  } catch (Throwable $e2) {
    log_error('report_create INSERT (base): ' . $e2->getMessage());
    try {
      // Régi schema: nincs service_code oszlop, csak authority_id
      $stmt = db()->prepare("
        INSERT INTO reports
          (category, title, description, lat, lng,
           address_approx, house_number_approx, road, suburb, city, postcode,
           status, ip_hash, user_agent,
           user_id, reporter_email, reporter_name, reporter_is_anonymous,
           notify_token, notify_enabled, authority_id)
        VALUES
          (:category, :title, :description, :lat, :lng,
           :address_approx, :house_number, :road, :suburb, :city, :postcode,
           'new', :ip_hash, :user_agent,
           :user_id, :reporter_email, :reporter_name, :reporter_is_anonymous,
           :notify_token, :notify_enabled, :authority_id)
      ");
      $stmt->execute($baseParams);
    } catch (Throwable $e3) {
      log_error('report_create INSERT (with authority_id): ' . $e3->getMessage());
      try {
        $minSql = "INSERT INTO reports (category, title, description, lat, lng, status, user_id, reporter_email, reporter_name, reporter_is_anonymous, notify_token, notify_enabled)
                   VALUES (:category, :title, :description, :lat, :lng, 'new', :user_id, :reporter_email, :reporter_name, :reporter_is_anonymous, :notify_token, :notify_enabled)";
        db()->prepare($minSql)->execute([
          ':category' => $category, ':title' => $title, ':description' => $desc, ':lat' => $lat, ':lng' => $lng,
          ':user_id' => $userId, ':reporter_email' => $storeEmail, ':reporter_name' => $reporterName,
          ':reporter_is_anonymous' => $reporterIsAnonymous, ':notify_token' => $notifyToken, ':notify_enabled' => $notifyEnabled,
        ]);
      } catch (Throwable $e4) {
        log_error('report_create INSERT (minimal): ' . $e4->getMessage());
        json_response(['ok' => false, 'error' => t('api.report_save_failed')], 500);
      }
    }
  }
}

$id = (int)db()->lastInsertId();

if ($id > 0 && isset($adminNorm) && is_array($adminNorm)) {
  try {
    $j = admin_subdivision_to_json($adminNorm);
    if ($j !== '' && $j !== '{}') {
      db()->prepare('UPDATE reports SET admin_subdivision_json = :j WHERE id = :id')->execute([':j' => $j, ':id' => $id]);
    }
  } catch (Throwable $e) {
    log_error('report_create admin_subdivision_json: ' . $e->getMessage());
  }
}

// ===== /FixMyStreet =====

// ===== AI – Report understanding (tanácsadó, opcionális) =====
if ($id > 0 && defined('AI_ENABLED') && AI_ENABLED) {
  try {
    $router = new AiRouter();
    if ($router->isEnabled()) {
      $outputLang = function_exists('current_lang') ? current_lang() : 'hu';
      $prompt = AiPromptBuilder::reportUnderstanding((string)$title, (string)$desc, (string)$category, $outputLang);
      $inputHash = hash('sha256', $category . '|' . (string)$title . '|' . (string)$desc);
      $res = $router->callJson('report_classification', $prompt, []);
      if (!empty($res['ok']) && isset($res['data']) && is_array($res['data'])) {
        $norm = AiResultParser::normalizeReportUnderstanding($res['data']);
        $confidence = null;
        if (isset($norm['confidence_score']) && is_numeric($norm['confidence_score'])) {
          $c = (float)$norm['confidence_score'];
          if ($c >= 0 && $c <= 1.5) {
            $confidence = $c;
          }
        }
        $modelName = isset($res['model']) ? (string)$res['model'] : (defined('AI_TEXT_MODEL') ? (string)AI_TEXT_MODEL : '');
        ai_store_result('report', $id, 'report_classification', $modelName, $inputHash, $norm, $confidence);

        // Jelzésként próbáljuk eltárolni a reports.ai_category / ai_priority mezőkbe is (ha léteznek).
        if (!empty($norm['suggested_category']) || !empty($norm['urgency_level'])) {
          try {
            $stmtAi = db()->prepare("UPDATE reports SET ai_category = :cat, ai_priority = :prio WHERE id = :id");
            $stmtAi->execute([
              ':cat' => isset($norm['suggested_category']) ? (string)$norm['suggested_category'] : null,
              ':prio' => isset($norm['urgency_level']) ? (string)$norm['urgency_level'] : null,
              ':id' => $id,
            ]);
          } catch (Throwable $eAi) {
            // ha a mezők nem léteznek, hagyjuk figyelmen kívül
            log_error('report_create AI update failed: ' . $eAi->getMessage());
          }
        }
      }
    }
  } catch (Throwable $e) {
    log_error('report_create AI error: ' . $e->getMessage());
    // AI hiba nem törheti el a mentést
  }
}
// ===== /AI – Report understanding =====

// ===== XP + badge rendszer (best-effort) =====
if ($userId) {
  $points = 15; // bejelentes letrehozasa
  if ($prevReportCount === 0) $points += 20; // elso bejelentes
  if ($descLen >= 200) $points += 10; // reszletes leiras
  if (gps_is_precise($latRaw) && gps_is_precise($lngRaw)) $points += 5; // pontos GPS (heurisztika)

  $catBonus = [
    'road' => 5,
    'trash' => 10,
    'lighting' => 10,
    'traffic' => 15,
  ];
  if (isset($catBonus[$category])) $points += (int)$catBonus[$category];

  add_user_xp($userId, $points, 'report_create', $id);

  $weekKey = 'week3:' . date('o-W');
  $monthKey = 'month10:' . date('Y-m');
  try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM reports WHERE user_id = :uid AND created_at >= (NOW() - INTERVAL 7 DAY)");
    $stmt->execute([':uid' => $userId]);
    $c7 = (int)$stmt->fetchColumn();
    if ($c7 >= 3) add_user_xp_once($userId, 15, $weekKey, 'bonus_week3', $id);
  } catch (Throwable $e) { /* ignore */ }

  try {
    $stmt = db()->prepare("SELECT COUNT(*) FROM reports WHERE user_id = :uid AND created_at >= (NOW() - INTERVAL 1 MONTH)");
    $stmt->execute([':uid' => $userId]);
    $c30 = (int)$stmt->fetchColumn();
    if ($c30 >= 10) add_user_xp_once($userId, 50, $monthKey, 'bonus_month10', $id);
  } catch (Throwable $e) { /* ignore */ }

  $streak = update_user_streak($userId);
  if ($streak >= 30) {
    $streakKey = 'streak30:' . date('Y-m-d');
    add_user_xp_once($userId, 100, $streakKey, 'bonus_streak30', $id);
  }

  check_category_badges($userId, $category);
  check_description_badge($userId, $descLen);
  check_gps_badge($userId, gps_is_precise($latRaw) && gps_is_precise($lngRaw));
}
// ===== /XP + badge =====

json_response([
  'ok' => true,
  'message' => t('modal.thanks'),
  'id' => $id,
  'admin_subdivision' => isset($adminNorm) && is_array($adminNorm) ? $adminNorm : null,
]);
