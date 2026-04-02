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

// Saját Open311 API jurisdiction_id (multi-city; opcionális – ha üres, discovery nem adja vissza)
define('APP_JURISDICTION_ID', trim((string)(getenv('APP_JURISDICTION_ID') ?: (defined('FMS_OPEN311_JURISDICTION') ? FMS_OPEN311_JURISDICTION : ''))));

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

// --- AI / LLM beállítások (Civic Green Intelligence) ---
define('AI_ENABLED', filter_var(getenv('AI_ENABLED'), FILTER_VALIDATE_BOOLEAN) ?: false);
define('AI_PROVIDER', getenv('AI_PROVIDER') ?: 'mistral'); // mistral | gemini
define('AI_PROVIDER_VISION', getenv('AI_PROVIDER_VISION') ?: 'mistral');

define('MISTRAL_API_KEY', getenv('MISTRAL_API_KEY') ?: '');
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');
define('OPENAI_API_KEY', getenv('OPENAI_API_KEY') ?: '');
define('OPENAI_MODEL', getenv('OPENAI_MODEL') ?: 'gpt-4o-mini');

define('AI_TEXT_MODEL', getenv('AI_TEXT_MODEL') ?: 'mistral-small-2506');
define('AI_VISION_MODEL', getenv('AI_VISION_MODEL') ?: 'mistral-small-2506');
define('AI_PREMIUM_MODEL', getenv('AI_PREMIUM_MODEL') ?: 'mistral-large-2512');

// --- Model routing (task → modell) – ne egy modell mindenre ---
// 1) Kép / fa felismerés / közterületi objektumok: vision modell (primary + optional fallback)
define('AI_MODEL_VISION', getenv('AI_MODEL_VISION') ?: (defined('AI_VISION_MODEL') ? AI_VISION_MODEL : 'pixtral-12b-2409'));
define('AI_MODEL_VISION_FALLBACK', getenv('AI_MODEL_VISION_FALLBACK') ?: 'pixtral-12b-2409');
// 2) Rövid szöveges kategorizálás: Mistral Small 3.2
define('AI_MODEL_SMALL', getenv('AI_MODEL_SMALL') ?: (defined('AI_TEXT_MODEL') ? AI_TEXT_MODEL : 'mistral-small-2506'));
// 3) Összefoglalók / ESG / vezetői riport: Mistral Large 3 (optional fallback: Medium)
define('AI_MODEL_LARGE', getenv('AI_MODEL_LARGE') ?: (defined('AI_PREMIUM_MODEL') ? AI_PREMIUM_MODEL : 'mistral-large-2512'));
define('AI_MODEL_LARGE_FALLBACK', getenv('AI_MODEL_LARGE_FALLBACK') ?: 'mistral-medium-2310');
// 4) Lakossági AI asszisztens: Mistral Medium 3.1 (premium fallback: Large)
define('AI_MODEL_MEDIUM', getenv('AI_MODEL_MEDIUM') ?: 'mistral-medium-2310');
define('AI_MODEL_MEDIUM_FALLBACK', getenv('AI_MODEL_MEDIUM_FALLBACK') ?: (defined('AI_MODEL_LARGE') ? AI_MODEL_LARGE : 'mistral-large-2512'));
// 5) Dokumentum OCR / mellékletek (ahol releváns)
define('AI_MODEL_OCR', getenv('AI_MODEL_OCR') ?: '');

// AI költségkontroll
define('AI_MAX_REPORTS_PER_DAY', (int)(getenv('AI_MAX_REPORTS_PER_DAY') ?: 1000));
define('AI_SUMMARY_LIMIT', (int)(getenv('AI_SUMMARY_LIMIT') ?: 20));
define('AI_IMAGE_ANALYSIS_LIMIT', (int)(getenv('AI_IMAGE_ANALYSIS_LIMIT') ?: 300));

// Időjárás API (Gov dashboard) – Open-Meteo használata, API kulcs nem kell
define('WEATHER_ENABLED', filter_var(getenv('WEATHER_ENABLED'), FILTER_VALIDATE_BOOLEAN) ?: true);

// --- EU admin subdivisions (district / borough / arrondissement / sublocality / ward, provider-first) ---
// Ha true: minden ország/város számára érvényes (analytics + UI relevancia szűrőkhez).
define('SUBDIVISION_AWARE_DEFAULT', filter_var(getenv('SUBDIVISION_AWARE_DEFAULT'), FILTER_VALIDATE_BOOLEAN) ?: false);
// Vesszővel: ISO-3166-1 alpha-2 (pl. HU,DE,FR,AT).
define('SUBDIVISION_AWARE_COUNTRY_CODES', strtoupper(preg_replace('/\s+/', '', (string)(getenv('SUBDIVISION_AWARE_COUNTRY_CODES') ?: ''))));
// Opcionális városlista (kisbetű, vesszővel) – pl. budapest,wien,berlin
define('SUBDIVISION_AWARE_CITIES', strtolower(preg_replace('/\s+/', '', (string)(getenv('SUBDIVISION_AWARE_CITIES') ?: ''))));
// Analytics: JSON subcity_name szerinti bontás (ha hatóságon subdivision_aware vagy ez true)
define('SUBDIVISION_ANALYTICS_USE_SUBCITY', filter_var(getenv('SUBDIVISION_ANALYTICS_USE_SUBCITY'), FILTER_VALIDATE_BOOLEAN) ?: false);
// Beküldő JSON admin_subdivision egyesítése (TomTom/HERE/Google/Geoapify kliens oldali válaszból)
define('SUBDIVISION_ALLOW_CLIENT_SNAPSHOT', filter_var(getenv('SUBDIVISION_ALLOW_CLIENT_SNAPSHOT'), FILTER_VALIDATE_BOOLEAN) ?: false);

// Production bootstrap: kritikus beállítások ellenőrzése (nem blokkol, csak jelzés)
if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('APP_BASE_URL')) {
  throw new RuntimeException('Kritikus config hiányzik: DB_HOST, DB_NAME vagy APP_BASE_URL.');
}
$baseUrl = (string)(defined('APP_BASE_URL') ? APP_BASE_URL : '');
if ($baseUrl === '' || strpos($baseUrl, 'example.com') !== false) {
  define('CONFIG_NEEDS_REVIEW', true); // Health check / üzemeltetés figyelje
}
