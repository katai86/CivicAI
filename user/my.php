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

$currentLang = current_lang();
$statusLabel = [
  'pending' => t('status.pending'), 'approved' => t('status.approved'), 'rejected' => t('status.rejected'),
  'new' => t('status.new'), 'needs_info' => t('status.needs_info'), 'forwarded' => t('status.forwarded'),
  'waiting_reply' => t('status.waiting_reply'), 'in_progress' => t('status.in_progress'), 'solved' => t('status.solved'), 'closed' => t('status.closed'),
];
$catLabel = [
  'road'=>t('cat.road_desc'), 'sidewalk'=>t('cat.sidewalk_desc'), 'lighting'=>t('cat.lighting_desc'), 'trash'=>t('cat.trash_desc'),
  'green'=>t('cat.green_desc'), 'traffic'=>t('cat.traffic_desc'), 'idea'=>t('cat.idea_desc'), 'civil_event'=>t('cat.civil_event_desc'),
];
?>
<!doctype html>
<html lang="<?= h($currentLang) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= h(t('site.name')) ?> – <?= h(t('user.my_reports')) ?></title>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body class="page">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?php echo h(app_url('/')); ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b><?= h(t('site.name')) ?></b>
    </a>
    <div class="topbar-links">
      <a class="topbtn" href="<?php echo h(app_url('/')); ?>"><?= h(t('nav.map')) ?></a>
      <a class="topbtn" href="<?php echo h(app_url('/user/profile.php?id=' . (int)$userId)); ?>"><?= h(t('user.profile')) ?></a>
      <a class="topbtn" href="<?php echo h(app_url('/user/friends.php')); ?>"><?= h(t('nav.friends')) ?></a>
      <a class="topbtn" href="<?php echo h(app_url('/user/settings.php')); ?>"><?= h(t('nav.settings')) ?></a>
      <?php if ($role === 'govuser' || $role === 'admin' || $role === 'superadmin'): ?>
        <a class="topbtn" href="<?php echo h(app_url('/gov/index.php')); ?>"><?= h(t('nav.gov')) ?></a>
      <?php endif; ?>
      <a class="topbtn" href="<?php echo h(app_url('/user/logout.php')); ?>"><?= h(t('nav.logout')) ?></a>
    </div>
  </div>
</header>

  <div class="wrap">
  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <div style="font-weight:900;font-size:18px"><?= h(t('user.my_reports')) ?></div>
        <div class="muted"><?php echo h($u['display_name'] ?: $u['email']); ?></div>
        <div class="row" style="margin-top:6px">
          <span class="pill"><?= h(t('user.level')) ?>: <b><?php echo h($lvlName); ?></b> (#<?php echo (int)$lvlNum; ?>)</span>
          <span class="pill">XP: <b><?php echo (int)$xp; ?></b></span>
          <span class="pill">Streak: <b><?php echo (int)$streak; ?></b> <?= h(t('user.streak_days')) ?></span>
        </div>
      </div>
      <div class="row">
        <a class="btn" href="<?php echo h(app_url('/leaderboard.php')); ?>"><?= h(t('nav.leaderboard')) ?></a>
      </div>
    </div>
  </div>

  <?php if (!$rows): ?>
    <div class="card">
      <div class="title"><?= h(t('user.no_reports')) ?></div>
      <div class="muted"><?= h(t('user.no_reports_hint')) ?></div>
    </div>
  <?php else: ?>
    <div class="card" style="margin-bottom:12px">
      <div class="title"><?= h(t('user.badges')) ?></div>
      <?php if (!$badges): ?>
        <div class="muted"><?= h(t('user.no_badges')) ?></div>
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
      <div class="title"><?= h(t('user.leaderboard_top')) ?></div>
      <div class="row" style="gap:8px;margin:8px 0 0 0;flex-wrap:wrap">
        <span class="pill"><?= h(t('user.rank_week')) ?>: <?= $rankWeek ? ('#' . (int)$rankWeek['rank'] . ' • ' . (int)$rankWeek['points'] . ' XP') : t('user.no_rank') ?></span>
        <span class="pill"><?= h(t('user.rank_month')) ?>: <?= $rankMonth ? ('#' . (int)$rankMonth['rank'] . ' • ' . (int)$rankMonth['points'] . ' XP') : t('user.no_rank') ?></span>
        <span class="pill"><?= h(t('user.rank_all')) ?>: <?= $rankAll ? ('#' . (int)$rankAll['rank'] . ' • ' . (int)$rankAll['points'] . ' XP') : t('user.no_rank') ?></span>
      </div>
      <div class="row" style="gap:8px;margin-top:8px">
        <div style="min-width:220px">
          <div class="small"><b><?= h(t('user.period_week')) ?></b></div>
          <?php if (!$lbWeek): ?>
            <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
          <div class="small"><b><?= h(t('user.period_month')) ?></b></div>
          <?php if (!$lbMonth): ?>
            <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
          <div class="small"><b><?= h(t('user.period_all')) ?></b></div>
          <?php if (!$lbAll): ?>
            <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
      <div class="title"><?= h(t('user.category_top')) ?></div>
      <div class="row" style="gap:6px;margin:8px 0 0 0;flex-wrap:wrap">
        <?php foreach ($categories as $key => $label): ?>
          <a class="pill" href="<?php echo h(app_url('/user/my.php?cat=' . $key)); ?>" style="<?php echo $key === $cat ? 'border-color:#c7d2fe;background:#eef2ff;color:#1e3a8a' : ''; ?>">
            <?php echo h($label); ?>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="row" style="gap:8px;margin:8px 0 0 0;flex-wrap:wrap">
        <span class="pill"><?= h(t('user.rank_week')) ?>: <?= $rankCatWeek ? ('#' . (int)$rankCatWeek['rank'] . ' • ' . (int)$rankCatWeek['count'] . ' db') : t('user.no_rank') ?></span>
        <span class="pill"><?= h(t('user.rank_month')) ?>: <?= $rankCatMonth ? ('#' . (int)$rankCatMonth['rank'] . ' • ' . (int)$rankCatMonth['count'] . ' db') : t('user.no_rank') ?></span>
        <span class="pill"><?= h(t('user.rank_all')) ?>: <?= $rankCatAll ? ('#' . (int)$rankCatAll['rank'] . ' • ' . (int)$rankCatAll['count'] . ' db') : t('user.no_rank') ?></span>
      </div>
      <div class="row" style="gap:8px;margin-top:8px">
        <div style="min-width:220px">
          <div class="small"><b><?= h(t('user.period_week')) ?></b></div>
          <?php if (!$lbCatWeek): ?>
            <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
          <div class="small"><b><?= h(t('user.period_month')) ?></b></div>
          <?php if (!$lbCatMonth): ?>
            <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
          <div class="small"><b><?= h(t('user.period_all')) ?></b></div>
          <?php if (!$lbCatAll): ?>
            <div class="muted"><?= h(t('gov.no_data')) ?></div>
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
          <div class="small"><?= h(t('user.created')) ?>: <?php echo h($r['created_at']); ?></div>
          <div class="hr"></div>
          <div><b><?= h(t('user.short_title')) ?>:</b> <?php echo h($r['title']); ?></div>
          <div class="muted" style="margin-top:6px"><?php echo nl2br(h($r['description'])); ?></div>
          <div class="hr"></div>
          <div class="small"><?php echo h($r['road'] ?: ''); ?> <?php echo h($r['suburb'] ?: ''); ?> <?php echo h($r['city'] ?: ''); ?></div>
          <div class="row" style="margin-top:10px">
            <a class="btn primary" href="<?php echo h(app_url('/user/report.php?id='.(int)$r['id'])); ?>"><?= h(t('user.open')) ?></a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

</body>
</html>