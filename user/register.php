<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim($_POST['email'] ?? '');
  $pass  = (string)($_POST['pass'] ?? '');
  $name  = safe_str($_POST['name'] ?? null, 80);

  // GDPR / hozzájárulások
  $consentData      = !empty($_POST['consent_data']);      // kötelező
  $consentShare     = !empty($_POST['consent_share']);     // opcionális
  $consentMarketing = !empty($_POST['consent_marketing']); // opcionális

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Hibás e-mail cím.';
  elseif (strlen($pass) < 8) $err = 'A jelszó legyen legalább 8 karakter.';
  elseif (!$consentData) $err = 'A regisztrációhoz el kell fogadnod az adatkezelési tájékoztatót és hozzá kell járulnod az adatok kezeléséhez.';
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
          (email, pass_hash, display_name, verify_token,
           consent_data, consent_share, consent_marketing,
           consent_version, consent_at, consent_ip_hash, consent_user_agent)
        VALUES
          (:e,:h,:n,:t,
           :cd,:cs,:cm,
           :cv, NOW(), :ip, :ua)
      ");
      $stmt->execute([
        ':e'  => $email_lc,
        ':h'  => $hash,
        ':n'  => $name,
        ':t'  => $token,
        ':cd' => $consentData ? 1 : 0,
        ':cs' => $consentShare ? 1 : 0,
        ':cm' => $consentMarketing ? 1 : 0,
        ':cv' => $consentVersion,
        ':ip' => $ipHash,
        ':ua' => $ua,
      ]);

      // MVP: email küldés később (D/E-nél).
      // Most kiírjuk a verify linket (admin/dev mód).
      $verifyUrl = app_url('/user/verify.php?token=' . $token);

      $_SESSION['flash'] = "Sikeres regisztráció. Ellenőrző link (MVP): " . $verifyUrl;
      header('Location: ' . app_url('/user/login.php'));
      exit;

    } catch (Throwable $e) {
      $err = 'Ez az e-mail már foglalt (vagy hiányzik a GDPR mezők bővítése az adatbázisból).';
    }
  }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="hu"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Regisztráció</title>
<style>
body{font:14px system-ui;background:#f6f7f9;margin:0;display:grid;place-items:center;height:100vh}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:18px;min-width:360px;max-width:420px}
input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #d1d5db;border-radius:10px;margin:6px 0}
button{width:100%;padding:10px;border:0;border-radius:10px;background:#16a34a;color:#fff;cursor:pointer}
.err{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:8px 10px;border-radius:10px;margin-bottom:10px}
.ok{background:#dcfce7;border:1px solid #bbf7d0;color:#166534;padding:8px 10px;border-radius:10px;margin-bottom:10px}
a{color:#2563eb;text-decoration:none}
.chk{display:flex;gap:10px;align-items:flex-start;margin:10px 0}
.chk input{width:auto;margin-top:3px}
.small{font-size:12px;color:#6b7280;line-height:1.35}
.hr{height:1px;background:#e5e7eb;margin:12px 0}
</style></head>
<body>
<div class="card">
  <h3 style="margin:0 0 10px">Regisztráció</h3>
  <?php if($flash): ?><div class="ok"><?= htmlspecialchars($flash,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <?php if($err): ?><div class="err"><?= htmlspecialchars($err,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

  <form method="post">
    <input name="email" placeholder="E-mail" required>
    <input name="name" placeholder="Név (opcionális)">
    <input name="pass" type="password" placeholder="Jelszó (min. 8)" required>

    <div class="hr"></div>

    <label class="chk">
      <input type="checkbox" name="consent_data" value="1" required>
      <span>
        <b>Elfogadom az adatkezelési tájékoztatót</b>, és hozzájárulok az adataim kezeléséhez. (kötelező)
        <div class="small">A hozzájárulás bármikor visszavonható – a fiók törlésével vagy írásban.</div>
      </span>
    </label>

    <label class="chk">
      <input type="checkbox" name="consent_share" value="1" checked>
      <span>
        Hozzájárulok, hogy az ügy intézése érdekében az adataimat az illetékeseknek továbbítsák. (opcionális)
        <div class="small">Például: önkormányzat, szolgáltató, közmű.</div>
      </span>
    </label>

    <label class="chk">
      <input type="checkbox" name="consent_marketing" value="1">
      <span>
        Hozzájárulok marketing célú megkeresésekhez. (opcionális)
        <div class="small">Hírek, fejlesztések, helyi ügyekkel kapcsolatos értesítések.</div>
      </span>
    </label>

    <button type="submit">Regisztráció</button>
  </form>

  <div style="margin-top:10px"><a href="<?= htmlspecialchars(app_url('/user/login.php')) ?>">Van fiókod? Belépés</a></div>
</div>
</body></html>
