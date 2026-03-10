<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($uid <= 0) {
  header('Location: ' . app_url('/user/login.php'));
  exit;
}
$role = current_user_role() ?: '';

// URL-ből nyelv beállítása (pl. mentés után redirect ?lang=hu)
if (!empty($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
  set_lang($_GET['lang']);
}

$err = null;
$ok  = null;
if (isset($_SESSION['settings_ok']) && $_SESSION['settings_ok']) {
  $ok = 'user.saved';
  unset($_SESSION['settings_ok']);
}

// aktuális adatok
try {
  $stmt = db()->prepare("
    SELECT id, email, display_name, avatar_filename, profile_public,
           first_name, last_name, prefix, birthdate, phone,
           address_zip, address_city, address_street, address_house,
           marketing_greetings_optout,
           consent_data, consent_share, consent_marketing,
           consent_version, consent_at,
           preferred_lang, preferred_theme
    FROM users
    WHERE id = :id
    LIMIT 1
  ");
  $stmt->execute([':id' => $uid]);
  $u = $stmt->fetch();
} catch (Throwable $e) {
  $stmt = db()->prepare("
    SELECT id, email, display_name, avatar_filename, profile_public,
           first_name, last_name, prefix, birthdate, phone,
           address_zip, address_city, address_street, address_house,
           marketing_greetings_optout,
           consent_data, consent_share, consent_marketing,
           consent_version, consent_at
    FROM users
    WHERE id = :id
    LIMIT 1
  ");
  $stmt->execute([':id' => $uid]);
  $u = $stmt->fetch();
  if ($u) { $u['preferred_lang'] = null; $u['preferred_theme'] = null; }
}
if (!$u) {
  // ha valamiért eltűnt a user
  session_destroy();
  header('Location: ' . app_url('/user/login.php'));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = safe_str($_POST['name'] ?? null, 80);
  $firstName = safe_str($_POST['first_name'] ?? null, 80);
  $lastName = safe_str($_POST['last_name'] ?? null, 80);
  $prefix = safe_str($_POST['prefix'] ?? null, 20);
  $birthdate = safe_str($_POST['birthdate'] ?? null, 10);
  $phone = safe_str($_POST['phone'] ?? null, 32);
  if ($phone) {
    $phone = preg_replace('/[^0-9\+]/', '', $phone);
  }
  $addrZip = safe_str($_POST['address_zip'] ?? null, 16);
  $addrCity = safe_str($_POST['address_city'] ?? null, 80);
  $addrStreet = safe_str($_POST['address_street'] ?? null, 120);
  $addrHouse = safe_str($_POST['address_house'] ?? null, 20);

  // csak az opcionális hozzájárulások állíthatók itt
  $consentShare     = !empty($_POST['consent_share']) ? 1 : 0;
  $consentMarketing = !empty($_POST['consent_marketing']) ? 1 : 0;
  $profilePublic    = !empty($_POST['profile_public']) ? 1 : 0;
  $marketingGreetings = !empty($_POST['marketing_greetings']) ? 1 : 0;
  $marketingOptOut = $consentMarketing ? ($marketingGreetings ? 0 : 1) : 1;

  $preferredLang = isset($_POST['preferred_lang']) && in_array($_POST['preferred_lang'], LANG_ALLOWED, true) ? $_POST['preferred_lang'] : null;
  $preferredTheme = isset($_POST['preferred_theme']) && in_array($_POST['preferred_theme'], ['light', 'dark'], true) ? $_POST['preferred_theme'] : null;

  if ($birthdate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
    $birthdate = null;
  }

  try {
    $stmt = db()->prepare("
      UPDATE users
      SET display_name = :n,
          first_name = :fn,
          last_name = :ln,
          prefix = :px,
          birthdate = :bd,
          phone = :ph,
          address_zip = :az,
          address_city = :ac,
          address_street = :as,
          address_house = :ah,
          marketing_greetings_optout = :mgo,
          consent_share = :cs,
          consent_marketing = :cm,
          profile_public = :pp,
          preferred_lang = :pl,
          preferred_theme = :pt,
          consent_updated_at = NOW()
      WHERE id = :id
      LIMIT 1
    ");
    $stmt->execute([
      ':n'  => $name,
      ':fn' => $firstName,
      ':ln' => $lastName,
      ':px' => $prefix,
      ':bd' => $birthdate,
      ':ph' => $phone,
      ':az' => $addrZip,
      ':ac' => $addrCity,
      ':as' => $addrStreet,
      ':ah' => $addrHouse,
      ':mgo' => $marketingOptOut,
      ':cs' => $consentShare,
      ':cm' => $consentMarketing,
      ':id' => $uid,
      ':pp' => $profilePublic,
      ':pl' => $preferredLang,
      ':pt' => $preferredTheme
    ]);
    // Mentés után redirect: a legördülők a mentett értéket mutatják, az oldal az új nyelv/téma szerint tölt
    $_SESSION['settings_ok'] = true;
    if ($preferredLang) {
      set_lang($preferredLang);
    }
    $redirectUrl = app_url('/user/settings.php');
    if ($preferredLang) {
      $redirectUrl .= (strpos($redirectUrl, '?') !== false ? '&' : '?') . 'lang=' . rawurlencode($preferredLang);
    }
    header('Location: ' . $redirectUrl);
    exit;
  } catch (Throwable $e) {
    $stmt = db()->prepare("
      UPDATE users
      SET display_name = :n, first_name = :fn, last_name = :ln, prefix = :px,
          birthdate = :bd, phone = :ph, address_zip = :az, address_city = :ac,
          address_street = :as, address_house = :ah, marketing_greetings_optout = :mgo,
          consent_share = :cs, consent_marketing = :cm, profile_public = :pp,
          consent_updated_at = NOW()
      WHERE id = :id LIMIT 1
    ");
    $stmt->execute([
      ':n' => $name, ':fn' => $firstName, ':ln' => $lastName, ':px' => $prefix,
      ':bd' => $birthdate, ':ph' => $phone, ':az' => $addrZip, ':ac' => $addrCity,
      ':as' => $addrStreet, ':ah' => $addrHouse, ':mgo' => $marketingOptOut,
      ':cs' => $consentShare, ':cm' => $consentMarketing, ':id' => $uid, ':pp' => $profilePublic
    ]);
    $u['preferred_lang'] = $preferredLang;
    $u['preferred_theme'] = $preferredTheme;
  }
  if ($preferredLang !== null) {
    set_lang($preferredLang);
  }

  $ok = 'user.saved';

  // frissítsük a képernyőn is
  $u['display_name'] = $name;
  $u['first_name'] = $firstName;
  $u['last_name'] = $lastName;
  $u['prefix'] = $prefix;
  $u['birthdate'] = $birthdate;
  $u['phone'] = $phone;
  $u['address_zip'] = $addrZip;
  $u['address_city'] = $addrCity;
  $u['address_street'] = $addrStreet;
  $u['address_house'] = $addrHouse;
  $u['marketing_greetings_optout'] = $marketingOptOut;
  $u['consent_share'] = $consentShare;
  $u['consent_marketing'] = $consentMarketing;
  $u['profile_public'] = $profilePublic;
  $u['preferred_lang'] = $preferredLang;
  $u['preferred_theme'] = $preferredTheme;
}

function checked($v): string { return ((int)$v) === 1 ? 'checked' : ''; }
$currentLang = current_lang();
$isMobile = function_exists('use_mobile_layout') ? use_mobile_layout() : (function_exists('is_mobile_device') && is_mobile_device());
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#0f1721">
<title><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('user.settings'), ENT_QUOTES, 'UTF-8') ?></title>
<script>try{var t=localStorage.getItem('civicai_theme');var u=<?= json_encode(($u['preferred_theme'] ?? null) === 'light' || ($u['preferred_theme'] ?? null) === 'dark' ? $u['preferred_theme'] : null, JSON_UNESCAPED_UNICODE) ?>;if(u){localStorage.setItem('civicai_theme',u);t=u;}t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');document.documentElement.setAttribute('data-bs-theme','dark');}</script>
<?php if ($isMobile): ?>
<link rel="stylesheet" href="<?= htmlspecialchars(app_url('/Mobilekit_v2-9-1/HTML/assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
<?php if ($isMobile): ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/mobilekit_civicai.css'), ENT_QUOTES, 'UTF-8'); ?>">
<?php endif; ?>
</head>
<body class="page<?= $isMobile ? ' civicai-mobile' : '' ?>">
<?php if (!$isMobile): require __DIR__ . '/../inc_desktop_topbar.php'; endif; ?>
<div class="wrap">
<div class="card">
  <div class="row">
    <h3 style="margin:0"><?= htmlspecialchars(t('user.settings'), ENT_QUOTES, 'UTF-8') ?></h3>
    <a class="btn" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.map'), ENT_QUOTES, 'UTF-8') ?></a>
  </div>

  <?php if($ok): ?><div class="ok"><?= htmlspecialchars(t($ok),ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <?php if($err): ?><div class="err"><?= htmlspecialchars($err,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

  <div style="margin:8px 0">
    <div><b><?= htmlspecialchars(t('user.email'), ENT_QUOTES, 'UTF-8') ?></b>: <?= htmlspecialchars($u['email'],ENT_QUOTES,'UTF-8') ?></div>
    <div><span class="badge"><?= htmlspecialchars(t('user.data_consent_ok'), ENT_QUOTES, 'UTF-8') ?></span>
      <?php if(!empty($u['consent_at'])): ?>
        <small>(<?= htmlspecialchars($u['consent_at'],ENT_QUOTES,'UTF-8') ?>, <?= htmlspecialchars($u['consent_version'] ?? 'v1',ENT_QUOTES,'UTF-8') ?>)</small>
      <?php endif; ?>
    </div>
    <div style="margin-top:6px">
      <a href="<?= htmlspecialchars(app_url('/user/profile.php?id=' . (int)$uid), ENT_QUOTES, 'UTF-8') ?>" target="_blank"><?= htmlspecialchars(t('user.open_profile'), ENT_QUOTES, 'UTF-8') ?></a>
    </div>
  </div>

  <form method="post">
    <label><b><?= htmlspecialchars(t('user.preferred_lang'), ENT_QUOTES, 'UTF-8') ?></b></label>
    <select name="preferred_lang">
      <option value="">—</option>
      <?php foreach (LANG_ALLOWED as $code): ?>
        <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"<?= ($u['preferred_lang'] ?? '') === $code ? ' selected' : '' ?>><?= htmlspecialchars(strtoupper($code), ENT_QUOTES, 'UTF-8') ?></option>
      <?php endforeach; ?>
    </select>
    <label><b><?= htmlspecialchars(t('user.preferred_theme'), ENT_QUOTES, 'UTF-8') ?></b></label>
    <select name="preferred_theme">
      <option value="">—</option>
      <option value="light"<?= ($u['preferred_theme'] ?? '') === 'light' ? ' selected' : '' ?>><?= htmlspecialchars(t('theme.light'), ENT_QUOTES, 'UTF-8') ?></option>
      <option value="dark"<?= ($u['preferred_theme'] ?? '') === 'dark' ? ' selected' : '' ?>><?= htmlspecialchars(t('theme.dark'), ENT_QUOTES, 'UTF-8') ?></option>
    </select>
    <div class="hr"></div>
    <label><b><?= htmlspecialchars(t('user.display_name'), ENT_QUOTES, 'UTF-8') ?></b></label>
    <input type="text" name="name" value="<?= htmlspecialchars($u['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(t('modal.name_placeholder'), ENT_QUOTES, 'UTF-8') ?>">

    <div class="hr"></div>

    <label><b><?= htmlspecialchars(t('user.prefix'), ENT_QUOTES, 'UTF-8') ?></b></label>
    <input type="text" name="prefix" value="<?= htmlspecialchars($u['prefix'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Pl. Dr., Prof., Mr, Mrs">

    <label><b><?= htmlspecialchars(t('user.last_name'), ENT_QUOTES, 'UTF-8') ?></b></label>
    <input type="text" name="last_name" value="<?= htmlspecialchars($u['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Pl. Kovács">

    <label><b><?= htmlspecialchars(t('user.first_name'), ENT_QUOTES, 'UTF-8') ?></b></label>
    <input type="text" name="first_name" value="<?= htmlspecialchars($u['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Pl. Anna">

    <label><b><?= htmlspecialchars(t('user.birthdate'), ENT_QUOTES, 'UTF-8') ?></b></label>
    <input type="text" name="birthdate" value="<?= htmlspecialchars($u['birthdate'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="YYYY-MM-DD">

    <label><b><?= htmlspecialchars(t('user.phone'), ENT_QUOTES, 'UTF-8') ?></b></label>
    <input type="text" name="phone" value="<?= htmlspecialchars($u['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="+36...">

    <div class="hr"></div>

    <label class="chk">
      <input type="checkbox" name="consent_share" value="1" <?= checked($u['consent_share'] ?? 0) ?>>
      <span><?= htmlspecialchars(t('user.consent_share'), ENT_QUOTES, 'UTF-8') ?></span>
    </label>

    <label class="chk">
      <input type="checkbox" name="consent_marketing" value="1" <?= checked($u['consent_marketing'] ?? 0) ?>>
      <span><?= htmlspecialchars(t('user.consent_marketing'), ENT_QUOTES, 'UTF-8') ?></span>
    </label>

    <label class="chk">
      <input type="checkbox" name="marketing_greetings" value="1" <?= checked((int)($u['consent_marketing'] ?? 0) === 1 && (int)($u['marketing_greetings_optout'] ?? 0) === 0) ?>>
      <span><?= htmlspecialchars(t('user.greetings_optin'), ENT_QUOTES, 'UTF-8') ?></span>
    </label>

    <div class="hr"></div>

    <label class="chk">
      <input type="checkbox" name="profile_public" value="1" <?= checked($u['profile_public'] ?? 1) ?>>
      <span><?= htmlspecialchars(t('user.profile_public'), ENT_QUOTES, 'UTF-8') ?><br><small><?= htmlspecialchars(t('user.profile_public_hint'), ENT_QUOTES, 'UTF-8') ?></small></span>
    </label>

    <div class="hr"></div>

    <label><b><?= htmlspecialchars(t('user.address'), ENT_QUOTES, 'UTF-8') ?></b></label>
    <input type="text" name="address_zip" value="<?= htmlspecialchars($u['address_zip'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(t('modal.zip'), ENT_QUOTES, 'UTF-8') ?>">
    <input type="text" name="address_city" value="<?= htmlspecialchars($u['address_city'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(t('modal.city'), ENT_QUOTES, 'UTF-8') ?>">
    <input type="text" name="address_street" value="<?= htmlspecialchars($u['address_street'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(t('modal.street'), ENT_QUOTES, 'UTF-8') ?>">
    <input type="text" name="address_house" value="<?= htmlspecialchars($u['address_house'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(t('modal.house'), ENT_QUOTES, 'UTF-8') ?>">

    <div class="hr"></div>

    <button type="submit"><?= htmlspecialchars(t('gov.save'), ENT_QUOTES, 'UTF-8') ?></button>
  </form>

  <div class="hr"></div>

  <div>
    <b><?= htmlspecialchars(t('user.avatar'), ENT_QUOTES, 'UTF-8') ?></b>
    <div class="row" style="margin-top:8px;align-items:center">
      <?php if (!empty($u['avatar_filename'])): ?>
        <img src="<?= htmlspecialchars(app_url('/uploads/avatars/' . $u['avatar_filename']), ENT_QUOTES, 'UTF-8') ?>" alt="avatar" style="width:64px;height:64px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
      <?php else: ?>
        <div style="width:64px;height:64px;border-radius:999px;border:1px solid #e5e7eb;background:#f3f4f6;display:grid;place-items:center;color:#6b7280">?</div>
      <?php endif; ?>
      <form method="post" action="<?= htmlspecialchars(app_url('/api/avatar_upload.php'), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center">
        <input type="file" name="file" accept="image/*" required>
        <button type="submit"><?= htmlspecialchars(t('user.avatar_upload'), ENT_QUOTES, 'UTF-8') ?></button>
      </form>
    </div>
    <small><?= htmlspecialchars(t('user.avatar_hint'), ENT_QUOTES, 'UTF-8') ?></small>
  </div>

  <div style="margin-top:12px">
    <small><?= htmlspecialchars(t('user.consent_withdraw'), ENT_QUOTES, 'UTF-8') ?></small>
  </div>
</div>
</div>
<?php if ($ok): $pt = ($u['preferred_theme'] ?? null) === 'light' || ($u['preferred_theme'] ?? null) === 'dark' ? $u['preferred_theme'] : null; if ($pt): ?>
<script>try{localStorage.setItem('civicai_theme','<?= $pt === 'light' ? 'light' : 'dark' ?>');document.documentElement.setAttribute('data-theme','<?= $pt ?>');document.documentElement.setAttribute('data-bs-theme','<?= $pt ?>');}catch(_){}</script>
<?php endif; endif; ?>
</body></html>
