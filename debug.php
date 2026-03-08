<?php
/**
 * Egyetlen cél: megmutatni a PHP hibát. Használat után TÖRÖLD vagy ne töltsd fel élesbe!
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
header('Content-Type: text/html; charset=utf-8');

echo "<h1>Debug – CivicAI</h1>\n";
echo "<p>PHP verzió: <strong>" . PHP_VERSION . "</strong></p>\n";

$steps = [];
try {
    $steps[] = 'config.php betöltése...';
    require_once __DIR__ . '/config.php';
    $steps[] = 'config OK (APP_BASE_URL beállítva)';

    $steps[] = 'util.php betöltése...';
    require_once __DIR__ . '/util.php';
    $steps[] = 'util OK';

    $steps[] = 'start_secure_session()...';
    start_secure_session();
    $steps[] = 'session OK';

    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $role = current_user_role() ?: 'guest';
    $steps[] = "uid=$uid, role=" . htmlspecialchars($role);

    if ($uid > 0 && function_exists('get_user_rank')) {
        $rankAll = get_user_rank('all', $uid);
        $steps[] = 'get_user_rank OK';
    } else {
        $steps[] = 'get_user_rank kihagyva (nincs uid vagy nincs függvény)';
    }
} catch (Throwable $e) {
    echo "<h2 style='color:red'>Hiba</h2>";
    echo "<pre>" . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString()) . "</pre>";
}

echo "<h2>Lépések</h2><ul>";
foreach ($steps as $s) {
    echo "<li>" . htmlspecialchars($s) . "</li>";
}
echo "</ul>";
echo "<p><small>Ha minden OK, a főoldal hibája máshol van (pl. index.php szintaxis). <strong>Töröld ezt a fájlt élesben!</strong></small></p>";
