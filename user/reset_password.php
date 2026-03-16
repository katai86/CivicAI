<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$ok = false;
$err = null;
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '') {
  $pass = (string)($_POST['pass'] ?? '');
  $pass2 = (string)($_POST['pass2'] ?? '');
  if (strlen($pass) < 8) {
    $err = 'auth.password_short';
  } elseif ($pass !== $pass2) {
    $err = 'auth.password_mismatch';
  } else {
    try {
      $stmt = db()->prepare("SELECT id FROM users WHERE reset_token = :t AND reset_token_expires > NOW() LIMIT 1");
      $stmt->execute([':t' => $token]);
      $u = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$u) {
        $err = 'auth.reset_token_invalid';
      } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt2 = db()->prepare("UPDATE users SET pass_hash = :h, reset_token = NULL, reset_token_expires = NULL WHERE id = :id");
        $stmt2->execute([':h' => $hash, ':id' => (int)$u['id']]);
        $done = true;
        $_SESSION['flash'] = t('auth.reset_password_success');
        header('Location: ' . app_url('/user/login.php'));
        exit;
      }
    } catch (Throwable $e) {
      log_error('reset_password: ' . $e->getMessage());
      $err = 'auth.login_error';
    }
  }
} elseif ($token === '') {
  $err = 'auth.reset_token_invalid';
} else {
  try {
    $stmt = db()->prepare("SELECT id FROM users WHERE reset_token = :t AND reset_token_expires > NOW() LIMIT 1");
    $stmt->execute([':t' => $token]);
    $ok = $stmt->fetch() !== false;
  } catch (Throwable $e) {
    $ok = false;
  }
}

$currentLang = current_lang();
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('auth.reset_password_title'), ENT_QUOTES, 'UTF-8') ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){}</script>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="page auth-page">
<?php require __DIR__ . '/../inc_desktop_topbar.php'; ?>
<div class="auth-wrap">
  <div class="card">
    <h3 style="margin:0 0 10px"><?= htmlspecialchars(t('auth.reset_password_title'), ENT_QUOTES, 'UTF-8') ?></h3>
    <?php if ($done): ?>
      <div class="ok"><?= htmlspecialchars(t('auth.reset_password_success'), ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif (!$ok && $token !== '' && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
      <div class="err"><?= htmlspecialchars(t('auth.reset_token_invalid'), ENT_QUOTES, 'UTF-8') ?></div>
      <div style="margin-top:12px"><a href="<?= htmlspecialchars(app_url('/user/forgot_password.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('auth.forgot_password_title'), ENT_QUOTES, 'UTF-8') ?></a></div>
    <?php elseif ($ok || ($token !== '' && $_SERVER['REQUEST_METHOD'] === 'POST')): ?>
      <?php if ($err): ?><div class="err"><?= htmlspecialchars(t($err), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <form method="post">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
        <input type="password" name="pass" placeholder="<?= htmlspecialchars(t('auth.password_new'), ENT_QUOTES, 'UTF-8') ?>" required minlength="8" autocomplete="new-password">
        <input type="password" name="pass2" placeholder="<?= htmlspecialchars(t('auth.password_confirm'), ENT_QUOTES, 'UTF-8') ?>" required minlength="8" autocomplete="new-password">
        <button type="submit" class="primary"><?= htmlspecialchars(t('auth.reset_password_submit'), ENT_QUOTES, 'UTF-8') ?></button>
      </form>
    <?php else: ?>
      <div class="err"><?= htmlspecialchars(t('auth.reset_token_invalid'), ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <div style="margin-top:12px"><a href="<?= htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('auth.back_to_login'), ENT_QUOTES, 'UTF-8') ?></a></div>
  </div>
</div>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
