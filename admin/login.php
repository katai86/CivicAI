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
    $_SESSION['user_role'] = 'superadmin';

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
  <link rel="stylesheet" href="/terkep/assets/style.css">
</head>
<body class="page auth-page">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b>Köz.Tér – Admin</b>
    </a>
  </div>
</header>
<div class="auth-wrap">
  <div class="card">
    <h1>Admin belépés</h1>
    <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <form method="post">
      <input name="user" placeholder="Felhasználó" autocomplete="username" required>
      <input name="pass" type="password" placeholder="Jelszó" autocomplete="current-password" required>
      <button type="submit" class="primary">Belépés</button>
    </form>
    <div class="muted">Problématérkép admin</div>
  </div>
</div>
</body>
</html>