<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($uid <= 0) {
  header('Location: ' . app_url('/user/login.php'));
  exit;
}

$err = null;
$ok  = null;

// aktuális adatok
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

  if ($birthdate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthdate)) {
    $birthdate = null;
  }

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
    ':pp' => $profilePublic
  ]);

  $ok = 'Mentve.';

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
}

function checked($v): string { return ((int)$v) === 1 ? 'checked' : ''; }

?>
<!doctype html>
<html lang="hu"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Beállítások</title>
<style>
body{font:14px system-ui;background:#f6f7f9;margin:0;display:grid;place-items:center;min-height:100vh;padding:24px}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:18px;min-width:360px;max-width:520px;width:100%}
.row{display:flex;gap:10px;justify-content:space-between;align-items:center}
input[type=text]{width:100%;box-sizing:border-box;padding:10px;border:1px solid #d1d5db;border-radius:10px;margin:6px 0}
small{color:#6b7280}
button{padding:10px 14px;border:0;border-radius:10px;background:#2563eb;color:#fff;cursor:pointer}
a{color:#2563eb;text-decoration:none}
.err{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:8px 10px;border-radius:10px;margin-bottom:10px}
.ok{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;padding:8px 10px;border-radius:10px;margin-bottom:10px}
.chk{display:flex;gap:10px;align-items:flex-start;margin:10px 0}
.chk input{width:auto;margin-top:3px}
.hr{height:1px;background:#e5e7eb;margin:12px 0}
.badge{display:inline-block;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:999px;padding:2px 10px;font-size:12px;color:#374151}
</style></head>
<body>
<div class="card">
  <div class="row">
    <h3 style="margin:0">Beállítások</h3>
    <a href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">← Vissza a térképre</a>
  </div>

  <?php if($ok): ?><div class="ok"><?= htmlspecialchars($ok,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <?php if($err): ?><div class="err"><?= htmlspecialchars($err,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

  <div style="margin:8px 0">
    <div><b>E-mail</b>: <?= htmlspecialchars($u['email'],ENT_QUOTES,'UTF-8') ?></div>
    <div><span class="badge">Adatkezelés: elfogadva</span>
      <?php if(!empty($u['consent_at'])): ?>
        <small>(<?= htmlspecialchars($u['consent_at'],ENT_QUOTES,'UTF-8') ?>, <?= htmlspecialchars($u['consent_version'] ?? 'v1',ENT_QUOTES,'UTF-8') ?>)</small>
      <?php endif; ?>
    </div>
    <div style="margin-top:6px">
      <a href="<?= htmlspecialchars(app_url('/user/profile.php?id=' . (int)$uid), ENT_QUOTES, 'UTF-8') ?>" target="_blank">Profilom megnyitása</a>
    </div>
  </div>

  <form method="post">
    <label><b>Megjelenő név</b> (opcionális)</label>
    <input type="text" name="name" value="<?= htmlspecialchars($u['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Pl. Kovács Anna">

    <div class="hr"></div>

    <label><b>Megszólítás / prefix</b> (opcionális)</label>
    <input type="text" name="prefix" value="<?= htmlspecialchars($u['prefix'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Pl. Dr., Prof., Mr, Mrs">

    <label><b>Vezetéknév</b> (opcionális)</label>
    <input type="text" name="last_name" value="<?= htmlspecialchars($u['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Pl. Kovács">

    <label><b>Keresztnév</b> (opcionális, névnaphoz)</label>
    <input type="text" name="first_name" value="<?= htmlspecialchars($u['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Pl. Anna">

    <label><b>Születésnap</b> (opcionális)</label>
    <input type="text" name="birthdate" value="<?= htmlspecialchars($u['birthdate'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="YYYY-MM-DD">

    <label><b>Telefonszám</b> (opcionális)</label>
    <input type="text" name="phone" value="<?= htmlspecialchars($u['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="+36...">

    <div class="hr"></div>

    <label class="chk">
      <input type="checkbox" name="consent_share" value="1" <?= checked($u['consent_share'] ?? 0) ?>>
      <span>
        Hozzájárulok, hogy az ügy intézése érdekében az adataimat az illetékeseknek továbbítsák.
        <br><small>Például: önkormányzat, szolgáltató, közmű.</small>
      </span>
    </label>

    <label class="chk">
      <input type="checkbox" name="consent_marketing" value="1" <?= checked($u['consent_marketing'] ?? 0) ?>>
      <span>
        Hozzájárulok marketing célú megkeresésekhez.
        <br><small>Hírek, fejlesztések, helyi ügyekkel kapcsolatos értesítések.</small>
      </span>
    </label>

    <label class="chk">
      <input type="checkbox" name="marketing_greetings" value="1" <?= checked((int)($u['consent_marketing'] ?? 0) === 1 && (int)($u['marketing_greetings_optout'] ?? 0) === 0) ?>>
      <span>
        Szeretnék születésnapi / névnapi üdvözletet kapni e-mailben.
        <br><small>A marketing hozzájárulás szükséges hozzá.</small>
      </span>
    </label>

    <div class="hr"></div>

    <label class="chk">
      <input type="checkbox" name="profile_public" value="1" <?= checked($u['profile_public'] ?? 1) ?>>
      <span>
        Profilom nyilv&#225;nos (a nevem &#233;s rangom megjelenhet a bejelent&#233;sekn&#233;l).
        <br><small>Ha kikapcsolod, m&#225;sok nem l&#225;tj&#225;k a profilodat.</small>
      </span>
    </label>

    <div class="hr"></div>

    <label><b>Saját cím</b> (opcionális)</label>
    <input type="text" name="address_zip" value="<?= htmlspecialchars($u['address_zip'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Irányítószám">
    <input type="text" name="address_city" value="<?= htmlspecialchars($u['address_city'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Város">
    <input type="text" name="address_street" value="<?= htmlspecialchars($u['address_street'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Utca">
    <input type="text" name="address_house" value="<?= htmlspecialchars($u['address_house'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="Házszám">

    <div class="hr"></div>

    <button type="submit">Mentés</button>
  </form>

  <div class="hr"></div>

  <div>
    <b>Profilk&#233;p</b>
    <div class="row" style="margin-top:8px;align-items:center">
      <?php if (!empty($u['avatar_filename'])): ?>
        <img src="<?= htmlspecialchars(app_url('/uploads/avatars/' . $u['avatar_filename']), ENT_QUOTES, 'UTF-8') ?>" alt="avatar" style="width:64px;height:64px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
      <?php else: ?>
        <div style="width:64px;height:64px;border-radius:999px;border:1px solid #e5e7eb;background:#f3f4f6;display:grid;place-items:center;color:#6b7280">?</div>
      <?php endif; ?>
      <form method="post" action="<?= htmlspecialchars(app_url('/api/avatar_upload.php'), ENT_QUOTES, 'UTF-8') ?>" enctype="multipart/form-data" style="display:flex;gap:8px;align-items:center">
        <input type="file" name="file" accept="image/*" required>
        <button type="submit">Felt&#246;lt&#233;s</button>
      </form>
    </div>
    <small>JPG/PNG/WebP, max. 2 MB. A r&#233;gi profilk&#233;p fel&#252;l&#237;r&#243;dik.</small>
  </div>

  <div style="margin-top:12px">
    <small>Adatkezelési hozzájárulás visszavonása: fiók törlése (későbbi fejlesztés), vagy írásban az üzemeltetőnek.</small>
  </div>
</div>
</body></html>
