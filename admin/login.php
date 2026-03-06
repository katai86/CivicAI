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

  $loggedIn = false;

  // 1) Users tábla: ha email formátum ( tartalmaz @ ), próbáljuk users táblából admin/superadmin-ként
  if (strpos($u, '@') !== false && $p !== '') {
    require_once __DIR__ . '/../db.php';
    try {
      $stmt = db()->prepare("SELECT id, pass_hash, role, is_active FROM users WHERE email = :e LIMIT 1");
      $stmt->execute([':e' => mb_strtolower($u)]);
      $row = $stmt->fetch();
      if ($row && password_verify($p, (string)$row['pass_hash'])) {
        $role = (string)($row['role'] ?? '');
        if (in_array($role, ['admin', 'superadmin'], true)) {
          if (isset($row['is_active']) && (int)$row['is_active'] === 0) {
            $error = 'A fiók le van tiltva.';
          } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$row['id'];
            $_SESSION['user_role'] = $role;
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user'] = $u;
            $loggedIn = true;
          }
        }
      }
    } catch (Throwable $e) {
      // fallback to config login
    }
  }

  // 2) Config alapú belépés (üzemeltetési / helyreállítási belépés)
  if (!$loggedIn && defined('ADMIN_USER') && defined('ADMIN_PASS')) {
    if (hash_equals((string)ADMIN_USER, (string)$u) && hash_equals((string)ADMIN_PASS, (string)$p)) {
      session_regenerate_id(true);
      $_SESSION['admin_logged_in'] = true;
      $_SESSION['admin_user'] = $u;
      $_SESSION['user_role'] = 'superadmin';
      $loggedIn = true;
    }
  }

  if ($loggedIn) {
    header('Location: ' . app_url('/admin/index.php'));
    exit;
  }

  if (!$error) {
    $error = 'Hibás belépési adatok.';
  }
}
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Köz.Tér – Admin belépés</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
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
      <input name="user" type="text" placeholder="E-mail vagy felhasználónév" autocomplete="username" required>
      <input name="pass" type="password" placeholder="Jelszó" autocomplete="current-password" required>
      <button type="submit" class="primary">Belépés</button>
    </form>
    <p class="muted" style="margin-top:8px;font-size:0.9em;">Admin/superadmin fiókkal e-mail + jelszó, vagy üzemeltetési belépés.</p>
    <p style="margin-top:8px;"><a href="<?= htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>">Belépés a térképre (felhasználói fiók)</a></p>
  </div>
</div>
</body>
</html>