<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

if (!empty($_GET['lang'])) {
  set_lang((string)$_GET['lang']);
}
$lang = current_lang();

$token = trim($_GET['token'] ?? '');
$ok = false;
if ($token !== '' && strlen($token) >= 10) {
  $stmt = db()->prepare("UPDATE users SET is_verified=1, verify_token=NULL WHERE verify_token=:t");
  $stmt->execute([':t'=>$token]);
  $ok = $stmt->rowCount() > 0;
}
?><!doctype html>
<html lang="<?= h($lang) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>CivicAI – <?= h(t('verify.title')) ?></title>
<script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');}</script>
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="page auth-page">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b>CivicAI</b>
    </a>
    <div class="topbar-links">
      <a class="topbtn" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= h(t('nav.map')) ?></a>
      <a class="topbtn" href="<?= htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>"><?= h(t('nav.login')) ?></a>
    </div>
  </div>
</header>
<div class="auth-wrap">
  <div class="card">
    <h3 style="margin:0 0 10px"><?= h(t('verify.title')) ?></h3>
    <?php if ($token === '' || strlen($token) < 10): ?>
      <div class="err"><?= h(t('verify.bad_token')) ?></div>
    <?php elseif ($ok): ?>
      <div class="ok"><?= h(t('verify.ok')) ?></div>
    <?php else: ?>
      <div class="err"><?= h(t('verify.invalid_token')) ?></div>
    <?php endif; ?>
    <div style="margin-top:10px">
      <a class="btn" href="<?= htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>"><?= h(t('nav.login')) ?></a>
    </div>
  </div>
</div>
</body></html>
