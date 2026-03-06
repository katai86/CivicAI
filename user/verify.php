<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

$token = trim($_GET['token'] ?? '');
$ok = false;
if ($token !== '' && strlen($token) >= 10) {
  $stmt = db()->prepare("UPDATE users SET is_verified=1, verify_token=NULL WHERE verify_token=:t");
  $stmt->execute([':t'=>$token]);
  $ok = $stmt->rowCount() > 0;
}
?><!doctype html>
<html lang="hu"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Köz.Tér – E-mail ellenőrzés</title>
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
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
      <a class="topbtn" href="<?= htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>">Belépés</a>
    </div>
  </div>
</header>
<div class="auth-wrap">
  <div class="card">
    <h3 style="margin:0 0 10px">E-mail ellenőrzés</h3>
    <?php if ($token === '' || strlen($token) < 10): ?>
      <div class="err">Hibás token.</div>
    <?php elseif ($ok): ?>
      <div class="ok">Sikeres e-mail ellenőrzés.</div>
    <?php else: ?>
      <div class="err">Token érvénytelen.</div>
    <?php endif; ?>
    <div style="margin-top:10px">
      <a class="btn" href="<?= htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>">Belépés</a>
    </div>
  </div>
</div>
</body></html>