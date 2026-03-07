<?php
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';
$uid = 0;
$role = 'guest';
$rankAll = null;
$userPreferredTheme = null;
try {
    start_secure_session();
    $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    $role = current_user_role() ?: 'guest';
    if ($uid > 0 && function_exists('get_user_rank')) {
        $rankAll = get_user_rank('all', $uid);
    }
    if ($uid > 0) {
        try {
            $st = db()->prepare("SELECT preferred_theme FROM users WHERE id = :id LIMIT 1");
            $st->execute([':id' => $uid]);
            $row = $st->fetch();
            if ($row && ($row['preferred_theme'] === 'light' || $row['preferred_theme'] === 'dark')) {
                $userPreferredTheme = $row['preferred_theme'];
            }
        } catch (Throwable $e) { /* oszlop lehet még nincs */ }
    }
} catch (Throwable $e) {
    if (function_exists('log_error')) {
        log_error('Index bootstrap: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
}
if (!empty($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
    set_lang($_GET['lang']);
    header('Location: ' . app_url('/'));
    exit;
}
$currentLang = current_lang();
$LANG_JS = lang_array_for_js();
?><!doctype html>
<html lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?php echo htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8'); ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');<?php if ($userPreferredTheme): ?>if(!t)localStorage.setItem('civicai_theme',<?= json_encode($userPreferredTheme, JSON_UNESCAPED_UNICODE) ?>);t=localStorage.getItem('civicai_theme');<?php endif; ?>document.documentElement.setAttribute('data-theme',(t==='light'||t==='dark')?t:'dark');}catch(_){document.documentElement.setAttribute('data-theme','dark');}</script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body data-logged-in="<?php echo ($uid > 0 ? '1' : '0'); ?>" data-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" data-lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>" data-app-base="<?php echo htmlspecialchars(defined('APP_BASE') ? APP_BASE : '/terkep', ENT_QUOTES, 'UTF-8'); ?>" data-map-lat="<?php echo htmlspecialchars((string)(defined('MAP_CENTER_LAT') ? MAP_CENTER_LAT : 47.1625), ENT_QUOTES, 'UTF-8'); ?>" data-map-lng="<?php echo htmlspecialchars((string)(defined('MAP_CENTER_LNG') ? MAP_CENTER_LNG : 19.5033), ENT_QUOTES, 'UTF-8'); ?>" data-map-zoom="<?php echo htmlspecialchars((string)(defined('MAP_ZOOM') ? MAP_ZOOM : 7), ENT_QUOTES, 'UTF-8'); ?>">

<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?></b>
    </a>

    <form class="topbar-search" id="mapSearchForm">
      <div class="search-wrap">
        <input id="mapSearchInput" type="search" placeholder="<?= htmlspecialchars(t('search.placeholder'), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(t('search.aria'), ENT_QUOTES, 'UTF-8') ?>">
        <div id="mapSearchResults" class="search-results" role="listbox" aria-label="<?= htmlspecialchars(t('search.results_aria'), ENT_QUOTES, 'UTF-8') ?>"></div>
      </div>
      <button type="submit" class="search-btn" aria-label="<?= htmlspecialchars(t('search.btn'), ENT_QUOTES, 'UTF-8') ?>">
        <span class="icon-search" aria-hidden="true"></span>
        <span class="sr-only"><?= htmlspecialchars(t('search.btn'), ENT_QUOTES, 'UTF-8') ?></span>
      </button>
    </form>

    <div class="topbar-right">
      <div class="topbar-tools">
        <button type="button" id="themeToggle" class="topbtn topbtn-icon" aria-label="<?= htmlspecialchars(t('theme.aria'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(t('theme.dark'), ENT_QUOTES, 'UTF-8') ?>" data-title-light="<?= htmlspecialchars(t('theme.light'), ENT_QUOTES, 'UTF-8') ?>" data-title-dark="<?= htmlspecialchars(t('theme.dark'), ENT_QUOTES, 'UTF-8') ?>">
          <span class="theme-icon theme-sun" aria-hidden="true">☀️</span>
          <span class="theme-icon theme-moon" aria-hidden="true">🌙</span>
        </button>
        <div class="lang-dropdown">
          <button type="button" class="topbtn lang-btn" id="langToggle" aria-haspopup="listbox" aria-expanded="false" aria-label="<?= htmlspecialchars(t('lang.choose'), ENT_QUOTES, 'UTF-8') ?>">
            <span class="lang-label"><?= htmlspecialchars(strtoupper($currentLang), ENT_QUOTES, 'UTF-8') ?></span><span class="lang-chevron" aria-hidden="true">▼</span>
          </button>
          <div class="lang-menu" id="langMenu" role="listbox" aria-hidden="true">
            <?php foreach (LANG_ALLOWED as $code): ?>
              <a class="lang-option<?= $code === $currentLang ? ' active' : '' ?>" href="<?= htmlspecialchars(app_url('/?lang=' . $code), ENT_QUOTES, 'UTF-8') ?>" data-lang="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper($code), ENT_QUOTES, 'UTF-8') ?></a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <div class="topbar-links">
      <?php if ($role === 'govuser'): ?>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.map'), ENT_QUOTES, 'UTF-8') ?></a>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/settings.php'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.settings'), ENT_QUOTES, 'UTF-8') ?></a>
        <a class="topbtn primary" href="<?php echo htmlspecialchars(app_url('/gov/index.php'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.gov'), ENT_QUOTES, 'UTF-8') ?></a>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/logout.php'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.logout'), ENT_QUOTES, 'UTF-8') ?></a>
      <?php elseif ($uid > 0): ?>
      <?php if ($rankAll): ?>
        <span class="topbtn">
          <?= htmlspecialchars(t('nav.rank'), ENT_QUOTES, 'UTF-8') ?>: <b>#<?= (int)$rankAll['rank'] ?></b> (<?= (int)$rankAll['points'] ?> XP)
        </span>
      <?php endif; ?>
      <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/leaderboard.php'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.leaderboard'), ENT_QUOTES, 'UTF-8') ?></a>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/my.php'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.my_reports'), ENT_QUOTES, 'UTF-8') ?></a>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/friends.php'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.friends'), ENT_QUOTES, 'UTF-8') ?></a>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/settings.php'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.settings'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php if ($role === 'admin' || $role === 'superadmin'): ?>
          <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/gov/index.php'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.gov'), ENT_QUOTES, 'UTF-8') ?></a>
        <?php endif; ?>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/logout.php'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.logout'), ENT_QUOTES, 'UTF-8') ?></a>
      <?php else: ?>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.login'), ENT_QUOTES, 'UTF-8') ?></a>
        <a class="topbtn primary" href="<?php echo htmlspecialchars(app_url('/user/register.php'), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars(t('nav.register'), ENT_QUOTES, 'UTF-8') ?></a>
      <?php endif; ?>
    </div>
    </div>
  </div>
</header>

<div id="mapWrap">
  <div id="map"></div>
  <button type="button" id="btnNewReport" class="fab-report" aria-label="<?= htmlspecialchars(t('fab.new_report'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(t('fab.new_report'), ENT_QUOTES, 'UTF-8') ?>">+ <?= htmlspecialchars(t('fab.report'), ENT_QUOTES, 'UTF-8') ?></button>

  <div class="legend" id="legend" aria-label="<?= htmlspecialchars(t('legend.title'), ENT_QUOTES, 'UTF-8') ?>">
    <button type="button" class="legend-toggle" id="legendToggle" aria-expanded="false">
      <span class="legend-toggle-text"><?= htmlspecialchars(t('legend.title'), ENT_QUOTES, 'UTF-8') ?></span>
      <span class="legend-chevron" aria-hidden="true">▼</span>
      <span class="legend-count" id="legendCount">0</span>
    </button>

    <div class="legend-body" id="legendBody">
      <div class="legend-filters-single">
        <button class="legend-filter active" data-cat="all" type="button"><?= htmlspecialchars(t('legend.all'), ENT_QUOTES, 'UTF-8') ?></button>
      </div>
      <div class="legend-list">
        <button type="button" class="legend-item legend-item-btn" data-cat="road"><span class="legend-badge b-road">🚧</span><span><?= htmlspecialchars(t('cat.road_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
        <button type="button" class="legend-item legend-item-btn" data-cat="sidewalk"><span class="legend-badge b-sidewalk">🚶</span><span><?= htmlspecialchars(t('cat.sidewalk_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
        <button type="button" class="legend-item legend-item-btn" data-cat="lighting"><span class="legend-badge b-lighting">💡</span><span><?= htmlspecialchars(t('cat.lighting_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
        <button type="button" class="legend-item legend-item-btn" data-cat="trash"><span class="legend-badge b-trash">🗑️</span><span><?= htmlspecialchars(t('cat.trash_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
        <button type="button" class="legend-item legend-item-btn" data-cat="green"><span class="legend-badge b-green">🌳</span><span><?= htmlspecialchars(t('cat.green_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
        <button type="button" class="legend-item legend-item-btn" data-cat="traffic"><span class="legend-badge b-traffic">🚦</span><span><?= htmlspecialchars(t('cat.traffic_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
        <button type="button" class="legend-item legend-item-btn" data-cat="idea"><span class="legend-badge b-idea">❗</span><span><?= htmlspecialchars(t('cat.idea_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
        <button type="button" class="legend-item legend-item-btn" data-cat="civil_event"><span class="legend-badge b-civil">🤝</span><span><?= htmlspecialchars(t('cat.civil_event_desc'), ENT_QUOTES, 'UTF-8') ?></span></button>
      </div>

      <div class="legend-foot muted">
        <?= htmlspecialchars(t('legend.foot'), ENT_QUOTES, 'UTF-8') ?>
      </div>
    </div>
  </div>

</div>

<script>window.LANG = <?= json_encode($LANG_JS, JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo htmlspecialchars(app_url('/assets/app.js'), ENT_QUOTES, 'UTF-8'); ?>?v=28"></script>
<script src="<?php echo htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
