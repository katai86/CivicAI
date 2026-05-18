<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = mb_strtolower(trim($_POST['email'] ?? ''));
  $pass  = (string)($_POST['pass'] ?? '');
  $u = null;

  try {
    $lookupQueries = [
      'SELECT id, pass_hash, is_verified, role, is_active, preferred_lang FROM users WHERE email=:e LIMIT 1',
      'SELECT id, pass_hash, role, is_active FROM users WHERE email=:e LIMIT 1',
      'SELECT id, pass_hash, role FROM users WHERE email=:e LIMIT 1',
    ];
    $lookupFailed = true;
    foreach ($lookupQueries as $sql) {
      try {
        $stmt = db()->prepare($sql);
        $stmt->execute([':e' => $email]);
        $u = $stmt->fetch();
        $lookupFailed = false;
        if ($u) {
          if (!isset($u['is_active'])) {
            $u['is_active'] = 1;
          }
          if (!array_key_exists('preferred_lang', $u)) {
            $u['preferred_lang'] = null;
          }
        }
        break;
      } catch (PDOException $e) {
        log_error('login: DB connection failed - ' . $e->getMessage());
        $err = 'auth.login_db_unavailable';
        $u = null;
        break;
      } catch (Throwable $e) {
        continue;
      }
    }
    if ($lookupFailed && $err === null) {
      log_error('login: user lookup queries failed for ' . $email);
      $err = 'auth.login_db_unavailable';
      $u = null;
    }

    if ($err === null && !$u) {
      $err = 'auth.login_error';
    } elseif ($err === null) {
      $hash = trim((string)($u['pass_hash'] ?? ''));
      if ($hash === '' || strlen($hash) < 60 || !password_verify($pass, $hash)) {
        $err = 'auth.login_error';
      } elseif (isset($u['is_active']) && (int)$u['is_active'] === 0) {
        $err = 'auth.account_disabled';
      }
    }
    if ($err === null && $u) {
      session_regenerate_id(true);
      $_SESSION['user_id'] = (int)$u['id'];
      $_SESSION['user_role'] = $u['role'] ? (string)$u['role'] : 'user';
      if (!empty($u['preferred_lang']) && defined('LANG_ALLOWED') && is_array(LANG_ALLOWED) && in_array($u['preferred_lang'], LANG_ALLOWED, true)) {
        set_lang($u['preferred_lang']);
      }
      if (in_array($_SESSION['user_role'], ['admin', 'superadmin'], true)) {
        $_SESSION['admin_logged_in'] = true;
      }
      $redirect = trim((string)($_GET['redirect'] ?? ''));
      // Only allow relative paths (no protocol, no host)
      if ($redirect !== '' && $redirect[0] === '/' && strpos($redirect, '//') === false) {
        $basePath = defined('APP_BASE') ? rtrim(APP_BASE, '/') : '';
        if ($basePath !== '' && strpos($redirect, $basePath) === 0) {
          $redirect = substr($redirect, strlen($basePath)) ?: '/';
        }
        header('Location: ' . app_url($redirect));
      } elseif ($redirect !== '' && strpos($redirect, 'admin') !== false && in_array($_SESSION['user_role'], ['admin', 'superadmin'], true)) {
        header('Location: ' . app_url('/admin/index.php'));
      } else {
        header('Location: ' . app_url('/'));
      }
      exit;
    }
  } catch (PDOException $e) {
    log_error('login: DB - ' . $e->getMessage());
    $err = 'auth.login_db_unavailable';
  } catch (Throwable $e) {
    log_error('login: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $err = 'auth.login_error';
  }
}
$currentLang = current_lang();
$isMobile = function_exists('use_mobile_layout') ? use_mobile_layout() : (function_exists('is_mobile_device') && is_mobile_device());
$uid = 0;
$role = 'guest';
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0f1721">
<link rel="icon" type="image/png" href="<?= htmlspecialchars(app_url('/assets/fav_icon.png'), ENT_QUOTES, 'UTF-8') ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars(app_url('/assets/fav_icon.png'), ENT_QUOTES, 'UTF-8') ?>">
<title><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('auth.login_title'), ENT_QUOTES, 'UTF-8') ?></title>
<script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');document.documentElement.setAttribute('data-bs-theme','dark');}</script>
<?php if ($isMobile): ?>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('/Mobilekit_v2-9-1/HTML/assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($isMobile): ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/mobilekit_civicai.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
<?php endif; ?>
</head>
<body class="page auth-page<?= $isMobile ? ' civicai-mobile' : '' ?>">
<?php if ($isMobile): ?>
  <?php $mobilePageTitle = t('auth.login_title'); $mobileActiveTab = 'profile'; $uid = 0; $role = 'guest'; $mobileBackUrl = app_url('/'); require __DIR__ . '/../inc_mobile_header.php'; ?>
<?php else: ?>
<?php require __DIR__ . '/../inc_desktop_topbar.php'; ?>
<?php endif; ?>
<?php if (!$isMobile): ?>
<div class="auth-wrap">
<?php endif; ?>
<div class="card">
  <h3 style="margin:0 0 10px"><?= htmlspecialchars(t('auth.login_title'), ENT_QUOTES, 'UTF-8') ?></h3>
  <?php if($err): ?><div class="err"><?= htmlspecialchars(t($err), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <form method="post">
    <input name="email" placeholder="<?= htmlspecialchars(t('auth.email'), ENT_QUOTES, 'UTF-8') ?>" required>
    <input name="pass" type="password" placeholder="<?= htmlspecialchars(t('auth.password'), ENT_QUOTES, 'UTF-8') ?>" required>
    <button type="submit" class="primary"><?= htmlspecialchars(t('auth.login_title'), ENT_QUOTES, 'UTF-8') ?></button>
  </form>
  <div style="margin-top:10px"><a href="<?= htmlspecialchars(app_url('/user/forgot_password.php')) ?>"><?= htmlspecialchars(t('auth.forgot_password_title'), ENT_QUOTES, 'UTF-8') ?></a></div>
  <div style="margin-top:6px"><a href="<?= htmlspecialchars(app_url('/user/register.php')) ?>"><?= htmlspecialchars(t('nav.register'), ENT_QUOTES, 'UTF-8') ?></a></div>
</div>
<?php if (!$isMobile): ?>
</div>
<?php endif; ?>
<?php if ($isMobile): ?>
  <?php require __DIR__ . '/../inc_mobile_footer.php'; ?>
  <script src="<?= htmlspecialchars(app_url('/Mobilekit_v2-9-1/HTML/assets/js/lib/bootstrap.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(app_url('/Mobilekit_v2-9-1/HTML/assets/js/base.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body></html>