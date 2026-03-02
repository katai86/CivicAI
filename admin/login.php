<?php
require_once __DIR__ . '/../util.php';

start_secure_session();

$error = null;

// Ha már beléptél, irány az admin
if (!empty($_SESSION['admin_logged_in'])) {
  header('Location: ' . app_url('/admin/index.php'));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim((string)($_POST['user'] ?? ''));
  $p = (string)($_POST['pass'] ?? '');

  if (hash_equals((string)ADMIN_USER, (string)$u) && hash_equals((string)ADMIN_PASS, (string)$p)) {
    session_regenerate_id(true);

    // FONTOS: util.php -> require_admin() ezt a kulcsot ellenőrzi
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_user'] = $u;

    header('Location: ' . app_url('/admin/index.php'));
    exit;
  } else {
    $error = 'Hibás belépési adatok.';
  }
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin belépés</title>
  <style>
    body{font:14px system-ui;background:#f6f7f9;margin:0}
    .wrap{max-width:420px;margin:0 auto;padding:80px 16px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:18px}
    h1{font-size:18px;margin:0 0 12px 0}
    input{width:100%;padding:10px 12px;border:1px solid #e5e7eb;border-radius:12px;margin:6px 0}
    button{width:100%;padding:10px 12px;border:0;border-radius:12px;background:#2563eb;color:#fff;font-weight:700;margin-top:8px}
    .err{background:#fee2e2;color:#991b1b;padding:10px 12px;border-radius:12px;margin:10px 0}
    .muted{color:#6b7280;font-size:12px;margin-top:10px}
  </style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Admin belépés</h1>
    <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <form method="post">
      <input name="user" placeholder="Felhasználó" autocomplete="username" required>
      <input name="pass" type="password" placeholder="Jelszó" autocomplete="current-password" required>
      <button type="submit">Belépés</button>
    </form>
    <div class="muted">Problématérkép admin</div>
  </div>
</div>
</body>
</html>