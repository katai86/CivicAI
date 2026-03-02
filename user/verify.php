<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

$token = trim($_GET['token'] ?? '');
if ($token === '' || strlen($token) < 10) {
  echo "Hibás token."; exit;
}

$stmt = db()->prepare("UPDATE users SET is_verified=1, verify_token=NULL WHERE verify_token=:t");
$stmt->execute([':t'=>$token]);

echo $stmt->rowCount() ? "Sikeres email ellenőrzés. " : "Token érvénytelen. ";
echo '<a href="'.htmlspecialchars(app_url('/user/login.php')).'">Belépés</a>';