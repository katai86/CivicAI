<?php
require_once __DIR__ . '/../util.php';

start_secure_session();

$currentLang = current_lang();
$uid = 0;
$role = 'guest';
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('auth.register_thanks_title'), ENT_QUOTES, 'UTF-8') ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){}</script>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="page auth-page">
<?php require __DIR__ . '/../inc_desktop_topbar.php'; ?>
<div class="auth-wrap">
  <div class="card">
    <h3 style="margin:0 0 10px"><?= htmlspecialchars(t('auth.register_thanks_title'), ENT_QUOTES, 'UTF-8') ?></h3>
    <p><?= htmlspecialchars(t('auth.register_thanks_message'), ENT_QUOTES, 'UTF-8') ?></p>
    <div style="margin-top:12px"><a href="<?= htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>" class="primary" style="display:inline-block;padding:8px 16px"><?= htmlspecialchars(t('auth.register_thanks_login'), ENT_QUOTES, 'UTF-8') ?></a></div>
  </div>
</div>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
