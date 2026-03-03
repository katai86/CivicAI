<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../util.php';

$uid = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($uid <= 0) {
  http_response_code(400);
  echo 'Hibas felhasznalo.';
  exit;
}

$stmt = db()->prepare("
  SELECT id, display_name, total_xp, level, streak_days, avatar_filename, profile_public
  FROM users
  WHERE id = :id
  LIMIT 1
");
$stmt->execute([':id' => $uid]);
$u = $stmt->fetch();
if (!$u) {
  http_response_code(404);
  echo 'Felhasznalo nem talalhato.';
  exit;
}
if ((int)$u['profile_public'] !== 1) {
  http_response_code(403);
  echo 'Ez a profil nem nyilvanos.';
  exit;
}

$xp = (int)($u['total_xp'] ?? 0);
$lvlInfo = level_from_xp($xp);
$lvlName = $lvlInfo['name'] ?? 'Szint';
$lvlNum = (int)($u['level'] ?? $lvlInfo['level'] ?? 1);
$streak = (int)($u['streak_days'] ?? 0);

ensure_level_badge((int)$u['id'], $lvlNum);

$badges = [];
try {
  $stmt = db()->prepare("
    SELECT b.code, b.name, b.icon
    FROM user_badges ub
    JOIN badges b ON b.id = ub.badge_id
    WHERE ub.user_id = :uid
    ORDER BY ub.earned_at DESC, ub.id DESC
    LIMIT 50
  ");
  $stmt->execute([':uid' => $uid]);
  $badges = $stmt->fetchAll() ?: [];
} catch (Throwable $e) { $badges = []; }

$lbWeek = get_leaderboard('week', 10);
$lbMonth = get_leaderboard('month', 10);
$lbAll = get_leaderboard('all', 10);
$rankWeek = get_user_rank('week', (int)$u['id']);
$rankMonth = get_user_rank('month', (int)$u['id']);
$rankAll = get_user_rank('all', (int)$u['id']);

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
$rankCatWeek = get_user_category_rank('week', (int)$u['id'], $cat);
$rankCatMonth = get_user_category_rank('month', (int)$u['id'], $cat);
$rankCatAll = get_user_category_rank('all', (int)$u['id'], $cat);

$reports = [];
try {
  $stmt = db()->prepare("
    SELECT id, category, title, description, status, created_at
    FROM reports
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 50
  ");
  $stmt->execute([':uid' => $uid]);
  $reports = $stmt->fetchAll() ?: [];
} catch (Throwable $e) { $reports = []; }

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
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Köz.Tér – Profil</title>
  <link rel="stylesheet" href="/terkep/assets/style.css">
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
      <a class="topbtn" href="<?= h(app_url('/leaderboard.php')) ?>">Toplista</a>
      <?php if (current_user_id()): ?>
        <a class="topbtn" href="<?= h(app_url('/user/my.php')) ?>">Saját ügyeim</a>
        <a class="topbtn" href="<?= h(app_url('/user/settings.php')) ?>">Beállítások</a>
        <a class="topbtn" href="<?= h(app_url('/user/logout.php')) ?>">Kilépés</a>
      <?php else: ?>
        <a class="topbtn" href="<?= h(app_url('/user/login.php')) ?>">Belépés</a>
        <a class="topbtn primary" href="<?= h(app_url('/user/register.php')) ?>">Regisztráció</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="wrap">
  <div class="card">
    <div class="row">
      <?php if (!empty($u['avatar_filename'])): ?>
        <img src="<?= h(app_url('/uploads/avatars/' . $u['avatar_filename'])) ?>" alt="avatar" style="width:72px;height:72px;border-radius:999px;object-fit:cover;border:1px solid #e5e7eb">
      <?php else: ?>
        <div style="width:72px;height:72px;border-radius:999px;border:1px solid #e5e7eb;background:#f3f4f6;display:grid;place-items:center;color:#6b7280">?</div>
      <?php endif; ?>
      <div>
        <div style="font-weight:900;font-size:18px"><?= h($u['display_name'] ?: ('User #' . $u['id'])) ?></div>
        <div class="row" style="margin-top:6px">
          <span class="pill">Szint: <b><?= h($lvlName) ?></b> (#<?= (int)$lvlNum ?>)</span>
          <span class="pill">XP: <b><?= (int)$xp ?></b></span>
          <span class="pill">Streak: <b><?= (int)$streak ?></b> nap</span>
        </div>
      </div>
    </div>
  </div>

  <div class="card" style="margin-top:12px">
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

  <div class="card" style="margin-top:12px">
    <div class="title">Kategória toplista (Top 10)</div>
    <div class="row" style="gap:6px;margin:8px 0 0 0;flex-wrap:wrap">
      <?php foreach ($categories as $key => $label): ?>
        <a class="pill" href="<?= h(app_url('/user/profile.php?id=' . (int)$u['id'] . '&cat=' . $key)) ?>" style="<?= $key === $cat ? 'border-color:#c7d2fe;background:#eef2ff;color:#1e3a8a' : '' ?>">
          <?= h($label) ?>
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

  <div class="grid cols-2" style="margin-top:12px">
    <div class="card">
      <div class="title">Jelvenyek</div>
      <?php if (!$badges): ?>
        <div class="muted">Meg nincs jelvenye.</div>
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
                <?= h($b['icon'] ?: '🏅') ?>
              <?php endif; ?>
              <?php if (!$isLevel): ?>
                <?= h($b['name']) ?>
              <?php endif; ?>
            </span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="title">Utolso bejelentesek</div>
      <?php if (!$reports): ?>
        <div class="muted">Nincs megjelenitheto bejelentes.</div>
      <?php else: ?>
        <?php foreach ($reports as $r): ?>
          <div style="margin-bottom:10px">
            <b>#<?= (int)$r['id'] ?></b> <?= h($r['title'] ?: '') ?><br>
            <span class="muted"><?= h($r['created_at']) ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
