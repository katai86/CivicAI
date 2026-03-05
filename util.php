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
        return null;
    }
}

function level_from_xp(int $xp): array {
    $levels = [
        1 => ['name' => 'Katyuvadasz Tanonc',      'min' => 0],
        2 => ['name' => 'Szemfules Szomszed',      'min' => 100],
        3 => ['name' => 'Helyi Vagany',           'min' => 250],
        4 => ['name' => 'Tombfelelos',            'min' => 500],
        5 => ['name' => 'Jardaszegely-lovag',     'min' => 800],
        6 => ['name' => 'Keruleti Kiskiraly',     'min' => 1200],
        7 => ['name' => 'Varosi Vagyonor',        'min' => 1700],
        8 => ['name' => 'Aszfaltbetyar Fejedelem','min' => 2300],
        9 => ['name' => 'Varosgazda Fomagus',     'min' => 3000],
        10 => ['name' => 'A Varos Lelkiismerete', 'min' => 4000],
    ];

    $current = 1;
    foreach ($levels as $lvl => $meta) {
        if ($xp >= $meta['min']) $current = $lvl;
    }
    return ['level' => $current, 'name' => $levels[$current]['name']];
}

function add_user_xp(int $userId, int $points, string $reason, ?int $reportId = null): void {
    if ($points === 0) return;
    try {
        $pdo = db();
        $pdo->beginTransaction();

        $pdo->prepare("UPDATE users SET total_xp = total_xp + :p WHERE id = :id")
            ->execute([':p' => $points, ':id' => $userId]);

        // best-effort XP log (optional table)
        try {
            $pdo->prepare("INSERT INTO user_xp_log (user_id, points, reason, report_id) VALUES (:uid,:p,:r,:rid)")
                ->execute([':uid' => $userId, ':p' => $points, ':r' => $reason, ':rid' => $reportId]);
        } catch (Throwable $e) { /* ignore */ }

        $stmt = $pdo->prepare("SELECT total_xp FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $xp = (int)$stmt->fetchColumn();
        $lvl = level_from_xp($xp);
        $pdo->prepare("UPDATE users SET level = :lvl WHERE id = :id")
            ->execute([':lvl' => $lvl['level'], ':id' => $userId]);
        award_badge($userId, 'level_' . $lvl['level']);

        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    }
}

function add_user_xp_once(int $userId, int $points, string $eventKey, string $reason, ?int $reportId = null): void {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT 1 FROM user_xp_events WHERE user_id = :uid AND event_key = :ek LIMIT 1");
        $stmt->execute([':uid' => $userId, ':ek' => $eventKey]);
        if ($stmt->fetchColumn()) return;

        $pdo->prepare("INSERT INTO user_xp_events (user_id, event_key) VALUES (:uid, :ek)")
            ->execute([':uid' => $userId, ':ek' => $eventKey]);
        add_user_xp($userId, $points, $reason, $reportId);
    } catch (Throwable $e) {
        // If table does not exist, just award directly (best effort)
        add_user_xp($userId, $points, $reason, $reportId);
    }
}

function update_user_streak(int $userId): int {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT last_active_date, streak_days FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        if (!$row) return 0;

        $last = $row['last_active_date'] ? (string)$row['last_active_date'] : null;
        $streak = (int)($row['streak_days'] ?? 0);
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        if ($last === $today) {
            return $streak;
        }
        if ($last === $yesterday) {
            $streak += 1;
        } else {
            $streak = 1;
        }

        $pdo->prepare("UPDATE users SET streak_days = :s, last_active_date = :d WHERE id = :id")
            ->execute([':s' => $streak, ':d' => $today, ':id' => $userId]);

        return $streak;
    } catch (Throwable $e) {
        return 0;
    }
}

function award_badge(int $userId, string $badgeCode): void {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("SELECT id FROM badges WHERE code = :c LIMIT 1");
        $stmt->execute([':c' => $badgeCode]);
        $bid = $stmt->fetchColumn();
        if (!$bid) return;

        $stmt = $pdo->prepare("SELECT 1 FROM user_badges WHERE user_id = :uid AND badge_id = :bid LIMIT 1");
        $stmt->execute([':uid' => $userId, ':bid' => $bid]);
        if ($stmt->fetchColumn()) return;

        $pdo->prepare("INSERT INTO user_badges (user_id, badge_id) VALUES (:uid,:bid)")
            ->execute([':uid' => $userId, ':bid' => $bid]);
    } catch (Throwable $e) {
        // ignore
    }
}

function ensure_level_badge(int $userId, int $level): void {
    if ($level < 1) return;
    award_badge($userId, 'level_' . $level);
}

function get_leaderboard(string $period, int $limit = 10): array {
    $limit = max(1, min(50, $limit));
    $where = '';
    if ($period === 'week') {
        $where = "AND x.created_at >= (NOW() - INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $where = "AND x.created_at >= (NOW() - INTERVAL 1 MONTH)";
    }

    try {
        $sql = "
            SELECT u.id, u.display_name, u.level, u.avatar_filename, SUM(x.points) AS points
            FROM user_xp_log x
            JOIN users u ON u.id = x.user_id
            WHERE COALESCE(u.profile_public, 1) = 1
            $where
            GROUP BY u.id, u.display_name, u.level, u.avatar_filename
            HAVING points > 0
            ORDER BY points DESC
            LIMIT $limit
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function get_category_leaderboard(string $period, string $category, int $limit = 10): array {
    $limit = max(1, min(50, $limit));
    if ($category === '') return [];

    $where = '';
    if ($period === 'week') {
        $where = "AND r.created_at >= (NOW() - INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $where = "AND r.created_at >= (NOW() - INTERVAL 1 MONTH)";
    }

    try {
        $sql = "
            SELECT u.id, u.display_name, u.level, u.avatar_filename, COUNT(*) AS count
            FROM reports r
            JOIN users u ON u.id = r.user_id
            WHERE COALESCE(u.profile_public, 1) = 1
              AND r.category = :cat
              $where
            GROUP BY u.id, u.display_name, u.level, u.avatar_filename
            HAVING count > 0
            ORDER BY count DESC
            LIMIT $limit
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute([':cat' => $category]);
        return $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function get_user_rank(string $period, int $userId): ?array {
    if ($userId <= 0) return null;
    $where = '';
    if ($period === 'week') {
        $where = "AND x.created_at >= (NOW() - INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $where = "AND x.created_at >= (NOW() - INTERVAL 1 MONTH)";
    }

    try {
        $sqlPoints = "
            SELECT SUM(x.points) AS points
            FROM user_xp_log x
            JOIN users u ON u.id = x.user_id
            WHERE x.user_id = :uid
              AND (COALESCE(u.profile_public, 1) = 1 OR u.id = :uid)
              $where
        ";
        $stmt = db()->prepare($sqlPoints);
        $stmt->execute([':uid' => $userId]);
        $points = (int)($stmt->fetchColumn() ?: 0);
        if ($points <= 0) return null;

        $sqlRank = "
            SELECT COUNT(*) + 1 AS rank
            FROM (
              SELECT u.id, SUM(x.points) AS points
              FROM user_xp_log x
              JOIN users u ON u.id = x.user_id
              WHERE (COALESCE(u.profile_public, 1) = 1 OR u.id = :uid)
                $where
              GROUP BY u.id
              HAVING points > :p
            ) t
        ";
        $stmt = db()->prepare($sqlRank);
        $stmt->execute([':uid' => $userId, ':p' => $points]);
        $rank = (int)($stmt->fetchColumn() ?: 0);
        if ($rank <= 0) return null;

        return ['rank' => $rank, 'points' => $points];
    } catch (Throwable $e) {
        return null;
    }
}

function get_user_category_rank(string $period, int $userId, string $category): ?array {
    if ($userId <= 0 || $category === '') return null;
    $where = '';
    if ($period === 'week') {
        $where = "AND r.created_at >= (NOW() - INTERVAL 7 DAY)";
    } elseif ($period === 'month') {
        $where = "AND r.created_at >= (NOW() - INTERVAL 1 MONTH)";
    }

    try {
        $sqlCount = "
            SELECT COUNT(*) AS count
            FROM reports r
            JOIN users u ON u.id = r.user_id
            WHERE r.user_id = :uid
              AND r.category = :cat
              AND (COALESCE(u.profile_public, 1) = 1 OR u.id = :uid)
              $where
        ";
        $stmt = db()->prepare($sqlCount);
        $stmt->execute([':uid' => $userId, ':cat' => $category]);
        $count = (int)($stmt->fetchColumn() ?: 0);
        if ($count <= 0) return null;

        $sqlRank = "
            SELECT COUNT(*) + 1 AS rank
            FROM (
              SELECT u.id, COUNT(*) AS count
              FROM reports r
              JOIN users u ON u.id = r.user_id
              WHERE (COALESCE(u.profile_public, 1) = 1 OR u.id = :uid)
                AND r.category = :cat
                $where
              GROUP BY u.id
              HAVING count > :c
            ) t
        ";
        $stmt = db()->prepare($sqlRank);
        $stmt->execute([':uid' => $userId, ':cat' => $category, ':c' => $count]);
        $rank = (int)($stmt->fetchColumn() ?: 0);
        if ($rank <= 0) return null;

        return ['rank' => $rank, 'count' => $count];
    } catch (Throwable $e) {
        return null;
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

function check_category_badges(int $userId, string $category): void {
    $map = [
        'road' => ['code' => 'bad_katyuvadasz', 'need' => 10],
        'trash' => ['code' => 'bad_szemet_szemle', 'need' => 15],
        'lighting' => ['code' => 'bad_lampas_ember', 'need' => 5],
        'green' => ['code' => 'bad_zold_ujju', 'need' => 10],
    ];
    if (!isset($map[$category])) return;
    $need = (int)$map[$category]['need'];
    $code = (string)$map[$category]['code'];

    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM reports WHERE user_id = :uid AND category = :cat");
        $stmt->execute([':uid' => $userId, ':cat' => $category]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt >= $need) {
            award_badge($userId, $code);
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function check_description_badge(int $userId, int $descLen): void {
    if ($descLen < 300) return;
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM reports WHERE user_id = :uid AND CHAR_LENGTH(description) >= 300");
        $stmt->execute([':uid' => $userId]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt >= 5) {
            award_badge($userId, 'bad_diktalas');
        }
    } catch (Throwable $e) {
        // ignore
    }
}

function check_gps_badge(int $userId, bool $isPrecise): void {
    if (!$isPrecise) return;
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM reports WHERE user_id = :uid AND (lat REGEXP '\\\\.[0-9]{5,}$' AND lng REGEXP '\\\\.[0-9]{5,}$')");
        $stmt->execute([':uid' => $userId]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt >= 10) {
            award_badge($userId, 'bad_terkepesz');
        }
    } catch (Throwable $e) {
        // ignore
    }
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