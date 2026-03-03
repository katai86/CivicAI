<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

$token = trim($_GET['token'] ?? '');
if ($token === '' || strlen($token) < 16) {
  http_response_code(400);
  echo "Hibás token.";
  exit;
}

$stmt = db()->prepare("
  UPDATE reports
  SET notify_enabled = 0
  WHERE notify_token = :t
");
$stmt->execute([':t' => $token]);

$ok = $stmt->rowCount() > 0;

?><!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Köz.Tér – Értesítések</title>
  <link rel="stylesheet" href="/terkep/assets/style.css">
</head>
<body class="page auth-page">
  <header class="topbar">
    <div class="topbar-inner">
      <a class="brand brand-link" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">
        <span class="brand-logo" aria-hidden="true"></span>
        <b>Köz.Tér</b>
      </a>
      <div class="topbar-links">
        <a class="topbtn" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">Térkép</a>
      </div>
    </div>
  </header>
  <div class="auth-wrap">
  <div class="card">
    <h3 style="margin:0 0 10px">Értesítések</h3>
    <p style="margin:0 0 10px">
      <?= $ok ? 'Sikeres leiratkozás. Több e-mail értesítést nem küldünk ehhez a bejelentéshez.' : 'A token nem található, vagy már le lettél iratkozva.' ?>
    </p>
    <a class="btn" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">Vissza a térképhez</a>
  </div>
  </div>
</body>
</html>