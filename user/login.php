<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = mb_strtolower(trim($_POST['email'] ?? ''));
  $pass  = (string)($_POST['pass'] ?? '');

  try {
    $stmt = db()->prepare("SELECT id, pass_hash, is_verified, role, is_active, preferred_lang FROM users WHERE email=:e LIMIT 1");
    $stmt->execute([':e'=>$email]);
    $u = $stmt->fetch();
  } catch (Throwable $e) {
    $stmt = db()->prepare("SELECT id, pass_hash, is_verified, role FROM users WHERE email=:e LIMIT 1");
    $stmt->execute([':e'=>$email]);
    $u = $stmt->fetch();
    if ($u) { $u['is_active'] = 1; $u['preferred_lang'] = null; }
  }

  if (!$u || !password_verify($pass, $u['pass_hash'])) {
    $err = 'auth.login_error';
  } elseif (isset($u['is_active']) && (int)$u['is_active'] === 0) {
    $err = 'auth.account_disabled';
  } else {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['user_role'] = $u['role'] ? (string)$u['role'] : 'user';
    if (!empty($u['preferred_lang']) && in_array($u['preferred_lang'], LANG_ALLOWED, true)) {
      set_lang($u['preferred_lang']);
    }
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
$currentLang = current_lang();
// Mobil shell csak mobil eszközön
$isMobile = function_exists('is_mobile_device') ? is_mobile_device() : false;
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0f1721">
<title><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('auth.login_title'), ENT_QUOTES, 'UTF-8') ?></title>
<script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');document.documentElement.setAttribute('data-bs-theme','dark');}</script>
<?php if ($isMobile): ?>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('/Mobilekit_v2-9-1/HTML/assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($isMobile): ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/mobilekit_civicai.css'), ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>
</head>
<body class="page auth-page<?= $isMobile ? ' civicai-mobile' : '' ?>">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?></b>
    </a>
    <?php $currentLang = current_lang(); include __DIR__ . '/inc_topbar_tools.php'; ?>
      <a class="topbtn" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.map'), ENT_QUOTES, 'UTF-8') ?></a>
      <a class="topbtn primary" href="<?= htmlspecialchars(app_url('/user/register.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.register'), ENT_QUOTES, 'UTF-8') ?></a>
    </div>
  </div>
</header>
<div class="auth-wrap">
<div class="card">
  <h3 style="margin:0 0 10px"><?= htmlspecialchars(t('auth.login_title'), ENT_QUOTES, 'UTF-8') ?></h3>
  <?php if($err): ?><div class="err"><?= htmlspecialchars(t($err), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form method="post">
    <input name="email" placeholder="<?= htmlspecialchars(t('auth.email'), ENT_QUOTES, 'UTF-8') ?>" required>
    <input name="pass" type="password" placeholder="<?= htmlspecialchars(t('auth.password'), ENT_QUOTES, 'UTF-8') ?>" required>
    <button type="submit" class="primary"><?= htmlspecialchars(t('auth.login_title'), ENT_QUOTES, 'UTF-8') ?></button>
  </form>
  <div style="margin-top:10px"><a href="<?= htmlspecialchars(app_url('/user/register.php')) ?>"><?= htmlspecialchars(t('nav.register'), ENT_QUOTES, 'UTF-8') ?></a></div>
</div>
</div>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body></html>