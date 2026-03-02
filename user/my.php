<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
  header('Location: ' . app_url('/user/login.php'));
  exit;
}

$stmt = db()->prepare("SELECT email, display_name, total_xp, level, streak_days FROM users WHERE id=:id LIMIT 1");
$stmt->execute([':id' => $userId]);
$u = $stmt->fetch();

$xp = (int)($u['total_xp'] ?? 0);
$lvlInfo = level_from_xp($xp);
$lvlName = $lvlInfo['name'] ?? 'Szint';
$lvlNum = (int)($u['level'] ?? $lvlInfo['level'] ?? 1);
$streak = (int)($u['streak_days'] ?? 0);

// Biztonság: ha a DB sémában eltérés van (pl. notify_* mezők hiányoznak), ne 500-zunk.
$rows = [];
$badges = [];
try {
  $stmt = db()->prepare("
    SELECT
      id, category, title, description, status, created_at,
      address_approx, road, suburb, city, postcode,
      notify_enabled, notify_token
    FROM reports
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 1000
  ");
  $stmt->execute([':uid' => $userId]);
  $rows = $stmt->fetchAll();
} catch (Throwable $e) {
  $stmt = db()->prepare("
    SELECT
      id, category, title, description, status, created_at,
      address_approx, road, suburb, city, postcode
    FROM reports
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 1000
  ");
  $stmt->execute([':uid' => $userId]);
  $rows = $stmt->fetchAll();
}

try {
  $stmt = db()->prepare("
    SELECT b.code, b.name, b.icon, b.description, ub.earned_at
    FROM user_badges ub
    JOIN badges b ON b.id = ub.badge_id
    WHERE ub.user_id = :uid
    ORDER BY ub.earned_at DESC, ub.id DESC
    LIMIT 50
  ");
  $stmt->execute([':uid' => $userId]);
  $badges = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
  $badges = [];
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function badge_icon_url($code){
  if (!$code) return null;
  $base = __DIR__ . '/../assets/badges/' . $code;
  $pathLower = $base . '.png';
  $pathUpper = $base . '.PNG';
  if (is_file($pathLower)) return app_url('/assets/badges/' . $code . '.png');
  if (is_file($pathUpper)) return app_url('/assets/badges/' . $code . '.PNG');
  return null;
}

$statusLabel = [
  'pending' => 'Ellenőrzés alatt',
  'approved' => 'Publikálva',
  'rejected' => 'Elutasítva',
  'new' => 'Új',
  'needs_info' => 'Kiegészítésre vár',
  'forwarded' => 'Továbbítva',
  'waiting_reply' => 'Válaszra vár',
  'in_progress' => 'Folyamatban',
  'solved' => 'Megoldva',
  'closed' => 'Lezárva',
];
$catLabel = [
  'road'=>'Úthiba / kátyú',
  'sidewalk'=>'Járda / burkolat hiba',
  'lighting'=>'Közvilágítás',
  'trash'=>'Szemét / illegális',
  'green'=>'Zöldterület / veszélyes fa',
  'traffic'=>'Közlekedés / tábla',
  'idea'=>'Ötlet / javaslat',
  'civil_event'=>'Civil esemény',
];
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Saját ügyeim</title>
  <style>
    :root{--b:#e5e7eb;--p:#2563eb;--m:#6b7280;}
    body{font:14px system-ui;background:#f6f7f9;margin:0}
    header{background:#fff;border-bottom:1px solid var(--b)}
    .wrap{max-width:1100px;margin:0 auto;padding:12px 14px}
    .top{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
    a.btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 12px;border-radius:12px;
      text-decoration:none;border:1px solid var(--b);background:#fff;color:#111827;font-weight:700}
    a.btn.primary{background:var(--p);border-color:var(--p);color:#fff}
    .muted{color:var(--m)}
    .card{background:#fff;border:1px solid var(--b);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:14px}
    .grid{display:grid;gap:12px}
    @media(min-width:860px){ .grid{grid-template-columns:1fr 1fr} }
    .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
    .pill{display:inline-block;padding:3px 10px;border-radius:999px;border:1px solid var(--b);background:#f9fafb;font-size:12px}
    .title{font-size:16px;font-weight:800;margin:0 0 4px 0}
    .small{font-size:12px;color:var(--m)}
    .hr{height:1px;background:var(--b);margin:10px 0}
  </style>
</head>
<body>
<header>
  <div class="wrap">
    <div class="top">
      <div>
        <div style="font-weight:900;font-size:18px">Saját ügyeim</div>
        <div class="muted"><?php echo h($u['display_name'] ?: $u['email']); ?></div>
        <div class="row" style="margin-top:6px">
          <span class="pill">Szint: <b><?php echo h($lvlName); ?></b> (#<?php echo (int)$lvlNum; ?>)</span>
          <span class="pill">XP: <b><?php echo (int)$xp; ?></b></span>
          <span class="pill">Streak: <b><?php echo (int)$streak; ?></b> nap</span>
        </div>
      </div>
      <div class="row">
        <a class="btn" href="<?php echo h(app_url('/')); ?>">Térkép</a>
        <a class="btn" href="<?php echo h(app_url('/user/profile.php?id=' . (int)$userId)); ?>">Profilom</a>
        <a class="btn" href="<?php echo h(app_url('/user/settings.php')); ?>">Beállítások</a>
        <a class="btn" href="<?php echo h(app_url('/user/logout.php')); ?>">Kilépés</a>
      </div>
    </div>
  </div>
</header>

<div class="wrap">
  <?php if (!$rows): ?>
    <div class="card">
      <div class="title">Még nincs egyetlen ügyed sem.</div>
      <div class="muted">Menj vissza a térképre, kattints egy pontra és küldj bejelentést úgy, hogy belépve vagy.</div>
    </div>
  <?php else: ?>
    <div class="card" style="margin-bottom:12px">
      <div class="title">Jelvenyek</div>
      <?php if (!$badges): ?>
        <div class="muted">Meg nincs jelvenyed.</div>
      <?php else: ?>
        <div class="row" style="margin-top:8px">
          <?php foreach ($badges as $b): ?>
            <span class="pill">
              <?php $burl = !empty($b['code']) ? badge_icon_url($b['code']) : null; ?>
              <?php if ($burl): ?>
                <img src="<?= h($burl) ?>" alt="" style="width:16px;height:16px;vertical-align:-3px;margin-right:6px">
              <?php else: ?>
                <?php echo h($b['icon'] ?: '🏅'); ?>
              <?php endif; ?>
              <?php echo h($b['name']); ?>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="grid">
      <?php foreach ($rows as $r): ?>
        <div class="card">
          <div class="row" style="justify-content:space-between">
            <div class="title">#<?php echo (int)$r['id']; ?> — <?php echo h($catLabel[$r['category']] ?? $r['category']); ?></div>
            <span class="pill"><?php echo h($statusLabel[$r['status']] ?? $r['status']); ?></span>
          </div>
          <div class="small">Létrehozva: <?php echo h($r['created_at']); ?></div>
          <div class="hr"></div>
          <div><b>Rövid cím:</b> <?php echo h($r['title']); ?></div>
          <div class="muted" style="margin-top:6px"><?php echo nl2br(h($r['description'])); ?></div>
          <div class="hr"></div>
          <div class="small"><?php echo h($r['road'] ?: ''); ?> <?php echo h($r['suburb'] ?: ''); ?> <?php echo h($r['city'] ?: ''); ?></div>
          <div class="row" style="margin-top:10px">
            <a class="btn primary" href="<?php echo h(app_url('/user/report.php?id='.(int)$r['id'])); ?>">Megnyitás</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

</body>
</html>