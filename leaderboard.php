<?php
require_once __DIR__ . '/util.php';

$lbWeek = get_leaderboard('week', 10);
$lbMonth = get_leaderboard('month', 10);
$lbAll = get_leaderboard('all', 10);

$uid = current_user_id() ?: 0;
$rankWeek = $uid ? get_user_rank('week', $uid) : null;
$rankMonth = $uid ? get_user_rank('month', $uid) : null;
$rankAll = $uid ? get_user_rank('all', $uid) : null;

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
  <title>Toplista</title>
  <style>
    :root{--b:#e5e7eb;--p:#2563eb;--m:#6b7280;}
    body{font:14px system-ui;background:#f6f7f9;margin:0}
    header{background:#fff;border-bottom:1px solid var(--b)}
    .wrap{max-width:1100px;margin:0 auto;padding:12px 14px}
    .top{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
    .card{background:#fff;border:1px solid var(--b);border-radius:16px;box-shadow:0 10px 30px rgba(0,0,0,.06);padding:14px}
    .grid{display:grid;gap:12px}
    @media(min-width:860px){ .grid{grid-template-columns:1fr 1fr 1fr} }
    .title{font-size:16px;font-weight:800;margin:0 0 6px 0}
    .muted{color:var(--m)}
    .list{display:grid;gap:6px}
    .rank{display:flex;justify-content:space-between;align-items:center;padding:6px 8px;border-radius:10px}
    .rank.me{background:#eef2ff;border:1px solid #c7d2fe}
    .rank .name a{color:#2563eb;text-decoration:none}
    .pill{display:inline-block;padding:3px 10px;border-radius:999px;border:1px solid var(--b);background:#f9fafb;font-size:12px}
  </style>
</head>
<body>
<header>
  <div class="wrap">
    <div class="top">
      <div style="font-weight:900;font-size:18px">Toplista (Top 10)</div>
      <div><a href="<?= h(app_url('/')) ?>">← Vissza a térképre</a></div>
    </div>
  </div>
</header>

<div class="wrap">
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

  <div class="grid">
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
</div>
</body>
</html>
