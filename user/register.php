<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['pass'] ?? '');
  $name  = safe_str($_POST['name'] ?? null, 80);
  $role  = safe_str($_POST['role'] ?? null, 32) ?: 'user';
  $allowedRoles = ['user','govuser','communityuser','civiluser'];
  if (!in_array($role, $allowedRoles, true)) $role = 'user';
  // Önkormányzati regisztráció csak akkor engedélyezett, ha a superadmin bekapcsolta (config).
  if ($role === 'govuser' && !(defined('GOV_REGISTRATION_ENABLED') && GOV_REGISTRATION_ENABLED)) {
    $err = 'auth.gov_disabled';
    $role = 'user';
  }

  // GDPR / hozzájárulások
  $consentData      = !empty($_POST['consent_data']);      // kötelező
  $consentShare     = !empty($_POST['consent_share']);     // opcionális
  $consentMarketing = !empty($_POST['consent_marketing']); // opcionális

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'auth.email_invalid';
  elseif (strlen($pass) < 8) $err = 'auth.password_short';
  elseif (!$consentData) $err = 'auth.consent_required';
  else {
    $email_lc = mb_strtolower($email);
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(32));

    $ip = client_ip();
    $ipHash = $ip ? ip_hash($ip) : null;
    $ua = safe_str($_SERVER['HTTP_USER_AGENT'] ?? null, 255);

    // Ha később változik a szöveg, ezt emeljük (v2, v3...)
    $consentVersion = 'v1';

    try {
      $stmt = db()->prepare("
        INSERT INTO users
          (email, pass_hash, display_name, role, verify_token,
           consent_data, consent_share, consent_marketing,
           consent_version, consent_at, consent_ip_hash, consent_user_agent)
        VALUES
          (:e,:h,:n,:role,:t,
           :cd,:cs,:cm,
           :cv, NOW(), :ip, :ua)
      ");
      $stmt->execute([
        ':e'  => $email_lc,
        ':h'  => $hash,
        ':n'  => $name,
        ':role' => $role,
        ':t'  => $token,
        ':cd' => $consentData ? 1 : 0,
        ':cs' => $consentShare ? 1 : 0,
        ':cm' => $consentMarketing ? 1 : 0,
        ':cv' => $consentVersion,
        ':ip' => $ipHash,
        ':ua' => $ua,
      ]);

      $newUserId = (int)db()->lastInsertId();
      if ($newUserId > 0) {
        add_user_xp($newUserId, 10, 'register', null);
        award_badge($newUserId, 'level_1');
      }

      // MVP: email küldés később (D/E-nél).
      // Most kiírjuk a verify linket (admin/dev mód).
      $verifyUrl = app_url('/user/verify.php?token=' . $token);

      $_SESSION['flash'] = "Sikeres regisztráció. Ellenőrző link (MVP): " . $verifyUrl;
      header('Location: ' . app_url('/user/login.php'));
      exit;

} catch (Throwable $e) {
    $err = 'auth.email_taken';
  }
}
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$currentLang = current_lang();
$uid = 0;
$role = 'guest';
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('auth.register_title'), ENT_QUOTES, 'UTF-8') ?></title>
<script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');}</script>
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="page auth-page">
<?php require __DIR__ . '/../inc_desktop_topbar.php'; ?>
<div class="auth-wrap">
<div class="card">
  <h3 style="margin:0 0 10px"><?= htmlspecialchars(t('auth.register_title'), ENT_QUOTES, 'UTF-8') ?></h3>
  <?php if($flash): ?><div class="ok"><?= htmlspecialchars($flash,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <?php if($err): ?><div class="err"><?= htmlspecialchars(t($err),ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

  <form method="post">
    <input name="email" placeholder="<?= htmlspecialchars(t('auth.email'), ENT_QUOTES, 'UTF-8') ?>" required>
    <input name="name" placeholder="<?= htmlspecialchars(t('auth.name_optional'), ENT_QUOTES, 'UTF-8') ?>">
    <input name="pass" type="password" placeholder="<?= htmlspecialchars(t('auth.password_min'), ENT_QUOTES, 'UTF-8') ?>" required>
    <select name="role" required>
      <option value="user"><?= htmlspecialchars(t('auth.role_user'), ENT_QUOTES, 'UTF-8') ?></option>
      <?php if (defined('GOV_REGISTRATION_ENABLED') && GOV_REGISTRATION_ENABLED): ?>
      <option value="govuser"><?= htmlspecialchars(t('auth.role_gov'), ENT_QUOTES, 'UTF-8') ?></option>
      <?php endif; ?>
      <option value="communityuser"><?= htmlspecialchars(t('auth.role_community'), ENT_QUOTES, 'UTF-8') ?></option>
      <option value="civiluser"><?= htmlspecialchars(t('auth.role_civil'), ENT_QUOTES, 'UTF-8') ?></option>
    </select>

    <div class="hr"></div>

    <label class="chk">
      <input type="checkbox" name="consent_data" value="1" required>
      <span>
        <b><?= htmlspecialchars(t('auth.consent_data'), ENT_QUOTES, 'UTF-8') ?></b>
        <div class="small"><?= htmlspecialchars(t('auth.consent_data_hint'), ENT_QUOTES, 'UTF-8') ?></div>
      </span>
    </label>

    <label class="chk">
      <input type="checkbox" name="consent_share" value="1" checked>
      <span>
        <?= htmlspecialchars(t('auth.consent_share'), ENT_QUOTES, 'UTF-8') ?>
        <div class="small"><?= htmlspecialchars(t('auth.consent_share_hint'), ENT_QUOTES, 'UTF-8') ?></div>
      </span>
    </label>

    <label class="chk">
      <input type="checkbox" name="consent_marketing" value="1">
      <span>
        <?= htmlspecialchars(t('auth.consent_marketing'), ENT_QUOTES, 'UTF-8') ?>
        <div class="small"><?= htmlspecialchars(t('auth.consent_marketing_hint'), ENT_QUOTES, 'UTF-8') ?></div>
      </span>
    </label>

    <button type="submit" class="primary"><?= htmlspecialchars(t('auth.register_title'), ENT_QUOTES, 'UTF-8') ?></button>
  </form>

  <div style="margin-top:10px"><a href="<?= htmlspecialchars(app_url('/user/login.php')) ?>"><?= htmlspecialchars(t('auth.register_link'), ENT_QUOTES, 'UTF-8') ?></a></div>
</div>
</div>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body></html>
