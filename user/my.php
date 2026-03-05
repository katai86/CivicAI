<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

start_secure_session();

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
  header('Location: ' . app_url('/user/login.php'));
  exit;
}
$role = current_user_role() ?: '';

$stmt = db()->prepare("SELECT email, display_name, total_xp, level, streak_days FROM users WHERE id=:id LIMIT 1");
$stmt->execute([':id' => $userId]);
$u = $stmt->fetch();

$xp = (int)($u['total_xp'] ?? 0);
$lvlInfo = level_from_xp($xp);
$lvlName = $lvlInfo['name'] ?? 'Szint';
$lvlNum = (int)($u['level'] ?? $lvlInfo['level'] ?? 1);
$streak = (int)($u['streak_days'] ?? 0);

ensure_level_badge((int)$userId, $lvlNum);

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

$lbWeek = get_leaderboard('week', 10);
$lbMonth = get_leaderboard('month', 10);
$lbAll = get_leaderboard('all', 10);
$rankWeek = get_user_rank('week', (int)$userId);
$rankMonth = get_user_rank('month', (int)$userId);
$rankAll = get_user_rank('all', (int)$userId);

$categories = [
  'road' => 'Úthiba / kátyú',
  'sidewalk' => 'Járda / burkolat hiba',
  'lighting' => 'Közvilágítás',
  'trash' => 'Szemét / illegális',
  'green' => 'Zöldterület / veszélyes fa',
  'traffic' => 'Közlekedés / tábla',
  'idea' => 'Ötlet / javaslat',
  'civil_event' => 'Civil esemény',
];
$cat = isset($_GET['cat']) ? (string)$_GET['cat'] : 'road';
if (!isset($categories[$cat])) $cat = 'road';
$lbCatWeek = get_category_leaderboard('week', $cat, 10);
$lbCatMonth = get_category_leaderboard('month', $cat, 10);
$lbCatAll = get_category_leaderboard('all', $cat, 10);
$rankCatWeek = get_user_category_rank('week', (int)$userId, $cat);
$rankCatMonth = get_user_category_rank('month', (int)$userId, $cat);
$rankCatAll = get_user_category_rank('all', (int)$userId, $cat);

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
function avatar_url($filename){
  if (!$filename) return null;
  return app_url('/uploads/avatars/' . $filename);
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
  <title>Köz.Tér – Saját ügyeim</title>
  <link rel="stylesheet" href="/terkep/assets/style.css">
</head>
<body class="page">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?php echo h(app_url('/')); ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b>Köz.Tér</b>
    </a>
    <div class="topbar-links">
      <a class="topbtn" href="<?php echo h(app_url('/')); ?>">Térkép</a>
      <a class="topbtn" href="<?php echo h(app_url('/user/profile.php?id=' . (int)$userId)); ?>">Profilom</a>
      <a class="topbtn" href="<?php echo h(app_url('/user/friends.php')); ?>">Barátok</a>
      <a class="topbtn" href="<?php echo h(app_url('/user/settings.php')); ?>">Beállítások</a>
      <?php if ($role === 'govuser' || $role === 'admin' || $role === 'superadmin'): ?>
        <a class="topbtn" href="<?php echo h(app_url('/gov/index.php')); ?>">Közigazgatási</a>
      <?php endif; ?>
      <a class="topbtn" href="<?php echo h(app_url('/user/logout.php')); ?>">Kilépés</a>
    </div>
  </div>
</header>

  <div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
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
        <a class="btn" href="<?php echo h(app_url('/leaderboard.php')); ?>">Toplista</a>
      </div>
    </div>
  </div>

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
            <?php
              $code = (string)($b['code'] ?? '');
              $isLevel = strpos($code, 'level_') === 0;
              $burl = $code ? badge_icon_url($code) : null;
              $imgSize = $isLevel ? 100 : 20;
            ?>
            <span class="pill" style="<?= $isLevel ? 'padding:8px 12px;gap:8px;display:inline-flex;align-items:center' : '' ?>">
              <?php if ($burl): ?>
                <img src="<?= h($burl) ?>" alt="" style="width:<?= (int)$imgSize ?>px;height:<?= (int)$imgSize ?>px;vertical-align:middle;margin-right:<?= $isLevel ? '0' : '6px' ?>">
              <?php else: ?>
                <?php echo h($b['icon'] ?: '🏅'); ?>
              <?php endif; ?>
              <?php if (!$isLevel): ?>
                <?php echo h($b['name']); ?>
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card" style="margin-bottom:12px">
      <div class="title">Toplista (Top 10)</div>
      <div class="row" style="gap:8px;margin:8px 0 0 0;flex-wrap:wrap">
        <span class="pill">Helyezésem (heti): <?= $rankWeek ? ('#' . (int)$rankWeek['rank'] . ' • ' . (int)$rankWeek['points'] . ' XP') : 'nincs adat' ?></span>
        <span class="pill">Helyezésem (havi): <?= $rankMonth ? ('#' . (int)$rankMonth['rank'] . ' • ' . (int)$rankMonth['points'] . ' XP') : 'nincs adat' ?></span>
        <span class="pill">Helyezésem (összes): <?= $rankAll ? ('#' . (int)$rankAll['rank'] . ' • ' . (int)$rankAll['points'] . ' XP') : 'nincs adat' ?></span>
      </div>
      <div class="row" style="gap:8px;margin-top:8px">
        <div style="min-width:220px">
          <div class="small"><b>Heti</b></div>
          <?php if (!$lbWeek): ?>
            <div class="muted">Nincs adat.</div>
          <?php else: ?>
            <?php foreach ($lbWeek as $i => $row): ?>
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="small" style="display:flex;align-items:center;gap:8px">
                <span>#<?= (int)($i+1) ?></span>
                <?php if (!empty($row['avatar_filename'])): ?>
                  <img src="<?= h(avatar_url($row['avatar_filename'])) ?>" alt="" style="width:22px;height:22px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
                <?php endif; ?>
                <?php if ($lvlBadge): ?>
                  <img src="<?= h($lvlBadge) ?>" alt="" style="width:22px;height:22px;object-fit:cover">
                <?php endif; ?>
                <a href="<?= h(app_url('/user/profile.php?id=' . (int)$row['id'])) ?>" target="_blank">
                  <?= h($row['display_name'] ?: ('User #' . $row['id'])) ?>
                </a>
                <span class="muted">(<?= (int)$row['points'] ?> XP)</span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div style="min-width:220px">
          <div class="small"><b>Havi</b></div>
          <?php if (!$lbMonth): ?>
            <div class="muted">Nincs adat.</div>
          <?php else: ?>
            <?php foreach ($lbMonth as $i => $row): ?>
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="small" style="display:flex;align-items:center;gap:8px">
                <span>#<?= (int)($i+1) ?></span>
                <?php if (!empty($row['avatar_filename'])): ?>
                  <img src="<?= h(avatar_url($row['avatar_filename'])) ?>" alt="" style="width:22px;height:22px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
                <?php endif; ?>
                <?php if ($lvlBadge): ?>
                  <img src="<?= h($lvlBadge) ?>" alt="" style="width:22px;height:22px;object-fit:cover">
                <?php endif; ?>
                <a href="<?= h(app_url('/user/profile.php?id=' . (int)$row['id'])) ?>" target="_blank">
                  <?= h($row['display_name'] ?: ('User #' . $row['id'])) ?>
                </a>
                <span class="muted">(<?= (int)$row['points'] ?> XP)</span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div style="min-width:220px">
          <div class="small"><b>Összesített</b></div>
          <?php if (!$lbAll): ?>
            <div class="muted">Nincs adat.</div>
          <?php else: ?>
            <?php foreach ($lbAll as $i => $row): ?>
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="small" style="display:flex;align-items:center;gap:8px">
                <span>#<?= (int)($i+1) ?></span>
                <?php if (!empty($row['avatar_filename'])): ?>
                  <img src="<?= h(avatar_url($row['avatar_filename'])) ?>" alt="" style="width:22px;height:22px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
                <?php endif; ?>
                <?php if ($lvlBadge): ?>
                  <img src="<?= h($lvlBadge) ?>" alt="" style="width:22px;height:22px;object-fit:cover">
                <?php endif; ?>
                <a href="<?= h(app_url('/user/profile.php?id=' . (int)$row['id'])) ?>" target="_blank">
                  <?= h($row['display_name'] ?: ('User #' . $row['id'])) ?>
                </a>
                <span class="muted">(<?= (int)$row['points'] ?> XP)</span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="card" style="margin-bottom:12px">
      <div class="title">Kategória toplista (Top 10)</div>
      <div class="row" style="gap:6px;margin:8px 0 0 0;flex-wrap:wrap">
        <?php foreach ($categories as $key => $label): ?>
          <a class="pill" href="<?php echo h(app_url('/user/my.php?cat=' . $key)); ?>" style="<?php echo $key === $cat ? 'border-color:#c7d2fe;background:#eef2ff;color:#1e3a8a' : ''; ?>">
            <?php echo h($label); ?>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="row" style="gap:8px;margin:8px 0 0 0;flex-wrap:wrap">
        <span class="pill">Helyezésem (heti): <?= $rankCatWeek ? ('#' . (int)$rankCatWeek['rank'] . ' • ' . (int)$rankCatWeek['count'] . ' db') : 'nincs adat' ?></span>
        <span class="pill">Helyezésem (havi): <?= $rankCatMonth ? ('#' . (int)$rankCatMonth['rank'] . ' • ' . (int)$rankCatMonth['count'] . ' db') : 'nincs adat' ?></span>
        <span class="pill">Helyezésem (összes): <?= $rankCatAll ? ('#' . (int)$rankCatAll['rank'] . ' • ' . (int)$rankCatAll['count'] . ' db') : 'nincs adat' ?></span>
      </div>
      <div class="row" style="gap:8px;margin-top:8px">
        <div style="min-width:220px">
          <div class="small"><b>Heti</b></div>
          <?php if (!$lbCatWeek): ?>
            <div class="muted">Nincs adat.</div>
          <?php else: ?>
            <?php foreach ($lbCatWeek as $i => $row): ?>
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="small" style="display:flex;align-items:center;gap:8px">
                <span>#<?= (int)($i+1) ?></span>
                <?php if (!empty($row['avatar_filename'])): ?>
                  <img src="<?= h(avatar_url($row['avatar_filename'])) ?>" alt="" style="width:22px;height:22px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
                <?php endif; ?>
                <?php if ($lvlBadge): ?>
                  <img src="<?= h($lvlBadge) ?>" alt="" style="width:22px;height:22px;object-fit:cover">
                <?php endif; ?>
                <a href="<?= h(app_url('/user/profile.php?id=' . (int)$row['id'])) ?>" target="_blank">
                  <?= h($row['display_name'] ?: ('User #' . $row['id'])) ?>
                </a>
                <span class="muted">(<?= (int)$row['count'] ?> db)</span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div style="min-width:220px">
          <div class="small"><b>Havi</b></div>
          <?php if (!$lbCatMonth): ?>
            <div class="muted">Nincs adat.</div>
          <?php else: ?>
            <?php foreach ($lbCatMonth as $i => $row): ?>
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="small" style="display:flex;align-items:center;gap:8px">
                <span>#<?= (int)($i+1) ?></span>
                <?php if (!empty($row['avatar_filename'])): ?>
                  <img src="<?= h(avatar_url($row['avatar_filename'])) ?>" alt="" style="width:22px;height:22px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
                <?php endif; ?>
                <?php if ($lvlBadge): ?>
                  <img src="<?= h($lvlBadge) ?>" alt="" style="width:22px;height:22px;object-fit:cover">
                <?php endif; ?>
                <a href="<?= h(app_url('/user/profile.php?id=' . (int)$row['id'])) ?>" target="_blank">
                  <?= h($row['display_name'] ?: ('User #' . $row['id'])) ?>
                </a>
                <span class="muted">(<?= (int)$row['count'] ?> db)</span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        <div style="min-width:220px">
          <div class="small"><b>Összesített</b></div>
          <?php if (!$lbCatAll): ?>
            <div class="muted">Nincs adat.</div>
          <?php else: ?>
            <?php foreach ($lbCatAll as $i => $row): ?>
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="small" style="display:flex;align-items:center;gap:8px">
                <span>#<?= (int)($i+1) ?></span>
                <?php if (!empty($row['avatar_filename'])): ?>
                  <img src="<?= h(avatar_url($row['avatar_filename'])) ?>" alt="" style="width:22px;height:22px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
                <?php endif; ?>
                <?php if ($lvlBadge): ?>
                  <img src="<?= h($lvlBadge) ?>" alt="" style="width:22px;height:22px;object-fit:cover">
                <?php endif; ?>
                <a href="<?= h(app_url('/user/profile.php?id=' . (int)$row['id'])) ?>" target="_blank">
                  <?= h($row['display_name'] ?: ('User #' . $row['id'])) ?>
                </a>
                <span class="muted">(<?= (int)$row['count'] ?> db)</span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

  <div class="grid cols-2">
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