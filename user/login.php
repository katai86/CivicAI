<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = mb_strtolower(trim($_POST['email'] ?? ''));
  $pass  = (string)($_POST['pass'] ?? '');

  try {
    $stmt = db()->prepare("SELECT id, pass_hash, is_verified, role, is_active FROM users WHERE email=:e LIMIT 1");
    $stmt->execute([':e'=>$email]);
    $u = $stmt->fetch();
  } catch (Throwable $e) {
    $stmt = db()->prepare("SELECT id, pass_hash, is_verified, role FROM users WHERE email=:e LIMIT 1");
    $stmt->execute([':e'=>$email]);
    $u = $stmt->fetch();
    if ($u) $u['is_active'] = 1;
  }

  if (!$u || !password_verify($pass, $u['pass_hash'])) {
    $err = 'Hibás belépési adatok.';
  } elseif (isset($u['is_active']) && (int)$u['is_active'] === 0) {
    $err = 'A fiók le van tiltva.';
  } else {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['user_role'] = $u['role'] ? (string)$u['role'] : 'user';
    // Admin/superadmin egy belépéssel mindkét felületet használhatja (production: egy fiók, egy rendszer)
    if (in_array($_SESSION['user_role'], ['admin', 'superadmin'], true)) {
      $_SESSION['admin_logged_in'] = true;
    }
    $redirect = trim((string)($_GET['redirect'] ?? ''));
    if ($redirect !== '' && strpos($redirect, 'admin') !== false && in_array($_SESSION['user_role'], ['admin', 'superadmin'], true)) {
      header('Location: ' . app_url('/admin/index.php'));
    } else {
      header('Location: ' . app_url('/'));
    }
    exit;
  }
}
?>
<!doctype html>
<html lang="hu"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Köz.Tér – Belépés</title>
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
      <a class="topbtn primary" href="<?= htmlspecialchars(app_url('/user/register.php'), ENT_QUOTES, 'UTF-8') ?>">Regisztráció</a>
    </div>
  </div>
</header>
<div class="auth-wrap">
<div class="card">
  <h3 style="margin:0 0 10px">Belépés</h3>
  <?php if($err): ?><div class="err"><?= htmlspecialchars($err,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <form method="post">
    <input name="email" placeholder="E-mail" required>
    <input name="pass" type="password" placeholder="Jelszó" required>
    <button type="submit" class="primary">Belépés</button>
  </form>
  <div style="margin-top:10px"><a href="<?= htmlspecialchars(app_url('/user/register.php')) ?>">Regisztráció</a></div>
</div>
</div>
</body></html>