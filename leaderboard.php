<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';
start_secure_session();
$currentLang = current_lang();
// Mobil shell csak mobil eszközön
$isMobile = function_exists('is_mobile_device') ? is_mobile_device() : false;

$lbWeek = get_leaderboard('week', 10);
$lbMonth = get_leaderboard('month', 10);
$lbAll = get_leaderboard('all', 10);

$categories = [
  'road' => t('cat.road_desc'),
  'sidewalk' => t('cat.sidewalk_desc'),
  'lighting' => t('cat.lighting_desc'),
  'trash' => t('cat.trash_desc'),
  'green' => t('cat.green_desc'),
  'traffic' => t('cat.traffic_desc'),
  'idea' => t('cat.idea_desc'),
  'civil_event' => t('cat.civil_event_desc'),
];
$cat = isset($_GET['category']) ? (string)$_GET['category'] : 'road';
if (!isset($categories[$cat])) $cat = 'road';
$lbCatWeek = get_category_leaderboard('week', $cat, 10);
$lbCatMonth = get_category_leaderboard('month', $cat, 10);
$lbCatAll = get_category_leaderboard('all', $cat, 10);

$uid = current_user_id() ?: 0;
$role = current_user_role() ?: 'guest';
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
<html lang="<?= h($currentLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,minimum-scale=1,maximum-scale=1,viewport-fit=cover">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="theme-color" content="#0f1721">
  <title><?= h(t('site.name')) ?> – <?= h(t('lb.title')) ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');document.documentElement.setAttribute('data-bs-theme','dark');}</script>
  <?php if ($isMobile): ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/Mobilekit_v2-9-1/HTML/assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
  <?php if ($isMobile): ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/mobilekit_civicai.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
  <?php endif; ?>
</head>
<body class="page<?= $isMobile ? ' civicai-mobile' : '' ?>">
<?php if ($isMobile): ?>
  <?php $mobilePageTitle = t('lb.title'); $mobileActiveTab = ''; $mobileBackUrl = app_url('/'); require __DIR__ . '/inc_mobile_header.php'; ?>
<?php else: ?>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= h(app_url('/')) ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b><?= h(t('site.name')) ?></b>
    </a>
    <?php include __DIR__ . '/user/inc_topbar_tools.php'; ?>
    <div class="topbar-links">
      <a class="topbtn" href="<?= h(app_url('/')) ?>"><?= h(t('nav.map')) ?></a>
      <?php if ($uid > 0): ?>
        <a class="topbtn" href="<?= h(app_url('/user/my.php')) ?>"><?= h(t('nav.my_reports')) ?></a>
        <a class="topbtn" href="<?= h(app_url('/user/settings.php')) ?>"><?= h(t('nav.settings')) ?></a>
        <a class="topbtn" href="<?= h(app_url('/user/logout.php')) ?>"><?= h(t('nav.logout')) ?></a>
      <?php else: ?>
        <a class="topbtn" href="<?= h(app_url('/user/login.php')) ?>"><?= h(t('nav.login')) ?></a>
        <a class="topbtn primary" href="<?= h(app_url('/user/register.php')) ?>"><?= h(t('nav.register')) ?></a>
      <?php endif; ?>
    </div>
  </div>
</header>
<?php if (!$isMobile): ?>
<div class="wrap">
<?php endif; ?>
  <div class="card">
    <div class="top">
      <div style="font-weight:900;font-size:18px"><?= h(t('lb.title')) ?></div>
      <div><a class="btn" href="<?= h(app_url('/')) ?>"><?= h(t('nav.map')) ?></a></div>
    </div>
  </div>
  <?php if ($uid): ?>
  <div class="card" style="margin-bottom:12px">
    <div class="title"><?= h(t('lb.my_rank')) ?></div>
    <div class="row" style="gap:8px;flex-wrap:wrap">
      <span class="pill"><?= h(t('lb.week')) ?>: <?= $rankWeek ? ('#' . (int)$rankWeek['rank'] . ' • ' . (int)$rankWeek['points'] . ' XP') : t('user.no_rank') ?></span>
      <span class="pill"><?= h(t('lb.month')) ?>: <?= $rankMonth ? ('#' . (int)$rankMonth['rank'] . ' • ' . (int)$rankMonth['points'] . ' XP') : t('user.no_rank') ?></span>
      <span class="pill"><?= h(t('lb.all')) ?>: <?= $rankAll ? ('#' . (int)$rankAll['rank'] . ' • ' . (int)$rankAll['points'] . ' XP') : t('user.no_rank') ?></span>
    </div>
  </div>
  <?php endif; ?>

  <div class="grid cols-3">
    <div class="card">
      <div class="title"><?= h(t('lb.week')) ?></div>
      <?php if (!$lbWeek): ?>
        <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
      <div class="title"><?= h(t('lb.month')) ?></div>
      <?php if (!$lbMonth): ?>
        <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
      <div class="title"><?= h(t('lb.all')) ?></div>
      <?php if (!$lbAll): ?>
        <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
    <div class="title"><?= h(t('lb.category_top')) ?></div>
    <div class="tabs" style="margin-top:6px">
      <?php foreach ($categories as $key => $label): ?>
        <a class="tab <?= $key === $cat ? 'active' : '' ?>" href="<?= h(app_url('/leaderboard.php?category=' . $key)) ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>

    <?php if ($uid): ?>
      <div class="row" style="gap:8px;margin:8px 0 0 0;flex-wrap:wrap">
        <span class="pill"><?= h(t('user.rank_week')) ?>: <?= $rankCatWeek ? ('#' . (int)$rankCatWeek['rank'] . ' • ' . (int)$rankCatWeek['count'] . ' db') : t('user.no_rank') ?></span>
        <span class="pill"><?= h(t('user.rank_month')) ?>: <?= $rankCatMonth ? ('#' . (int)$rankCatMonth['rank'] . ' • ' . (int)$rankCatMonth['count'] . ' db') : t('user.no_rank') ?></span>
        <span class="pill"><?= h(t('user.rank_all')) ?>: <?= $rankCatAll ? ('#' . (int)$rankCatAll['rank'] . ' • ' . (int)$rankCatAll['count'] . ' db') : t('user.no_rank') ?></span>
      </div>
    <?php endif; ?>

    <div class="row" style="gap:8px;margin-top:8px">
      <div style="min-width:220px">
        <div class="small"><b><?= h(t('lb.week')) ?></b></div>
        <?php if (!$lbCatWeek): ?>
          <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
        <div class="small"><b><?= h(t('lb.month')) ?></b></div>
        <?php if (!$lbCatMonth): ?>
          <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
        <div class="small"><b><?= h(t('lb.all')) ?></b></div>
        <?php if (!$lbCatAll): ?>
          <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
<?php if (!$isMobile): ?>
</div>
<?php endif; ?>
<?php if ($isMobile): ?>
  <?php require __DIR__ . '/inc_mobile_footer.php'; ?>
  <script src="<?= htmlspecialchars(app_url('/Mobilekit_v2-9-1/HTML/assets/js/lib/bootstrap.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(app_url('/Mobilekit_v2-9-1/HTML/assets/js/base.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<?php endif; ?>
<script src="<?= h(app_url('/assets/theme-lang.js')) ?>"></script>
</body>
</html>
