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

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$friends = [];
$incoming = [];
$outgoing = [];

$stmt = db()->prepare("
  SELECT u.id, u.display_name, u.email, u.level
  FROM friends f
  JOIN users u ON u.id = f.friend_user_id
  WHERE f.user_id = :uid
  ORDER BY u.display_name ASC
");
$stmt->execute([':uid' => $uid]);
$friends = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmt = db()->prepare("
  SELECT fr.id, u.id AS user_id, u.display_name, u.email
  FROM friend_requests fr
  JOIN users u ON u.id = fr.from_user_id
  WHERE fr.to_user_id = :uid AND fr.status = 'pending'
  ORDER BY fr.created_at DESC
");
$stmt->execute([':uid' => $uid]);
$incoming = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmt = db()->prepare("
  SELECT fr.id, u.id AS user_id, u.display_name, u.email
  FROM friend_requests fr
  JOIN users u ON u.id = fr.to_user_id
  WHERE fr.from_user_id = :uid AND fr.status = 'pending'
  ORDER BY fr.created_at DESC
");
$stmt->execute([':uid' => $uid]);
$outgoing = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="hu"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Köz.Tér – Barátok</title>
<link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
<script>
document.addEventListener('DOMContentLoaded', function(){
  const BASE = '<?= addslashes(app_url('')) ?>';
  document.querySelectorAll('form[action*="friend_request"]').forEach(function(frm){
    frm.addEventListener('submit', function(e){
      e.preventDefault();
      const btn = frm.querySelector('button[type="submit"]');
      const origText = btn ? btn.textContent : '';
      if (btn) { btn.disabled = true; btn.textContent = '...'; }
      const fd = new FormData(frm);
      const body = {};
      fd.forEach(function(v,k){ body[k]=v; });

      fetch(BASE + '/api/friend_request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
      })
      .then(function(r){ return r.json(); })
      .then(function(j){
        if (j && j.ok) location.reload();
        else alert(j && j.error ? j.error : 'Hiba történt.');
      })
      .catch(function(err){
        alert('Hiba: ' + (err.message || 'Ismeretlen hiba'));
      })
      .finally(function(){
        if (btn) { btn.disabled = false; btn.textContent = origText; }
      });
    });
  });
});
</script>
</head>
<body class="page">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= h(app_url('/')) ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b>Köz.Tér</b>
    </a>
    <div class="topbar-links">
      <a class="topbtn" href="<?= h(app_url('/')) ?>">Térkép</a>
      <a class="topbtn" href="<?= h(app_url('/user/my.php')) ?>">Saját ügyeim</a>
      <a class="topbtn" href="<?= h(app_url('/user/settings.php')) ?>">Beállítások</a>
      <?php if ($role === 'govuser' || $role === 'admin' || $role === 'superadmin'): ?>
        <a class="topbtn" href="<?= h(app_url('/gov/index.php')) ?>">Közigazgatási</a>
      <?php endif; ?>
      <a class="topbtn" href="<?= h(app_url('/user/logout.php')) ?>">Kilépés</a>
    </div>
  </div>
</header>

<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div style="font-weight:900;font-size:18px">Barátok</div>
        <div class="muted">Kapcsolatok és kérések</div>
      </div>
      <a class="btn" href="<?= h(app_url('/')) ?>">Térkép</a>
    </div>

    <div class="hr"></div>
    <div class="kv">
      <div class="k">Barátok</div>
      <div class="v"><?= count($friends) ?></div>
      <div class="k">Bejövő kérések</div>
      <div class="v"><?= count($incoming) ?></div>
      <div class="k">Kimenő kérések</div>
      <div class="v"><?= count($outgoing) ?></div>
    </div>

    <div class="hr"></div>
    <h3 style="margin:0 0 8px">Barátok listája</h3>
    <?php if (!$friends): ?>
      <div class="muted">Még nincs barátod.</div>
    <?php else: ?>
      <div class="list">
        <?php foreach($friends as $f): ?>
          <div class="admin-item">
            <div class="meta">
              <b><?= h($f['display_name'] ?: $f['email']) ?></b> • <?= h($f['email']) ?>
            </div>
            <div class="actions">
              <a class="btn" href="<?= h(app_url('/user/profile.php?id=' . (int)$f['id'])) ?>" target="_blank">Profil</a>
              <form method="post" action="<?= h(app_url('/api/friend_request.php')) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="target_id" value="<?= (int)$f['id'] ?>">
                <button class="btn soft" type="submit">Eltávolítás</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="hr"></div>
    <h3 style="margin:0 0 8px">Bejövő kérések</h3>
    <?php if (!$incoming): ?>
      <div class="muted">Nincs új kérés.</div>
    <?php else: ?>
      <div class="list">
        <?php foreach($incoming as $r): ?>
          <div class="admin-item">
            <div class="meta">
              <b><?= h($r['display_name'] ?: $r['email']) ?></b> • <?= h($r['email']) ?>
            </div>
            <div class="actions">
              <form method="post" action="<?= h(app_url('/api/friend_request.php')) ?>">
                <input type="hidden" name="action" value="accept">
                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                <button class="btn primary" type="submit">Elfogadás</button>
              </form>
              <form method="post" action="<?= h(app_url('/api/friend_request.php')) ?>">
                <input type="hidden" name="action" value="decline">
                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                <button class="btn soft" type="submit">Elutasítás</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="hr"></div>
    <h3 style="margin:0 0 8px">Kimenő kérések</h3>
    <?php if (!$outgoing): ?>
      <div class="muted">Nincs kimenő kérés.</div>
    <?php else: ?>
      <div class="list">
        <?php foreach($outgoing as $r): ?>
          <div class="admin-item">
            <div class="meta">
              <b><?= h($r['display_name'] ?: $r['email']) ?></b> • <?= h($r['email']) ?>
            </div>
            <div class="actions">
              <form method="post" action="<?= h(app_url('/api/friend_request.php')) ?>">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="target_id" value="<?= (int)$r['user_id'] ?>">
                <button class="btn soft" type="submit">Visszavonás</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</body></html>
