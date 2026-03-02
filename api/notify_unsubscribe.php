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
  <title>Leiratkozás</title>
  <style>
    body{font:14px system-ui;background:#f6f7f9;margin:0;display:grid;place-items:center;height:100vh}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:18px;max-width:520px}
    a{color:#2563eb;text-decoration:none}
  </style>
</head>
<body>
  <div class="card">
    <h3 style="margin:0 0 10px">Értesítések</h3>
    <p style="margin:0 0 10px">
      <?= $ok ? 'Sikeres leiratkozás. Több e-mail értesítést nem küldünk ehhez a bejelentéshez.' : 'A token nem található, vagy már le lettél iratkozva.' ?>
    </p>
    <a href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">Vissza a térképhez</a>
  </div>
</body>
</html>