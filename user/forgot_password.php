<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$sent = false;
$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = mb_strtolower(trim($_POST['email'] ?? ''));
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $err = 'auth.email_invalid';
  } else {
    try {
      $stmt = db()->prepare("SELECT id, display_name FROM users WHERE email = :e AND is_active = 1 LIMIT 1");
      $stmt->execute([':e' => $email]);
      $u = $stmt->fetch(PDO::FETCH_ASSOC);
      if ($u) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600); // 1 óra
        $stmt2 = db()->prepare("UPDATE users SET reset_token = :t, reset_token_expires = :exp WHERE id = :id");
        $stmt2->execute([':t' => $token, ':exp' => $expires, ':id' => (int)$u['id']]);
        $resetUrl = app_url('/user/reset_password.php?token=' . urlencode($token));
        $siteName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : (function_exists('t') ? t('site.name') : 'CivicAI');
        $subject = (function_exists('t') ? t('email.reset_password_subject') : 'Jelszó visszaállítás') . ' – ' . $siteName;
        $body = (function_exists('t') ? t('email.reset_password_hello') : 'Szia') . ' ' . htmlspecialchars($u['display_name'] ?: '', ENT_QUOTES, 'UTF-8') . ',<br><br>'
          . (function_exists('t') ? t('email.reset_password_body') : 'Kértél jelszó-visszaállítást. A link 1 óráig érvényes:')
          . '<br><br><a href="' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8') . '</a><br><br>'
          . (function_exists('t') ? t('email.reset_password_ignore') : 'Ha nem te kérted, hagyd figyelmen kívül ezt az e-mailt.');
        $html = email_template_html($subject, $body);
        send_mail_html($email, $subject, $html);
      }
      $sent = true;
    } catch (Throwable $e) {
      if (strpos($e->getMessage(), 'reset_token') !== false) {
        $err = 'auth.reset_not_available';
      } else {
        log_error('forgot_password: ' . $e->getMessage());
        $err = 'auth.login_error';
      }
    }
  }
}

$currentLang = current_lang();
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('auth.forgot_password_title'), ENT_QUOTES, 'UTF-8') ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){}</script>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="page auth-page">
<?php require __DIR__ . '/../inc_desktop_topbar.php'; ?>
<div class="auth-wrap">
  <div class="card">
    <h3 style="margin:0 0 10px"><?= htmlspecialchars(t('auth.forgot_password_title'), ENT_QUOTES, 'UTF-8') ?></h3>
    <?php if ($sent): ?>
      <div class="ok"><?= htmlspecialchars(t('auth.forgot_password_sent'), ENT_QUOTES, 'UTF-8') ?></div>
      <p class="small text-secondary"><?= htmlspecialchars(t('auth.forgot_password_check_spam'), ENT_QUOTES, 'UTF-8') ?></p>
    <?php else: ?>
      <?php if ($err): ?><div class="err"><?= htmlspecialchars(t($err), ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
      <p class="text-secondary small mb-2"><?= htmlspecialchars(t('auth.forgot_password_intro'), ENT_QUOTES, 'UTF-8') ?></p>
      <form method="post">
        <input type="email" name="email" placeholder="<?= htmlspecialchars(t('auth.email'), ENT_QUOTES, 'UTF-8') ?>" required autocomplete="email">
        <button type="submit" class="primary"><?= htmlspecialchars(t('auth.forgot_password_send'), ENT_QUOTES, 'UTF-8') ?></button>
      </form>
    <?php endif; ?>
    <div style="margin-top:12px"><a href="<?= htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('auth.back_to_login'), ENT_QUOTES, 'UTF-8') ?></a></div>
  </div>
</div>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>
