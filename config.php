<?php
// ========= Alap beállítások =========

// Error log (ide íródnak a PHP/DB hibák – segít a 500-asoknál)
define('ERROR_LOG_FILE', __DIR__ . '/error.log');

// --- DB ---
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'kataia_civicai');
define('DB_USER', getenv('DB_USER') ?: 'kataia_civicai');
define('DB_PASS', getenv('DB_PASS') ?: '');

// --- Admin login (MVP) ---
// Később cseréljük rendes felhasználó táblára + hash-re.
define('ADMIN_USER', getenv('ADMIN_USER') ?: 'admin');
define('ADMIN_PASS', getenv('ADMIN_PASS') ?: 'admin');

// --- IP hash só (privacy) ---
define('IP_HASH_SALT', getenv('IP_HASH_SALT') ?: 'change_me');

// --- Nominatim (reverse geocode) ---
define('NOMINATIM_BASE', 'https://nominatim.openstreetmap.org');
define('NOMINATIM_USER_AGENT', 'Problematerkep/1.0 (contact: hello@kataiattila.hu)');

// FONTOS: állítsd be a tényleges alap URL-t (https + teljes domain + /terkep)
define('APP_BASE_URL', getenv('APP_BASE_URL') ?: 'https://example.com/terkep');

// Térkép kezdeti nézet (Phase 5 – multi-city: városonként más érték lehet)
// Pl. Orosháza: MAP_CENTER_LAT=46.565 MAP_CENTER_LNG=20.667 MAP_ZOOM=13
define('MAP_CENTER_LAT', is_numeric(getenv('MAP_CENTER_LAT')) ? (float)getenv('MAP_CENTER_LAT') : 47.1625);
define('MAP_CENTER_LNG', is_numeric(getenv('MAP_CENTER_LNG')) ? (float)getenv('MAP_CENTER_LNG') : 19.5033);
define('MAP_ZOOM', (int)(getenv('MAP_ZOOM') ?: 7));

// Session cookie beállítások
define('SESSION_NAME', 'terkep_sess');

// Admin token (ha még használod a régi tokenes védelmet is)
define('ADMIN_TOKEN', getenv('ADMIN_TOKEN') ?: '');

// Önkormányzati (govuser) regisztráció: csak akkor engedélyezett, ha a superadmin bekapcsolja.
// Ha false: a regisztrációs űrlapon a govuser opció nem választható / elutasítjuk.
define('GOV_REGISTRATION_ENABLED', filter_var(getenv('GOV_REGISTRATION_ENABLED'), FILTER_VALIDATE_BOOLEAN) ?: false);

// E-mail küldés
define('MAIL_FROM', getenv('MAIL_FROM') ?: 'no-reply@example.com');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Problematérkép');

// FixMyStreet Open311 bridge (optional)
define('FMS_OPEN311_BASE', getenv('FMS_OPEN311_BASE') ?: '');
define('FMS_OPEN311_JURISDICTION', getenv('FMS_OPEN311_JURISDICTION') ?: '');
define('FMS_OPEN311_API_KEY', getenv('FMS_OPEN311_API_KEY') ?: '');

// Upload
define('UPLOAD_DIR', __DIR__ . '/uploads'); // fájlrendszerben
define('UPLOAD_PUBLIC', APP_BASE_URL . '/uploads'); // böngészőben

// Engedélyezett feltöltés
define('UPLOAD_MAX_BYTES', 6 * 1024 * 1024); // 6 MB
define('UPLOAD_ALLOWED_MIME', [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
]);

// Production bootstrap: kritikus beállítások ellenőrzése (nem blokkol, csak jelzés)
if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('APP_BASE_URL')) {
  throw new RuntimeException('Kritikus config hiányzik: DB_HOST, DB_NAME vagy APP_BASE_URL.');
}
$baseUrl = (string)(defined('APP_BASE_URL') ? APP_BASE_URL : '');
if ($baseUrl === '' || strpos($baseUrl, 'example.com') !== false) {
  define('CONFIG_NEEDS_REVIEW', true); // Health check / üzemeltetés figyelje
}
