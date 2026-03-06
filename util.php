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

set_error_handler(function(int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) return false; // respect @
    $msg = "PHP error ($severity): $message in $file:$line";
    log_error($msg);

    if (is_api_request()) {
        json_response(['ok' => false, 'error' => 'Szerver hiba (PHP).'], 500);
    }
    return false; // let PHP handle for HTML
});

set_exception_handler(function(Throwable $e) {
    log_error('Uncaught exception: ' . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());

    if (is_api_request()) {
        json_response(['ok' => false, 'error' => 'Szerver hiba.'], 500);
    }

    http_response_code(500);
    echo "<h1>500 - Szerver hiba</h1>";
    echo "<p>Hiba történt. Kérlek próbáld újra később.</p>";
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

function require_user(): void {
    start_secure_session();
    if (!empty($_SESSION['user_id'])) return;
    json_response(['ok' => false, 'error' => 'Bejelentkezés szükséges.'], 401);
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
// XP, badge, leaderboard (service réteg)
// --------------------
require_once __DIR__ . '/services/XpBadge.php';

// --------------------
// FixMyStreet Open311 bridge helpers
// --------------------
function fms_enabled(): bool {
    return defined('FMS_OPEN311_BASE') && (string)FMS_OPEN311_BASE !== '';
}

function fms_open311_request(array $payload): array {
    $base = rtrim((string)FMS_OPEN311_BASE, '/');
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
    $base = rtrim((string)FMS_OPEN311_BASE, '/');
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

    // RFC2047 header encoding
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