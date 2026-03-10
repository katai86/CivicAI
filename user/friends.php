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
$currentLang = current_lang();
?>
<!doctype html>
<html lang="<?= h($currentLang) ?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h(t('site.name')) ?> – <?= h(t('user.friends')) ?></title>
<script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');}</script>
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
<?php require __DIR__ . '/../inc_desktop_topbar.php'; ?>

<div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div style="font-weight:900;font-size:18px"><?= h(t('user.friends')) ?></div>
        <div class="muted"><?= h(t('user.friends_sub')) ?></div>
      </div>
      <a class="btn" href="<?= h(app_url('/')) ?>"><?= h(t('nav.map')) ?></a>
    </div>

    <div class="hr"></div>
    <div class="kv">
      <div class="k"><?= h(t('user.friends')) ?></div>
      <div class="v"><?= count($friends) ?></div>
      <div class="k"><?= h(t('user.incoming')) ?></div>
      <div class="v"><?= count($incoming) ?></div>
      <div class="k"><?= h(t('user.outgoing')) ?></div>
      <div class="v"><?= count($outgoing) ?></div>
    </div>

    <div class="hr"></div>
    <h3 style="margin:0 0 8px"><?= h(t('user.friends_list')) ?></h3>
    <?php if (!$friends): ?>
      <div class="muted"><?= h(t('user.no_friends')) ?></div>
    <?php else: ?>
      <div class="list">
        <?php foreach($friends as $f): ?>
          <div class="admin-item">
            <div class="meta">
              <b><?= h($f['display_name'] ?: $f['email']) ?></b> • <?= h($f['email']) ?>
            </div>
            <div class="actions">
              <a class="btn" href="<?= h(app_url('/user/profile.php?id=' . (int)$f['id'])) ?>" target="_blank"><?= h(t('user.profile')) ?></a>
              <form method="post" action="<?= h(app_url('/api/friend_request.php')) ?>">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="target_id" value="<?= (int)$f['id'] ?>">
                <button class="btn soft" type="submit"><?= h(t('user.remove')) ?></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="hr"></div>
    <h3 style="margin:0 0 8px"><?= h(t('user.incoming')) ?></h3>
    <?php if (!$incoming): ?>
      <div class="muted"><?= h(t('user.no_incoming')) ?></div>
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
                <button class="btn primary" type="submit"><?= h(t('user.accept')) ?></button>
              </form>
              <form method="post" action="<?= h(app_url('/api/friend_request.php')) ?>">
                <input type="hidden" name="action" value="decline">
                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                <button class="btn soft" type="submit"><?= h(t('user.decline')) ?></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="hr"></div>
    <h3 style="margin:0 0 8px"><?= h(t('user.outgoing')) ?></h3>
    <?php if (!$outgoing): ?>
      <div class="muted"><?= h(t('user.no_outgoing')) ?></div>
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
                <button class="btn soft" type="submit"><?= h(t('user.cancel')) ?></button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</body></html>
