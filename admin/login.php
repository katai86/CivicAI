<?php
try {
  require_once __DIR__ . '/../util.php';
  start_secure_session();
  if (!empty($_GET['lang'])) {
    set_lang((string)$_GET['lang']);
  }
} catch (Throwable $e) {
  if (function_exists('log_error')) @log_error('Admin login bootstrap: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
  header('Content-Type: text/html; charset=utf-8');
  http_response_code(500);
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body style="font-family:sans-serif;padding:2rem;max-width:600px;">';
  echo '<h1>Error</h1><p><strong>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</strong></p>';
  echo '<p>' . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') . ' (' . (int)$e->getLine() . ')</p></body></html>';
  exit;
}

register_shutdown_function(function () {
  $err = error_get_last();
  if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) return;
  if (headers_sent()) return;
  header('Content-Type: text/html; charset=utf-8');
  http_response_code(500);
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>500</title></head><body style="font-family:sans-serif;padding:2rem;max-width:640px;">';
  echo '<h1>PHP error</h1><p><strong>' . htmlspecialchars($err['message'], ENT_QUOTES, 'UTF-8') . '</strong></p>';
  echo '<p>' . htmlspecialchars($err['file'], ENT_QUOTES, 'UTF-8') . ' (' . (int)$err['line'] . ')</p></body></html>';
});

$error = null;
$lang = current_lang();

if (!empty($_SESSION['admin_logged_in'])) {
  header('Location: ' . app_url('/admin/index.php'));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim((string)($_POST['user'] ?? ''));
  $p = (string)($_POST['pass'] ?? '');
  $loggedIn = false;

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
            $error = t('admin.login_disabled');
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
    }
  }

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
    $error = t('admin.login_error');
  }
}
?>
<!doctype html>
<html lang="<?= h($lang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>CivicAI – <?= h(t('admin.login_title')) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="page auth-page">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b><?= h(t('admin.brand_title')) ?></b>
    </a>
  </div>
</header>
<div class="auth-wrap">
  <div class="card">
    <h1><?= h(t('admin.login_title')) ?></h1>
    <?php if ($error): ?><div class="err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <form method="post">
      <input name="user" type="text" placeholder="<?= h(t('admin.login_user_placeholder')) ?>" autocomplete="username" required>
      <input name="pass" type="password" placeholder="<?= h(t('admin.login_pass_placeholder')) ?>" autocomplete="current-password" required>
      <button type="submit" class="primary"><?= h(t('admin.login_submit')) ?></button>
    </form>
    <p class="muted" style="margin-top:8px;font-size:0.9em;"><?= h(t('admin.login_hint')) ?></p>
    <p style="margin-top:8px;"><a href="<?= htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>"><?= h(t('admin.login_user_link')) ?></a></p>
  </div>
</div>
</body>
</html>
