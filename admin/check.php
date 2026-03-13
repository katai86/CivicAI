<?php
/**
 * Minimál admin ellenőrzés: config + util betöltése.
 * Ha ez betölt (OK), a hiba az index.php/login.php konkrét kódjában van.
 * URL: /CivicAI/admin/check.php
 */
header('Content-Type: text/plain; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "1. Config... ";
try {
    require_once __DIR__ . '/../config.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "HIBA: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit;
}

echo "2. Util... ";
try {
    require_once __DIR__ . '/../util.php';
    echo "OK\n";
} catch (Throwable $e) {
    echo "HIBA: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit;
}

echo "3. APP_BASE=" . (defined('APP_BASE') ? APP_BASE : '(nincs)') . "\n";
echo "4. Session... ";
try {
    start_secure_session();
    echo "OK\n";
} catch (Throwable $e) {
    echo "HIBA: " . $e->getMessage() . "\n";
    exit;
}

echo "\nMinden alap betöltve. Ha /admin/ még 500, a hiba az index.php vagy a session/redirect logikában van.\n";
