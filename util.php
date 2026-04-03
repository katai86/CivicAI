<?php
// Common helpers + security/session bootstrap

require_once __DIR__ . '/config.php';

// --------------------
// Error handling (log, and return JSON for /api/ requests)
// --------------------

function is_api_request(): bool {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($uri, '/api/') !== false) return true;

    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($accept, 'application/json') !== false) return true;

    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    if (strcasecmp($xhr, 'XMLHttpRequest') === 0) return true;

    return false;
}

function log_error(string $message): void {
    if (!defined('ERROR_LOG_FILE')) {
        error_log($message);
        return;
    }
    @file_put_contents(ERROR_LOG_FILE, '[' . date('c') . '] ' . $message . "\n", FILE_APPEND);
}

function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * M10 – Short-lived JSON file cache for heavy gov GET APIs (per role, user, admin authority scope).
 */
function gov_api_cache_scope_key(string $prefix, string $role, int $uid, ?int $adminAuthorityId): string {
    $a = ($adminAuthorityId !== null && $adminAuthorityId > 0) ? (string)$adminAuthorityId : 'all';

    return $prefix . '_' . sha1($role . '|' . $uid . '|' . $a);
}

function gov_api_cache_dir(): string {
    $dir = __DIR__ . '/data/gov_api_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        return $dir;
    }
    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'civicai_gov_api_cache';
    if (!is_dir($fallback)) {
        @mkdir($fallback, 0755, true);
    }

    return is_dir($fallback) ? $fallback : sys_get_temp_dir();
}

/** @return array<string,mixed>|null Full JSON response shape e.g. ['ok'=>true,'data'=>…] */
function gov_api_cache_get(string $cacheKey): ?array {
    if (!defined('GOV_API_CACHE_TTL_SECONDS') || GOV_API_CACHE_TTL_SECONDS <= 0) {
        return null;
    }
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $cacheKey);
    $path = gov_api_cache_dir() . '/' . $safe . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['_expires_at'])) {
        return null;
    }
    if (time() > (int)$j['_expires_at']) {
        @unlink($path);

        return null;
    }
    $body = $j['payload'] ?? null;

    return is_array($body) ? $body : null;
}

/** @param array<string,mixed> $responsePayload */
function gov_api_cache_set(string $cacheKey, array $responsePayload): void {
    if (!defined('GOV_API_CACHE_TTL_SECONDS') || GOV_API_CACHE_TTL_SECONDS <= 0) {
        return;
    }
    $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $cacheKey);
    $path = gov_api_cache_dir() . '/' . $safe . '.json';
    $wrap = ['_expires_at' => time() + GOV_API_CACHE_TTL_SECONDS, 'payload' => $responsePayload];
    @file_put_contents($path, json_encode($wrap, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

set_error_handler(function(int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) return false; // respect @
    $msg = "PHP error ($severity): $message in $file:$line";
    log_error($msg);

    if (is_api_request()) {
        json_response(['ok' => false, 'error' => t('common.error_server_php')], 500);
    }
    return false; // let PHP handle for HTML
});

set_exception_handler(function(Throwable $e) {
    log_error('Uncaught exception: ' . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());

    if (is_api_request()) {
        json_response(['ok' => false, 'error' => t('common.error_server')], 500);
    }

    http_response_code(500);
    echo "<h1>500 - " . htmlspecialchars(t('common.error_server'), ENT_QUOTES, 'UTF-8') . "</h1>";
    echo "<p>" . htmlspecialchars(t('common.error_try_later'), ENT_QUOTES, 'UTF-8') . "</p>";
    exit;
});

// --------------------
// Base path/url helpers
// --------------------

if (!defined('APP_BASE')) {
    $path = parse_url(APP_BASE_URL, PHP_URL_PATH);
    $path = is_string($path) ? $path : '/terkep';
    define('APP_BASE', rtrim($path, '/'));
}

function app_url(string $path = ''): string {
    $base = rtrim(APP_BASE_URL, '/');
    if ($path === '') return $base;
    return $base . '/' . ltrim($path, '/');
}

function is_mobile_device(): bool {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!is_string($ua) || $ua === '') return false;
    return (bool)preg_match('/Android|iPhone|iPad|iPod|Mobile|IEMobile|Opera Mini|webOS/i', $ua);
}

/**
 * Whether to show mobile layout (Mobilekit shell). False if desktop is forced via ?desktop=1 or force_desktop cookie.
 */
function use_mobile_layout(): bool {
    if (!is_mobile_device()) return false;
    if (!empty($_GET['desktop']) || !empty($_COOKIE['force_desktop'])) return false;
    return true;
}

// --------------------
// Session bootstrap
// --------------------

function is_https_request(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    if (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) return true;
    return false;
}

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $cookieParams = session_get_cookie_params();
    session_name(SESSION_NAME);

    // IMPORTANT: secure cookie only when HTTPS is actually used.
    $secure = is_https_request();

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE . '/', // keep session limited to /terkep
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// --------------------
// Auth helpers
// --------------------

function require_admin(): void {
    start_secure_session();

    if (!empty($_SESSION['admin_logged_in'])) return;

    $role = $_SESSION['user_role'] ?? '';
    if (in_array($role, ['admin', 'superadmin'], true)) return;

    // Optional legacy token support
    if (defined('ADMIN_TOKEN') && ADMIN_TOKEN && isset($_GET['token']) && hash_equals((string)ADMIN_TOKEN, (string)$_GET['token'])) {
        $_SESSION['admin_logged_in'] = true;
        return;
    }

    header('Location: ' . app_url('admin/login.php'));
    exit;
}

/** Gov dashboard és gov-specifikus API-k (heatmap, statisztika stb.): csak govuser / admin / superadmin. */
function require_gov_or_admin(): void {
    start_secure_session();
    $role = $_SESSION['user_role'] ?? '';
    if (in_array($role, ['admin', 'superadmin', 'govuser'], true)) return;
    if (is_api_request()) {
        json_response(['ok' => false, 'error' => t('common.error_no_permission')], 403);
        exit;
    }
    header('Location: ' . app_url('user/login.php'));
    exit;
}

function require_user(): void {
    start_secure_session();
    if (!empty($_SESSION['user_id'])) return;
    json_response(['ok' => false, 'error' => t('auth.login_required')], 401);
}

function current_user_id(): ?int {
    start_secure_session();
    if (empty($_SESSION['user_id'])) return null;
    return (int)$_SESSION['user_id'];
}

function current_user_role(): ?string {
    start_secure_session();
    if (empty($_SESSION['user_role'])) return null;
    return (string)$_SESSION['user_role'];
}

function has_role(array $roles): bool {
    start_secure_session();
    $role = $_SESSION['user_role'] ?? '';
    return in_array($role, $roles, true);
}

// --------------------
// Nyelv (i18n)
// --------------------
const LANG_DEFAULT = 'hu';
const LANG_ALLOWED = ['hu', 'en', 'de', 'fr', 'it', 'es', 'sl'];

function current_lang(): string {
    start_secure_session();
    if (!empty($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
        return (string)$_GET['lang'];
    }
    $lang = $_SESSION['lang'] ?? $_COOKIE['lang'] ?? null;
    if ($lang !== null && in_array($lang, LANG_ALLOWED, true)) {
        return (string)$lang;
    }
    return LANG_DEFAULT;
}

function set_lang(string $code): void {
    if (!in_array($code, LANG_ALLOWED, true)) return;
    start_secure_session();
    $_SESSION['lang'] = $code;
    $path = defined('APP_BASE') ? (APP_BASE . '/') : '/';
    $secure = is_https_request();
    setcookie('lang', $code, ['expires' => time() + 86400 * 365, 'path' => $path, 'secure' => $secure, 'samesite' => 'Lax']);
}

function t(string $key): string {
    static $lang = null;
    if ($lang === null) {
        $code = current_lang();
        $file = __DIR__ . '/lang/' . $code . '.php';
        $lang = is_file($file) ? (require $file) : [];
    }
    return $lang[$key] ?? $key;
}

/** Nyelvi tömb JS számára (pl. window.LANG) */
function lang_array_for_js(): array {
    $code = current_lang();
    $file = __DIR__ . '/lang/' . $code . '.php';
    return is_file($file) ? (require $file) : [];
}

// --------------------
// XP, badge, leaderboard (service réteg)
// --------------------
require_once __DIR__ . '/services/XpBadge.php';

// --------------------
// AI router & helper (Civic Green Intelligence)
// --------------------
require_once __DIR__ . '/services/AiRouter.php';
require_once __DIR__ . '/services/AiPromptBuilder.php';
require_once __DIR__ . '/services/AiResultParser.php';

function ai_store_result(string $entityType, ?int $entityId, string $taskType, string $model, string $inputHash, ?array $data, ?float $confidence): void {
    try {
        $stmt = db()->prepare("
            INSERT INTO ai_results (entity_type, entity_id, task_type, model_name, input_hash, output_json, confidence_score)
            VALUES (:et, :eid, :tt, :m, :h, :out, :c)
        ");
        $stmt->execute([
            ':et' => $entityType,
            ':eid' => $entityId,
            ':tt' => $taskType,
            ':m'  => $model,
            ':h'  => $inputHash,
            ':out'=> $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            ':c'  => $confidence,
        ]);
    } catch (Throwable $e) {
        // AI eredmény tárolás hibája ne törje el a fő folyamatot
        log_error('ai_store_result error: ' . $e->getMessage());
    }
}

// --------------------
// Beépülő modulok – beállítások DB-ből (admin felületről), env fallback
// --------------------
function get_module_setting(string $moduleKey, string $settingKey): ?string {
    static $cache = [];
    $k = $moduleKey . '.' . $settingKey;
    if (array_key_exists($k, $cache)) {
        return $cache[$k];
    }
    try {
        $stmt = db()->prepare("SELECT value FROM module_settings WHERE module_key = :mk AND setting_key = :sk LIMIT 1");
        $stmt->execute([':mk' => $moduleKey, ':sk' => $settingKey]);
        $v = $stmt->fetchColumn();
        $cache[$k] = $v !== false && $v !== null ? (string)$v : null;
        return $cache[$k];
    } catch (Throwable $e) {
        $cache[$k] = null;
        return null;
    }
}

/** EU Open Data modul (admin → Beépülő modulok → EU nyílt adatok). */
function eu_open_data_module_enabled(): bool {
    return get_module_setting('eu_open_data', 'enabled') === '1';
}

/** Részletes forrás kapcsoló (copernicus_enabled, clms_enabled, …); csak ha a fő modul be van kapcsolva. */
function eu_open_data_feature_enabled(string $settingKey): bool {
    if (!eu_open_data_module_enabled()) {
        return false;
    }
    return get_module_setting('eu_open_data', $settingKey) === '1';
}

function eu_open_data_request_timeout_seconds(): int {
    $v = get_module_setting('eu_open_data', 'request_timeout_seconds');
    if ($v !== null && $v !== '' && is_numeric($v)) {
        return max(5, min(120, (int)$v));
    }
    $e = getenv('EU_OPEN_DATA_HTTP_TIMEOUT');
    if ($e !== false && $e !== '' && is_numeric($e)) {
        return max(5, min(120, (int)$e));
    }
    return 30;
}

function eu_open_data_cache_ttl_minutes(): int {
    $v = get_module_setting('eu_open_data', 'cache_ttl_minutes');
    if ($v !== null && $v !== '' && is_numeric($v)) {
        return max(1, min(10080, (int)$v));
    }
    $e = getenv('EU_OPEN_DATA_CACHE_TTL_MINUTES');
    if ($e !== false && $e !== '' && is_numeric($e)) {
        return max(1, min(10080, (int)$e));
    }
    return 60;
}

/** Jövőbeli EU adat szinkron cron (Milestone 5+); külön az IoT cron-tól. */
function eu_open_data_sync_enabled(): bool {
    return eu_open_data_module_enabled() && get_module_setting('eu_open_data', 'sync_enabled') === '1';
}

/** Részvételi költségvetés – modul ki/be (időszakos szavazás). Alapértelmezett: be. */
function participatory_budget_enabled(): bool {
    $v = get_module_setting('participatory_budget', 'enabled');
    return $v !== '0' && $v !== 'false';
}

/** Felmérések – modul ki/be (menü és nyilvános oldal). Alapértelmezett: be. */
function surveys_enabled(): bool {
    $v = get_module_setting('surveys', 'enabled');
    return $v !== '0' && $v !== 'false';
}

/** Igaz, ha a felhasználó városa (address_city) olyan hatósághoz tartozik, ahol van RK (projekt vagy beállítás). */
function user_city_has_budget(int $userId): bool {
    if ($userId <= 0) return false;
    try {
        $st = db()->prepare("SELECT address_city FROM users WHERE id = ? LIMIT 1");
        $st->execute([$userId]);
        $city = trim((string)($st->fetchColumn() ?: ''));
        if ($city === '') return false;
        $st = db()->prepare("SELECT a.id FROM authorities a WHERE TRIM(a.city) = ? LIMIT 1");
        $st->execute([$city]);
        $aid = $st->fetchColumn();
        if ($aid === false || $aid === null) return false;
        $aid = (int)$aid;
        $st = db()->prepare("SELECT 1 FROM budget_projects WHERE authority_id = ? LIMIT 1");
        $st->execute([$aid]);
        if ($st->fetchColumn() !== false) return true;
        $st = db()->prepare("SELECT 1 FROM budget_settings WHERE authority_id = ? LIMIT 1");
        $st->execute([$aid]);
        return $st->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/** FMS: először modul (admin), majd env. */
function fms_enabled(): bool {
    $fromModule = get_module_setting('fms', 'enabled') === '1' && (get_module_setting('fms', 'base_url') ?? '') !== '';
    if ($fromModule) return true;
    return defined('FMS_OPEN311_BASE') && (string)FMS_OPEN311_BASE !== '';
}

function fms_config_base_url(): string {
    $v = get_module_setting('fms', 'base_url');
    if ($v !== null && $v !== '') return rtrim($v, '/');
    return defined('FMS_OPEN311_BASE') ? rtrim((string)FMS_OPEN311_BASE, '/') : '';
}

function fms_config_jurisdiction(): string {
    $v = get_module_setting('fms', 'jurisdiction');
    if ($v !== null && $v !== '') return $v;
    return defined('FMS_OPEN311_JURISDICTION') ? (string)FMS_OPEN311_JURISDICTION : '';
}

function fms_config_api_key(): string {
    $v = get_module_setting('fms', 'api_key');
    if ($v !== null && $v !== '') return $v;
    return defined('FMS_OPEN311_API_KEY') ? (string)FMS_OPEN311_API_KEY : '';
}

/** AI (Mistral / OpenAI / Gemini) be van-e állítva – modul vagy env. */
function ai_configured(): bool {
    $mistralOk = get_module_setting('mistral', 'enabled') === '1' && (get_module_setting('mistral', 'api_key') ?? '') !== '';
    if ($mistralOk) return true;
    $openaiOk = get_module_setting('openai', 'enabled') === '1' && (get_module_setting('openai', 'api_key') ?? '') !== '';
    if ($openaiOk) return true;
    if (!defined('AI_ENABLED') || !AI_ENABLED) return false;
    $provider = defined('AI_PROVIDER') ? (string)AI_PROVIDER : 'mistral';
    if ($provider === 'gemini') {
        return defined('GEMINI_API_KEY') && (string)GEMINI_API_KEY !== '';
    }
    if ($provider === 'openai') {
        return defined('OPENAI_API_KEY') && (string)OPENAI_API_KEY !== '';
    }
    return defined('MISTRAL_API_KEY') && (string)MISTRAL_API_KEY !== '';
}

/** Mistral API kulcs – modul (admin) vagy env. */
function mistral_api_key(): string {
    $v = get_module_setting('mistral', 'api_key');
    if ($v !== null && $v !== '') return $v;
    return defined('MISTRAL_API_KEY') ? (string)MISTRAL_API_KEY : '';
}

/** OpenAI API kulcs – modul (admin) vagy env. */
function openai_api_key(): string {
    $v = get_module_setting('openai', 'api_key');
    if ($v !== null && $v !== '') return $v;
    return defined('OPENAI_API_KEY') ? (string)OPENAI_API_KEY : '';
}

/**
 * AI hívási limit – először module_settings (mistral), ha nincs akkor env/config.
 * @param string $key 'summary' | 'reports_per_day' | 'image_analysis'
 */
function get_ai_limit(string $key): int {
    $settingKey = null;
    if ($key === 'summary') $settingKey = 'ai_summary_limit';
    elseif ($key === 'reports_per_day') $settingKey = 'ai_max_reports_per_day';
    elseif ($key === 'image_analysis') $settingKey = 'ai_image_analysis_limit';
    if ($settingKey !== null) {
        $v = get_module_setting('mistral', $settingKey);
        if ($v !== null && $v !== '' && is_numeric($v)) {
            return max(0, (int) $v);
        }
    }
    if ($key === 'summary') {
        return defined('AI_SUMMARY_LIMIT') ? max(0, (int) AI_SUMMARY_LIMIT) : 20;
    }
    if ($key === 'reports_per_day') {
        return defined('AI_MAX_REPORTS_PER_DAY') ? max(0, (int) AI_MAX_REPORTS_PER_DAY) : 1000;
    }
    if ($key === 'image_analysis') {
        return defined('AI_IMAGE_ANALYSIS_LIMIT') ? max(0, (int) AI_IMAGE_ANALYSIS_LIMIT) : 300;
    }
    return 0;
}

// --------------------
// Govuser modul kapcsolók (UI-szint)
// --------------------
function user_module_enabled(?int $userId, string $moduleKey): bool {
    if (!$userId) return true;
    try {
        $stmt = db()->prepare("SELECT is_enabled FROM user_module_toggles WHERE user_id = ? AND module_key = ? LIMIT 1");
        $stmt->execute([$userId, $moduleKey]);
        $v = $stmt->fetchColumn();
        if ($v === false || $v === null) return true; // default ON
        return (int)$v === 1;
    } catch (Throwable $e) {
        return true;
    }
}

// --------------------
// FixMyStreet Open311 bridge helpers
// --------------------
function fms_open311_request(array $payload): array {
    $base = fms_config_base_url();
    if ($base === '') {
        return ['ok' => false, 'error' => 'FMS not configured'];
    }
    $url = $base . '/open311/v2/requests.json';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) {
        return ['ok' => false, 'error' => $err ?: 'Open311 request failed'];
    }

    $json = json_decode($res, true);
    if ($code >= 400) {
        return ['ok' => false, 'error' => is_array($json) ? json_encode($json) : $res];
    }
    return ['ok' => true, 'data' => $json];
}

function fms_open311_get(string $path, array $query = []): array {
    $base = fms_config_base_url();
    if ($base === '') {
        return ['ok' => false, 'error' => 'FMS not configured'];
    }
    $url = $base . $path;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) {
        return ['ok' => false, 'error' => $err ?: 'Open311 request failed'];
    }
    $json = json_decode($res, true);
    if ($code >= 400) {
        return ['ok' => false, 'error' => is_array($json) ? json_encode($json) : $res];
    }
    return ['ok' => true, 'data' => $json];
}

// --------------------
// Authority routing (local)
// --------------------
function find_authority_for_report(?string $city, ?string $serviceCode = null): ?int {
    try {
        $pdo = db();
        $city = $city ? trim($city) : null;

        // Prefer authority that explicitly supports the service_code
        if ($serviceCode) {
            if ($city) {
                $stmt = $pdo->prepare("
                    SELECT a.id
                    FROM authorities a
                    JOIN authority_contacts c ON c.authority_id = a.id
                    WHERE a.is_active=1 AND c.is_active=1
                      AND c.service_code = :code
                      AND a.city LIKE :city
                    LIMIT 1
                ");
                $stmt->execute([
                    ':code' => $serviceCode,
                    ':city' => '%' . $city . '%'
                ]);
                $id = (int)$stmt->fetchColumn();
                if ($id > 0) return $id;
            }

            $stmt = $pdo->prepare("
                SELECT a.id
                FROM authorities a
                JOIN authority_contacts c ON c.authority_id = a.id
                WHERE a.is_active=1 AND c.is_active=1
                  AND c.service_code = :code
                ORDER BY a.id ASC
                LIMIT 1
            ");
            $stmt->execute([':code' => $serviceCode]);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) return $id;
        }

        // BBox routing (if report coords stored temporarily in globals)
        if (!empty($GLOBALS['__REPORT_LAT']) && !empty($GLOBALS['__REPORT_LNG'])) {
            $lat = (float)$GLOBALS['__REPORT_LAT'];
            $lng = (float)$GLOBALS['__REPORT_LNG'];
            $stmt = $pdo->prepare("
                SELECT id FROM authorities
                WHERE is_active=1
                  AND min_lat IS NOT NULL AND max_lat IS NOT NULL
                  AND min_lng IS NOT NULL AND max_lng IS NOT NULL
                  AND :lat BETWEEN min_lat AND max_lat
                  AND :lng BETWEEN min_lng AND max_lng
                LIMIT 1
            ");
            $stmt->execute([':lat' => $lat, ':lng' => $lng]);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) return $id;
        }

        if ($city) {
            $stmt = $pdo->prepare("SELECT id FROM authorities WHERE is_active=1 AND city LIKE :city LIMIT 1");
            $stmt->execute([':city' => '%' . $city . '%']);
            $id = (int)$stmt->fetchColumn();
            if ($id > 0) return $id;
        }

        // fallback: first active authority
        $stmt = $pdo->query("SELECT id FROM authorities WHERE is_active=1 ORDER BY id ASC LIMIT 1");
        $id = (int)$stmt->fetchColumn();
        return $id > 0 ? $id : null;
    } catch (Throwable $e) {
        // Régi schema: nincs authority_contacts vagy authorities.is_active, hanem authorities.active
        try {
            $stmt = $pdo->query("SELECT id FROM authorities WHERE active=1 ORDER BY id ASC LIMIT 1");
            $id = (int)$stmt->fetchColumn();
            return $id > 0 ? $id : null;
        } catch (Throwable $e2) {
            return null;
        }
    }
}

function gps_is_precise($val): bool {
    if ($val === null) return false;
    $s = trim((string)$val);
    if ($s === '' || strpos($s, '.') === false) return false;
    $parts = explode('.', $s, 2);
    return isset($parts[1]) && strlen($parts[1]) >= 5;
}

function normalize_name(string $name): string {
    $name = trim($name);
    if ($name === '') return '';
    $lower = function_exists('mb_strtolower') ? mb_strtolower($name) : strtolower($name);
    $name = trim($lower);
    if ($name === '') return '';
    $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    if ($trans !== false) $name = $trans;
    $name = preg_replace('/[^a-z]/', '', $name);
    return $name ?: '';
}

function normalize_name_variants(string $name): array {
    $name = trim($name);
    if ($name === '') return [];
    $variants = [$name];
    foreach (preg_split('/[\\s\\-]+/', $name) as $part) {
        $part = trim($part);
        if ($part !== '') $variants[] = $part;
    }
    $out = [];
    foreach ($variants as $v) {
        $norm = normalize_name($v);
        if ($norm !== '') $out[] = $norm;
    }
    return array_values(array_unique($out));
}

function load_name_days(): array {
    $path = __DIR__ . '/data/name_days.json';
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false) return [];
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function names_for_today(): array {
    $md = date('m-d');
    $map = load_name_days();
    $names = $map[$md] ?? [];
    if (!is_array($names)) return [];
    $out = [];
    foreach ($names as $n) {
        $norm = normalize_name((string)$n);
        if ($norm !== '') $out[] = $norm;
    }
    return array_values(array_unique($out));
}

// -----------------------------------------------------------------------------
// Missing helpers (re-added)
// -----------------------------------------------------------------------------

/**
 * Read JSON request body safely.
 * Returns an associative array. On invalid JSON returns empty array.
 */
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') return [];

    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return [];
    }
    return $data;
}

/**
 * Trim + limit a string, return null if empty.
 */
function safe_str($val, int $maxLen): ?string {
    if ($val === null) return null;
    if (is_bool($val)) $val = $val ? '1' : '0';
    if (is_array($val) || is_object($val)) return null;

    $s = trim((string)$val);
    if ($s === '') return null;

    // remove control chars
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);

    // limit length (multibyte)
    if (function_exists('mb_substr')) {
        $s = mb_substr($s, 0, $maxLen);
    } else {
        $s = substr($s, 0, $maxLen);
    }

    return $s;
}

/**
 * Backward compatible alias (older code used require_login()).
 */
function require_login(): void {
    require_user();
}

/**
 * Best-effort client IP detection (works behind proxies/CDN too).
 */
function client_ip(): ?string {
    $candidates = [];

    // Common proxy headers
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_REAL_IP']))        $candidates[] = $_SERVER['HTTP_X_REAL_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // first IP in list is the original client in most setups
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        if (!empty($parts)) $candidates[] = trim($parts[0]);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) $candidates[] = $_SERVER['REMOTE_ADDR'];

    foreach ($candidates as $ip) {
        $ip = trim((string)$ip);
        if ($ip === '') continue;
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return null;
}

/**
 * Hash IP for privacy-preserving rate limits.
 */
function ip_hash(string $ip): string {
    $salt = defined('IP_HASH_SALT') ? (string)IP_HASH_SALT : 'ip_salt';
    return hash_hmac('sha256', $ip, $salt);
}

/**
 * Generate human friendly case number without DB schema changes.
 * Format: OH-YYYY-000123
 */
function case_number(int $id, ?string $createdAt = null): string {
    $year = (int)date('Y');
    if ($createdAt) {
        $ts = strtotime($createdAt);
        if ($ts !== false) $year = (int)date('Y', $ts);
    }
    $num = str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    return 'OH-' . $year . '-' . $num;
}

/**
 * Geocode address to point. Returns [lat, lng] or null.
 */
/**
 * Forward geocode (Nominatim): több találat, a térkép-kereső / API számára.
 *
 * @return list<array{lat:string,lon:string,display_name:string}>
 */
function nominatim_geocode_forward_list(string $query, int $limit = 5, string $countrycodes = ''): array {
    $query = trim($query);
    if ($query === '' || !defined('NOMINATIM_BASE')) {
        return [];
    }
    $limit = min(10, max(1, $limit));
    $cc = strtolower(preg_replace('/[^a-z,]/', '', $countrycodes));

    $base = rtrim((string)NOMINATIM_BASE, '/');
    $url = $base . '/search?format=jsonv2&q=' . rawurlencode($query) . '&limit=' . $limit;
    if ($cc !== '') {
        $url .= '&countrycodes=' . rawurlencode($cc);
    }
    $ua = defined('NOMINATIM_USER_AGENT') ? (string)NOMINATIM_USER_AGENT : 'Problematerkep/1.0';
    $opts = ['http' => ['method' => 'GET', 'header' => "User-Agent: {$ua}\r\nAccept: application/json\r\n", 'timeout' => 8]];

    try {
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) {
            return [];
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return [];
        }
        $out = [];
        foreach ($arr as $r) {
            if (!is_array($r)) {
                continue;
            }
            $lat = isset($r['lat']) ? (string)$r['lat'] : '';
            $lon = isset($r['lon']) ? (string)$r['lon'] : '';
            $name = isset($r['display_name']) ? (string)$r['display_name'] : '';
            if ($lat !== '' && $lon !== '') {
                $out[] = ['lat' => $lat, 'lon' => $lon, 'display_name' => $name];
            }
        }

        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Címkereső UI + api/geocode_search.php: modul bekapcsolás, provider lista (TomTom csak bejelentkezve + API kulcs).
 *
 * @return array{backend:bool,endpoint:?string,providers:list<array{id:string,label:string}>,default:string,show_selector:bool}
 */
function civic_geocode_client_config(int $userId): array {
    $empty = ['backend' => false, 'endpoint' => null, 'providers' => [], 'default' => 'nominatim', 'show_selector' => false];
    if (!function_exists('get_module_setting')) {
        return $empty;
    }
    if (get_module_setting('geocode', 'enabled') !== '1') {
        return $empty;
    }
    $mode = strtolower(trim((string)(get_module_setting('geocode', 'search_provider_mode') ?: 'both')));
    if (!in_array($mode, ['nominatim', 'tomtom', 'both'], true)) {
        $mode = 'both';
    }
    $key = trim((string)(get_module_setting('geocode', 'tomtom_api_key') ?? ''));
    $hasTomtom = $key !== '';

    $providers = [];
    if ($mode === 'nominatim' || $mode === 'both') {
        $providers[] = ['id' => 'nominatim', 'label' => t('search.provider_nominatim')];
    }
    if (($mode === 'tomtom' || $mode === 'both') && $hasTomtom && $userId > 0) {
        $providers[] = ['id' => 'tomtom', 'label' => t('search.provider_tomtom')];
    }
    if ($providers === []) {
        $providers[] = ['id' => 'nominatim', 'label' => t('search.provider_nominatim')];
    }

    $default = (string)($providers[0]['id'] ?? 'nominatim');
    if ($mode === 'tomtom' && $hasTomtom && $userId > 0) {
        $default = 'tomtom';
    }

    return [
        'backend' => true,
        'endpoint' => app_url('/api/geocode_search.php'),
        'providers' => $providers,
        'default' => $default,
        'show_selector' => count($providers) > 1,
    ];
}

function nominatim_geocode_to_point(string $address): ?array {
    $address = trim($address);
    if ($address === '') return null;
    if (!defined('NOMINATIM_BASE')) return null;

    $base = rtrim((string)NOMINATIM_BASE, '/');
    $url = $base . '/search?format=jsonv2&q=' . rawurlencode($address) . '&limit=1';
    $ua = defined('NOMINATIM_USER_AGENT') ? (string)NOMINATIM_USER_AGENT : 'Problematerkep/1.0';

    $opts = ['http' => ['method' => 'GET', 'header' => "User-Agent: {$ua}\r\nAccept: application/json\r\n", 'timeout' => 6]];
    try {
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) return null;
        $arr = json_decode($raw, true);
        if (!is_array($arr) || empty($arr)) return null;
        $r = $arr[0];
        $lat = isset($r['lat']) ? (float)$r['lat'] : null;
        $lng = isset($r['lon']) ? (float)$r['lon'] : null;
        if ($lat !== null && $lng !== null) {
            return [$lat, $lng];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Geocode address via Nominatim (best effort). Returns [min_lat, max_lat, min_lng, max_lng] or null.
 */
function nominatim_geocode_address(string $address): ?array {
    $address = trim($address);
    if ($address === '') return null;
    if (!defined('NOMINATIM_BASE')) return null;

    $base = rtrim((string)NOMINATIM_BASE, '/');
    $url = $base . '/search?format=jsonv2&q=' . rawurlencode($address) . '&limit=1';
    $ua = defined('NOMINATIM_USER_AGENT') ? (string)NOMINATIM_USER_AGENT : 'Problematerkep/1.0';

    $opts = ['http' => ['method' => 'GET', 'header' => "User-Agent: {$ua}\r\nAccept: application/json\r\n", 'timeout' => 6]];
    try {
        $raw = @file_get_contents($url, false, stream_context_create($opts));
        if ($raw === false) return null;
        $arr = json_decode($raw, true);
        if (!is_array($arr) || empty($arr)) return null;
        $r = $arr[0];
        $box = $r['boundingbox'] ?? null;
        if (is_array($box) && count($box) >= 4) {
            return [(float)$box[0], (float)$box[1], (float)$box[2], (float)$box[3]];
        }
        $lat = isset($r['lat']) ? (float)$r['lat'] : null;
        $lng = isset($r['lon']) ? (float)$r['lon'] : null;
        if ($lat !== null && $lng !== null) {
            $d = 0.01;
            return [$lat - $d, $lat + $d, $lng - $d, $lng + $d];
        }
        return null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Reverse geocode via Nominatim (best effort).
 */
function nominatim_reverse(float $lat, float $lng): ?array {
    if (!defined('NOMINATIM_BASE')) return null;

    $base = rtrim((string)NOMINATIM_BASE, '/');
    $url = $base . '/reverse?format=jsonv2&lat=' . rawurlencode((string)$lat) . '&lon=' . rawurlencode((string)$lng) . '&zoom=18&addressdetails=1';
    $ua = defined('NOMINATIM_USER_AGENT') ? (string)NOMINATIM_USER_AGENT : 'Problematerkep/1.0';

    $opts = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: {$ua}\r\nAccept: application/json\r\n",
            'timeout' => 6,
        ]
    ];

    try {
        $ctx = stream_context_create($opts);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) return null;
        $json = json_decode($raw, true);
        if (!is_array($json)) return null;
        return $json;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Simple mail helper (best effort).
 */
function send_mail(string $to, string $subject, string $bodyText): bool {
    $from = defined('MAIL_FROM') ? (string)MAIL_FROM : 'no-reply@localhost';
    $fromName = defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : 'Problématérkép';

    $encodedFromName = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($fromName, 'UTF-8', 'B')
        : $fromName;
    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B')
        : $subject;

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'From: ' . $encodedFromName . ' <' . $from . '>';
    $headers[] = 'Reply-To: ' . $from;

    return @mail($to, $encodedSubject, $bodyText, implode("\r\n", $headers));
}

/**
 * Szabályos HTML e-mail sablon (cím + tartalom + lábléc).
 */
function email_template_html(string $title, string $bodyHtml): string {
    $siteName = defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : (function_exists('t') ? t('site.name') : 'CivicAI');
    $bodyHtml = trim($bodyHtml);
    return '<!DOCTYPE html><html lang="hu"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<style>body{font-family:system-ui,-apple-system,sans-serif;line-height:1.5;color:#333;max-width:600px;margin:0 auto;padding:20px;}'
        . 'a{color:#0d6efd;} .footer{font-size:12px;color:#6c757d;margin-top:24px;border-top:1px solid #dee2e6;padding-top:12px;}</style></head><body>'
        . '<div class="content">' . $bodyHtml . '</div>'
        . '<div class="footer">' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '</div></body></html>';
}

/**
 * HTML e-mail küldés (UTF-8, szabályos fejlécek).
 */
function send_mail_html(string $to, string $subject, string $bodyHtml): bool {
    $from = defined('MAIL_FROM') ? (string)MAIL_FROM : 'no-reply@localhost';
    $fromName = defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : 'Problématérkép';

    $encodedFromName = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($fromName, 'UTF-8', 'B')
        : $fromName;
    $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8', 'B')
        : $subject;

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/html; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: 8bit';
    $headers[] = 'From: ' . $encodedFromName . ' <' . $from . '>';
    $headers[] = 'Reply-To: ' . $from;

    return @mail($to, $encodedSubject, $bodyHtml, implode("\r\n", $headers));
}

/**
 * Virtuális szenzorok szűrési feltétele hatóság városai és bounds alapján.
 * Megjelennek: város név egyezés VAGY koordináta a bboxban.
 * @param array $cities Hatóság városnevei (pl. ['Budapest'])
 * @param array $bounds Hatóság bbox listája [[minLat, maxLat, minLng, maxLng], ...]
 * @return array [where string (vs. alias), params array]
 */
function virtual_sensors_scope_for_authority(array $cities, array $bounds): array {
  $scopeParts = [];
  $params = [];
  if (!empty($cities)) {
    $ph = implode(',', array_fill(0, count($cities), '?'));
    $scopeParts[] = "(vs.municipality IN ($ph) OR vs.address_or_area_name IN ($ph))";
    $params = array_merge($params, $cities, $cities);
  }
  if (!empty($bounds)) {
    $minLat = min(array_column($bounds, 0));
    $maxLat = max(array_column($bounds, 1));
    $minLng = min(array_column($bounds, 2));
    $maxLng = max(array_column($bounds, 3));
    $scopeParts[] = "(vs.latitude IS NOT NULL AND vs.longitude IS NOT NULL AND vs.latitude >= ? AND vs.latitude <= ? AND vs.longitude >= ? AND vs.longitude <= ?)";
    $params = array_merge($params, [$minLat, $maxLat, $minLng, $maxLng]);
  }
  $where = "vs.is_active = 1";
  if (!empty($scopeParts)) {
    $where .= " AND (" . implode(" OR ", $scopeParts) . ")";
  }
  return [$where, $params];
}

/**
 * Van-e adott oszlop a táblában (MySQL / MariaDB).
 */
function db_table_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $t = str_replace('`', '``', $table);
    $st = $pdo->query('SHOW COLUMNS FROM `' . $t . '` LIKE ' . $pdo->quote($column));
    return $st && $st->rowCount() > 0;
  } catch (Throwable $e) {
    return false;
  }
}

/**
 * Gov/admin fa-lista: mely authority_id-k tartoznak a kéréshez (GET authority_id vagy user hatóságai).
 * @return int[]
 */
function gov_tree_list_scope_authority_ids(): array {
  $role = current_user_role() ?: '';
  $uid = current_user_id() ? (int)current_user_id() : 0;
  $ids = [];
  if (in_array($role, ['admin', 'superadmin'], true)) {
    $aid = isset($_GET['authority_id']) ? (int)$_GET['authority_id'] : 0;
    if ($aid > 0) {
      $ids = [$aid];
    } else {
      try {
        $ids = array_map('intval', db()->query('SELECT id FROM authorities ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));
      } catch (Throwable $e) {
        $ids = [];
      }
    }
  } elseif ($uid > 0) {
    try {
      $stmt = db()->prepare('SELECT authority_id FROM authority_users WHERE user_id = ? ORDER BY authority_id');
      $stmt->execute([$uid]);
      $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (Throwable $e) {
      $ids = [];
    }
  }
  return array_values(array_filter($ids, static fn($x) => $x > 0));
}

/**
 * SQL WHERE fragment a fakataszter listához: explicit authority_id VAGY (NULL + bbox/város illeszkedés).
 * @return array{0:string,1:array}
 */
function gov_trees_scope_where_sql(PDO $pdo, array $authorityIds, string $alias = 't'): array {
  if (empty($authorityIds)) {
    return ['1=0', []];
  }
  $a = $alias;
  $hasAuthCol = db_table_has_column($pdo, 'trees', 'authority_id');
  $parts = [];
  $params = [];

  if ($hasAuthCol) {
    $inPh = implode(',', array_fill(0, count($authorityIds), '?'));
    $parts[] = "$a.authority_id IN ($inPh)";
    $params = array_merge($params, $authorityIds);
  }

  $nullParts = [];
  foreach ($authorityIds as $aid) {
    try {
      $st = $pdo->prepare('SELECT min_lat, max_lat, min_lng, max_lng, city FROM authorities WHERE id = ? LIMIT 1');
      $st->execute([$aid]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) {
        continue;
      }
      $minLa = $row['min_lat'];
      $maxLa = $row['max_lat'];
      $minL = $row['min_lng'];
      $maxL = $row['max_lng'];
      $city = trim((string)($row['city'] ?? ''));
      if ($minLa !== null && $maxLa !== null && $minL !== null && $maxL !== null) {
        if ($hasAuthCol) {
          $nullParts[] = "($a.authority_id IS NULL AND $a.lat >= ? AND $a.lat <= ? AND $a.lng >= ? AND $a.lng <= ?)";
        } else {
          $nullParts[] = "($a.lat >= ? AND $a.lat <= ? AND $a.lng >= ? AND $a.lng <= ?)";
        }
        $params[] = (float) $minLa;
        $params[] = (float) $maxLa;
        $params[] = (float) $minL;
        $params[] = (float) $maxL;
      } elseif ($city !== '') {
        if ($hasAuthCol) {
          $nullParts[] = "($a.authority_id IS NULL AND $a.address LIKE ?)";
        } else {
          $nullParts[] = "($a.address LIKE ?)";
        }
        $params[] = '%' . $city . '%';
      }
    } catch (Throwable $e) {
      continue;
    }
  }

  if (!empty($nullParts)) {
    $parts[] = '(' . implode(' OR ', $nullParts) . ')';
  }

  if (empty($parts)) {
    $role = current_user_role() ?: '';
    if (!$hasAuthCol && in_array($role, ['admin', 'superadmin'], true)) {
      return ['1=1', []];
    }
    return ['1=0', []];
  }

  return ['(' . implode(' OR ', $parts) . ')', $params];
}

/**
 * Fák száma a gov_trees_scope-ban (opcionálisan csak public_visible = 1).
 *
 * @param int[] $authorityIds
 */
function gov_trees_count_in_scope(PDO $pdo, array $authorityIds, bool $publicOnly = false): int {
  if (empty($authorityIds)) {
    return 0;
  }
  [$scopeWhere, $scopeParams] = gov_trees_scope_where_sql($pdo, $authorityIds, 't');
  $pub = $publicOnly ? 't.public_visible = 1 AND ' : '';
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM trees t WHERE {$pub}($scopeWhere)");
    $stmt->execute($scopeParams);
    return (int) $stmt->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

/**
 * Heatmap fa-rétegek: mely hatóság-id-k határozzák meg a scope-ot (gov: egy; admin 0 param: mind).
 *
 * @return int[]
 */
function heatmap_tree_scope_authority_ids(PDO $pdo, int $authorityId, ?string $role = null): array {
  if ($authorityId > 0) {
    return [$authorityId];
  }
  $role = $role ?? (current_user_role() ?: '');
  if (in_array($role, ['admin', 'superadmin'], true)) {
    try {
      $raw = $pdo->query('SELECT id FROM authorities ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
      return array_values(array_filter(array_map('intval', $raw ?: []), static fn ($x) => $x > 0));
    } catch (Throwable $e) {
      return [];
    }
  }
  return [];
}

/**
 * Urban ESG kártya (Elemzés + Zöld fül): fa metrikák hatósági fa-scope-pal, report metrikák $reportWhere szerint.
 *
 * @param int[] $treeScopeIds
 * @return array{environment: array, social: array, governance: array}
 */
function gov_compute_esg_snapshot(PDO $pdo, array $treeScopeIds, string $reportWhere, array $reportParams): array {
  $env = [
    'trees_total' => 0,
    'trees_needing_inspection' => 0,
    'trees_needing_water' => 0,
    'trees_dangerous' => 0,
    'green_reports' => 0,
  ];
  if (!empty($treeScopeIds)) {
    try {
      [$tsc, $tsp] = gov_trees_scope_where_sql($pdo, $treeScopeIds, 't');
      $st = $pdo->prepare("SELECT COUNT(*) FROM trees t WHERE t.public_visible = 1 AND ($tsc)");
      $st->execute($tsp);
      $env['trees_total'] = (int) $st->fetchColumn();
      $st = $pdo->prepare("SELECT COUNT(*) FROM trees t WHERE t.public_visible = 1 AND ($tsc) AND (t.last_inspection IS NULL OR t.last_inspection < DATE_SUB(CURDATE(), INTERVAL 365 DAY))");
      $st->execute($tsp);
      $env['trees_needing_inspection'] = (int) $st->fetchColumn();
      $st = $pdo->prepare("SELECT COUNT(*) FROM trees t WHERE t.public_visible = 1 AND ($tsc) AND (t.last_watered IS NULL OR t.last_watered < DATE_SUB(CURDATE(), INTERVAL 7 DAY))");
      $st->execute($tsp);
      $env['trees_needing_water'] = (int) $st->fetchColumn();
      $st = $pdo->prepare("SELECT COUNT(*) FROM trees t WHERE t.public_visible = 1 AND ($tsc) AND t.risk_level = 'high'");
      $st->execute($tsp);
      $env['trees_dangerous'] = (int) $st->fetchColumn();
    } catch (Throwable $e) { /* ignore */ }
  }
  try {
    $qg = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $reportWhere AND r.category = 'green'");
    $qg->execute($reportParams);
    $env['green_reports'] = (int) $qg->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }

  $soc = [
    'active_citizens_30d' => 0,
    'tree_adopters' => 0,
    'green_events_active' => 0,
    'watering_actions_30d' => 0,
  ];
  try {
    $qsoc = $pdo->prepare("SELECT COUNT(DISTINCT r.user_id) FROM reports r WHERE $reportWhere AND r.user_id IS NOT NULL AND r.created_at >= (NOW() - INTERVAL 30 DAY)");
    $qsoc->execute($reportParams);
    $soc['active_citizens_30d'] = (int) $qsoc->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }
  try {
    if (!empty($treeScopeIds)) {
      [$tsc2, $tsp2] = gov_trees_scope_where_sql($pdo, $treeScopeIds, 't');
      $qta = $pdo->prepare("SELECT COUNT(DISTINCT ta.user_id) FROM tree_adoptions ta INNER JOIN trees t ON t.id = ta.tree_id WHERE ta.status = 'active' AND ($tsc2)");
      $qta->execute($tsp2);
      $soc['tree_adopters'] = (int) $qta->fetchColumn();
    }
  } catch (Throwable $e) { /* ignore */ }
  try {
    $soc['green_events_active'] = (int) $pdo->query("SELECT COUNT(*) FROM civil_events WHERE is_active = 1 AND event_type = 'green_action' AND end_date >= CURDATE()")->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }
  try {
    if (!empty($treeScopeIds)) {
      [$tsc3, $tsp3] = gov_trees_scope_where_sql($pdo, $treeScopeIds, 't');
      $qtw = $pdo->prepare("SELECT COUNT(*) FROM tree_watering_logs tw INNER JOIN trees t ON t.id = tw.tree_id WHERE ($tsc3) AND tw.created_at >= (NOW() - INTERVAL 30 DAY)");
      $qtw->execute($tsp3);
      $soc['watering_actions_30d'] = (int) $qtw->fetchColumn();
    }
  } catch (Throwable $e) { /* ignore */ }

  $gov = [
    'reports_open' => 0,
    'reports_solved_30d' => 0,
    'avg_resolution_days' => null,
  ];
  try {
    $qo = $pdo->prepare("SELECT COUNT(*) FROM reports r WHERE $reportWhere AND r.status NOT IN ('solved','closed','rejected')");
    $qo->execute($reportParams);
    $gov['reports_open'] = (int) $qo->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }
  try {
    $qs30 = $pdo->prepare("
      SELECT COUNT(*) FROM reports r
      JOIN report_status_log l ON l.report_id = r.id AND l.new_status IN ('solved','closed')
      WHERE $reportWhere AND l.changed_at >= (NOW() - INTERVAL 30 DAY)
    ");
    $qs30->execute($reportParams);
    $gov['reports_solved_30d'] = (int) $qs30->fetchColumn();
  } catch (Throwable $e) { /* ignore */ }
  try {
    $qa = $pdo->prepare("
      SELECT AVG(DATEDIFF(l.changed_at, r.created_at)) AS avg_days
      FROM reports r
      JOIN report_status_log l ON l.report_id = r.id AND l.new_status IN ('solved','closed')
      WHERE $reportWhere
    ");
    $qa->execute($reportParams);
    $avg = $qa->fetchColumn();
    if ($avg !== false && $avg !== null) {
      $gov['avg_resolution_days'] = (float) $avg;
    }
  } catch (Throwable $e) { /* ignore */ }

  return [
    'environment' => $env,
    'social' => $soc,
    'governance' => $gov,
  ];
}

// -----------------------------------------------------------------------------
// Administrative subdivision (EU-wide, provider-first; not city-specific)
// -----------------------------------------------------------------------------

function admin_subdivision_require_normalizer(): void {
  static $loaded = false;
  if (!$loaded) {
    require_once __DIR__ . '/services/AdminSubdivisionNormalizer.php';
    $loaded = true;
  }
}

/** @return string[] */
function admin_subdivision_parse_country_codes(): array {
  if (!defined('SUBDIVISION_AWARE_COUNTRY_CODES')) {
    return [];
  }
  $raw = trim((string)SUBDIVISION_AWARE_COUNTRY_CODES);
  if ($raw === '') {
    return [];
  }
  $out = [];
  foreach (explode(',', $raw) as $p) {
    $p = strtoupper(trim($p));
    if (strlen($p) === 2) {
      $out[] = $p;
    }
  }
  return array_values(array_unique($out));
}

/** @return array<string,bool> normalized city key => true */
function admin_subdivision_parse_city_keys(): array {
  if (!defined('SUBDIVISION_AWARE_CITIES')) {
    return [];
  }
  $raw = trim((string)SUBDIVISION_AWARE_CITIES);
  if ($raw === '') {
    return [];
  }
  $out = [];
  foreach (explode(',', $raw) as $p) {
    $k = trim(strtolower($p));
    if ($k !== '') {
      $out[$k] = true;
    }
  }
  return $out;
}

function admin_subdivision_city_key(?string $city): string {
  $city = $city ? trim($city) : '';
  if ($city === '') {
    return '';
  }
  return function_exists('mb_strtolower') ? mb_strtolower($city) : strtolower($city);
}

/** @return array{subdivision_aware?:int,country_code?:string,municipality_type?:string} */
function admin_subdivision_authority_flags(int $authorityId): array {
  if ($authorityId <= 0) {
    return [];
  }
  try {
    $st = db()->prepare('SELECT subdivision_aware, country_code, municipality_type FROM authorities WHERE id = ? LIMIT 1');
    $st->execute([$authorityId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : [];
  } catch (Throwable $e) {
    return [];
  }
}

/**
 * Configurable “subdivision aware” mode: country / city / authority / type (authority row).
 */
function admin_subdivision_mode_applies(?string $countryCode, ?string $city, ?int $authorityId = null): bool {
  if (defined('SUBDIVISION_AWARE_DEFAULT') && SUBDIVISION_AWARE_DEFAULT) {
    return true;
  }
  $cc = $countryCode ? strtoupper(substr(trim($countryCode), 0, 2)) : '';
  if ($cc !== '' && in_array($cc, admin_subdivision_parse_country_codes(), true)) {
    return true;
  }
  $ck = admin_subdivision_city_key($city);
  if ($ck !== '' && !empty(admin_subdivision_parse_city_keys()[$ck])) {
    return true;
  }
  if ($authorityId && $authorityId > 0) {
    $row = admin_subdivision_authority_flags($authorityId);
    if (!empty($row['subdivision_aware'])) {
      return true;
    }
    $ac = isset($row['country_code']) ? strtoupper(substr(trim((string)$row['country_code']), 0, 2)) : '';
    if ($ac !== '' && in_array($ac, admin_subdivision_parse_country_codes(), true)) {
      return true;
    }
  }
  return false;
}

function admin_subdivision_analytics_use_subcity(?int $authorityId): bool {
  if (defined('SUBDIVISION_ANALYTICS_USE_SUBCITY') && SUBDIVISION_ANALYTICS_USE_SUBCITY) {
    return true;
  }
  if (defined('SUBDIVISION_AWARE_DEFAULT') && SUBDIVISION_AWARE_DEFAULT) {
    return true;
  }
  if ($authorityId && $authorityId > 0) {
    $row = admin_subdivision_authority_flags($authorityId);
    if (!empty($row['subdivision_aware'])) {
      return true;
    }
  }
  return false;
}

/**
 * @param array<string,mixed>|null $geo
 * @param array<string,mixed>|null $bodyClientSnapshot normalized client schema
 * @param array{provider?:string,raw?:array<string,mixed>}|null $rawGeocode admin_geocode_provider + admin_geocode_raw
 * @return array<string,mixed>
 */
function admin_subdivision_build_for_report(
  ?array $geo,
  float $lat,
  float $lng,
  ?array $bodyClientSnapshot = null,
  ?array $rawGeocode = null
): array {
  admin_subdivision_require_normalizer();
  $norm = AdminSubdivisionNormalizer::fromNominatim($geo, $lat, $lng);
  $allowClient = defined('SUBDIVISION_ALLOW_CLIENT_SNAPSHOT') && SUBDIVISION_ALLOW_CLIENT_SNAPSHOT;
  if ($allowClient && is_array($rawGeocode)) {
    $gp = isset($rawGeocode['provider']) ? trim((string)$rawGeocode['provider']) : '';
    $gr = $rawGeocode['raw'] ?? null;
    if ($gp !== '' && is_array($gr) && $gr) {
      $pNorm = AdminSubdivisionNormalizer::fromProvider($gp, $gr);
      $norm = AdminSubdivisionNormalizer::mergeProviderPreferred($norm, $pNorm);
    }
  }
  if ($allowClient && is_array($bodyClientSnapshot) && $bodyClientSnapshot) {
    $norm = AdminSubdivisionNormalizer::mergeClientSnapshot($norm, $bodyClientSnapshot);
  }
  return $norm;
}

/**
 * @param array<string,mixed> $norm
 */
function admin_subdivision_to_json(array $norm): string {
  $j = json_encode($norm, JSON_UNESCAPED_UNICODE);
  return ($j !== false && json_last_error() === JSON_ERROR_NONE) ? $j : '{}';
}