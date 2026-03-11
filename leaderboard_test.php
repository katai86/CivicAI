<?php
/**
 * Teszt: megmutatja a leaderboard betöltési hibát.
 * Futtasd: https://kataiattila.hu/CivicAI/leaderboard_test.php
 * Ha kész a javítás, töröld a fájlt a szerverről.
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>Leaderboard teszt</title></head><body><pre>\n";

$base = __DIR__;
echo "1. __DIR__ = " . htmlspecialchars($base) . "\n\n";

echo "2. config.php betöltése... ";
try {
  require_once $base . '/config.php';
  echo "OK\n";
} catch (Throwable $e) {
  echo "HIBA: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
  echo "</pre></body></html>";
  exit;
}

echo "3. db.php betöltése... ";
try {
  require_once $base . '/db.php';
  echo "OK\n";
} catch (Throwable $e) {
  echo "HIBA: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
  echo "</pre></body></html>";
  exit;
}

echo "4. DB kapcsolat (db())... ";
try {
  $pdo = db();
  echo "OK\n";
} catch (Throwable $e) {
  echo "HIBA: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
  echo "</pre></body></html>";
  exit;
}

echo "5. util.php betöltése... ";
try {
  require_once $base . '/util.php';
  echo "OK\n";
} catch (Throwable $e) {
  echo "HIBA: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
  echo "</pre></body></html>";
  exit;
}

echo "6. XpBadge.php betöltése... ";
try {
  require_once $base . '/services/XpBadge.php';
  echo "OK\n";
} catch (Throwable $e) {
  echo "HIBA: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
  echo "</pre></body></html>";
  exit;
}

echo "7. start_secure_session() + current_lang()... ";
try {
  start_secure_session();
  $lang = current_lang();
  echo "OK (lang=$lang)\n";
} catch (Throwable $e) {
  echo "HIBA: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
  echo "</pre></body></html>";
  exit;
}

echo "8. get_leaderboard('week', 10)... ";
try {
  $lb = get_leaderboard('week', 10);
  echo "OK (count=" . count($lb) . ")\n";
} catch (Throwable $e) {
  echo "HIBA: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
  echo "</pre></body></html>";
  exit;
}

echo "\nMinden lépés sikeres. A leaderboard.php-nek működnie kellene.\n";
echo "</pre></body></html>";
