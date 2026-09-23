<?php
/**
 * Egyszeri DB diagnosztika. Használat után TÖRÖLD az éles szerverről!
 * URL: /CivicAI/check_db.php?k=<ADMIN_PASS a config.local.php-ből>
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

$key = (string)($_GET['k'] ?? '');
$allowed = defined('ADMIN_PASS') && ADMIN_PASS !== '' && hash_equals((string)ADMIN_PASS, $key);
if (!$allowed) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden – add ?k=ADMIN_PASS']);
    exit;
}

$out = [
    'ok' => false,
    'php' => PHP_VERSION,
    'env_file' => is_readable(__DIR__ . '/.env'),
    'local_config' => is_readable(__DIR__ . '/config.local.php'),
    'dotenv_loader' => is_file(__DIR__ . '/inc/dotenv.php'),
    'db_host' => defined('DB_HOST') ? DB_HOST : null,
    'db_name' => defined('DB_NAME') ? DB_NAME : null,
    'db_user' => defined('DB_USER') ? DB_USER : null,
    'db_pass_set' => defined('DB_PASS') && DB_PASS !== '',
    'db_pass_len' => defined('DB_PASS') ? strlen((string)DB_PASS) : 0,
    'app_base_url' => defined('APP_BASE_URL') ? APP_BASE_URL : null,
];

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->query('SELECT 1');
    $out['ok'] = true;
    $out['message'] = 'MySQL connection OK';
    try {
        $n = (int)$pdo->query('SELECT COUNT(*) FROM module_settings')->fetchColumn();
        $out['module_settings_rows'] = $n;
    } catch (Throwable $e) {
        $out['module_settings_rows'] = null;
        $out['module_settings_note'] = 'table missing or not readable';
    }
} catch (PDOException $e) {
    $out['pdo_error'] = $e->getMessage();
    $msg = $e->getMessage();
    if (stripos($msg, 'Access denied') !== false) {
        $out['hint'] = 'Rossz DB_USER vagy DB_PASS (vagy üres jelszó a configban).';
    } elseif (stripos($msg, 'Unknown database') !== false) {
        $out['hint'] = 'Rossz DB_NAME – nézd meg a tárhely panel MySQL adatbázis nevét.';
    } elseif (stripos($msg, 'could not find driver') !== false) {
        $out['hint'] = 'A szerveren nincs PDO MySQL driver (pdo_mysql).';
    } else {
        $out['hint'] = 'Ellenőrizd a DB_HOST értékét (néha nem localhost, hanem pl. mysql.domain.hu).';
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
