<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$err = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = mb_strtolower(trim($_POST['email'] ?? ''));
  $pass  = (string)($_POST['pass'] ?? '');

  $stmt = db()->prepare("SELECT id, pass_hash, is_verified, role FROM users WHERE email=:e LIMIT 1");
  $stmt->execute([':e'=>$email]);
  $u = $stmt->fetch();

  if (!$u || !password_verify($pass, $u['pass_hash'])) {
    $err = 'Hibás belépési adatok.';
  } else {
    // MVP: is_verified-t egyelőre nem kényszerítjük (később E/D)
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$u['id'];
    $_SESSION['user_role'] = $u['role'] ? (string)$u['role'] : 'user';
    header('Location: ' . app_url('/'));
    exit;
  }
}
?>
<!doctype html>
<html lang="hu"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Belépés</title>
<style>
body{font:14px system-ui;background:#f6f7f9;margin:0;display:grid;place-items:center;height:100vh}
.card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.08);padding:18px;min-width:340px}
input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #d1d5db;border-radius:10px;margin:6px 0}
button{width:100%;padding:10px;border:0;border-radius:10px;background:#2563eb;color:#fff;cursor:pointer}
.err{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;padding:8px 10px;border-radius:10px;margin-bottom:10px}
a{color:#2563eb;text-decoration:none}
</style></head>
<body>
<div class="card">
  <h3 style="margin:0 0 10px">Belépés</h3>
  <?php if($err): ?><div class="err"><?= htmlspecialchars($err,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>
  <form method="post">
    <input name="email" placeholder="E-mail" required>
    <input name="pass" type="password" placeholder="Jelszó" required>
    <button type="submit">Belépés</button>
  </form>
  <div style="margin-top:10px"><a href="<?= htmlspecialchars(app_url('/user/register.php')) ?>">Regisztráció</a></div>
</div>
</body></html>