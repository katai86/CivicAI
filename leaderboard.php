<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

$lbWeek = get_leaderboard('week', 10);
$lbMonth = get_leaderboard('month', 10);
$lbAll = get_leaderboard('all', 10);

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
$cat = isset($_GET['category']) ? (string)$_GET['category'] : 'road';
if (!isset($categories[$cat])) $cat = 'road';
$lbCatWeek = get_category_leaderboard('week', $cat, 10);
$lbCatMonth = get_category_leaderboard('month', $cat, 10);
$lbCatAll = get_category_leaderboard('all', $cat, 10);

$uid = current_user_id() ?: 0;
$rankWeek = $uid ? get_user_rank('week', $uid) : null;
$rankMonth = $uid ? get_user_rank('month', $uid) : null;
$rankAll = $uid ? get_user_rank('all', $uid) : null;
$rankCatWeek = $uid ? get_user_category_rank('week', $uid, $cat) : null;
$rankCatMonth = $uid ? get_user_category_rank('month', $uid, $cat) : null;
$rankCatAll = $uid ? get_user_category_rank('all', $uid, $cat) : null;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function badge_icon_url($code){
  if (!$code) return null;
  $base = __DIR__ . '/assets/badges/' . $code;
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
  <title>Köz.Tér – Toplista</title>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
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
      <?php if ($uid > 0): ?>
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
    <div class="top">
      <div style="font-weight:900;font-size:18px">Toplista (Top 10)</div>
      <div><a class="btn" href="<?= h(app_url('/')) ?>">Térkép</a></div>
    </div>
  </div>
  <?php if ($uid): ?>
  <div class="card" style="margin-bottom:12px">
    <div class="title">Saját helyezésem</div>
    <div class="row" style="gap:8px;flex-wrap:wrap">
      <span class="pill">Heti: <?= $rankWeek ? ('#' . (int)$rankWeek['rank'] . ' • ' . (int)$rankWeek['points'] . ' XP') : 'nincs adat' ?></span>
      <span class="pill">Havi: <?= $rankMonth ? ('#' . (int)$rankMonth['rank'] . ' • ' . (int)$rankMonth['points'] . ' XP') : 'nincs adat' ?></span>
      <span class="pill">Összesített: <?= $rankAll ? ('#' . (int)$rankAll['rank'] . ' • ' . (int)$rankAll['points'] . ' XP') : 'nincs adat' ?></span>
    </div>
  </div>
  <?php endif; ?>

  <div class="grid cols-3">
    <div class="card">
      <div class="title">Heti</div>
      <?php if (!$lbWeek): ?>
        <div class="muted">Nincs adat.</div>
      <?php else: ?>
        <div class="list">
          <?php foreach ($lbWeek as $i => $row): ?>
            <?php $isMe = ($uid && (int)$row['id'] === (int)$uid); ?>
            <div class="rank <?= $isMe ? 'me' : '' ?>">
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="name" style="display:flex;align-items:center;gap:8px">
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
              </div>
              <div class="muted"><?= (int)$row['points'] ?> XP</div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="title">Havi</div>
      <?php if (!$lbMonth): ?>
        <div class="muted">Nincs adat.</div>
      <?php else: ?>
        <div class="list">
          <?php foreach ($lbMonth as $i => $row): ?>
            <?php $isMe = ($uid && (int)$row['id'] === (int)$uid); ?>
            <div class="rank <?= $isMe ? 'me' : '' ?>">
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="name" style="display:flex;align-items:center;gap:8px">
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
              </div>
              <div class="muted"><?= (int)$row['points'] ?> XP</div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="title">Összesített</div>
      <?php if (!$lbAll): ?>
        <div class="muted">Nincs adat.</div>
      <?php else: ?>
        <div class="list">
          <?php foreach ($lbAll as $i => $row): ?>
            <?php $isMe = ($uid && (int)$row['id'] === (int)$uid); ?>
            <div class="rank <?= $isMe ? 'me' : '' ?>">
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="name" style="display:flex;align-items:center;gap:8px">
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
              </div>
              <div class="muted"><?= (int)$row['points'] ?> XP</div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card" style="margin-top:12px">
    <div class="title">Kategória toplista (Top 10)</div>
    <div class="tabs" style="margin-top:6px">
      <?php foreach ($categories as $key => $label): ?>
        <a class="tab <?= $key === $cat ? 'active' : '' ?>" href="<?= h(app_url('/leaderboard.php?category=' . $key)) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($uid): ?>
      <div class="row" style="gap:8px;margin:8px 0 0 0;flex-wrap:wrap">
        <span class="pill">Helyezésem (heti): <?= $rankCatWeek ? ('#' . (int)$rankCatWeek['rank'] . ' • ' . (int)$rankCatWeek['count'] . ' db') : 'nincs adat' ?></span>
        <span class="pill">Helyezésem (havi): <?= $rankCatMonth ? ('#' . (int)$rankCatMonth['rank'] . ' • ' . (int)$rankCatMonth['count'] . ' db') : 'nincs adat' ?></span>
        <span class="pill">Helyezésem (összes): <?= $rankCatAll ? ('#' . (int)$rankCatAll['rank'] . ' • ' . (int)$rankCatAll['count'] . ' db') : 'nincs adat' ?></span>
      </div>
    <?php endif; ?>

    <div class="row" style="gap:8px;margin-top:8px">
      <div style="min-width:220px">
        <div class="small"><b>Heti</b></div>
        <?php if (!$lbCatWeek): ?>
          <div class="muted">Nincs adat.</div>
        <?php else: ?>
          <div class="list">
            <?php foreach ($lbCatWeek as $i => $row): ?>
              <?php $isMe = ($uid && (int)$row['id'] === (int)$uid); ?>
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="rank <?= $isMe ? 'me' : '' ?>">
                <div class="name" style="display:flex;align-items:center;gap:8px">
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
                </div>
                <div class="muted"><?= (int)$row['count'] ?> db</div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div style="min-width:220px">
        <div class="small"><b>Havi</b></div>
        <?php if (!$lbCatMonth): ?>
          <div class="muted">Nincs adat.</div>
        <?php else: ?>
          <div class="list">
            <?php foreach ($lbCatMonth as $i => $row): ?>
              <?php $isMe = ($uid && (int)$row['id'] === (int)$uid); ?>
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="rank <?= $isMe ? 'me' : '' ?>">
                <div class="name" style="display:flex;align-items:center;gap:8px">
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
                </div>
                <div class="muted"><?= (int)$row['count'] ?> db</div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <div style="min-width:220px">
        <div class="small"><b>Összesített</b></div>
        <?php if (!$lbCatAll): ?>
          <div class="muted">Nincs adat.</div>
        <?php else: ?>
          <div class="list">
            <?php foreach ($lbCatAll as $i => $row): ?>
              <?php $isMe = ($uid && (int)$row['id'] === (int)$uid); ?>
              <?php $lvlBadge = badge_icon_url('level_' . (int)$row['level']); ?>
              <div class="rank <?= $isMe ? 'me' : '' ?>">
                <div class="name" style="display:flex;align-items:center;gap:8px">
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
                </div>
                <div class="muted"><?= (int)$row['count'] ?> db</div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
</body>
</html>
