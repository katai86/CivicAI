<?php
require_once __DIR__ . '/../util.php';
require_once __DIR__ . '/../db.php';

start_secure_session();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$role = current_user_role() ?: 'guest';

if (!empty($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
  set_lang($_GET['lang']);
  header('Location: ' . app_url('/mobile/index.php'));
  exit;
}
$currentLang = current_lang();
$LANG_JS = lang_array_for_js();
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=1, viewport-fit=cover" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
  <meta name="theme-color" content="#0f1721" />
  <title><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/Mobilekit_v2-9-1/HTML/assets/css/style.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/mobilekit_civicai.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="civicai-mobile" data-logged-in="<?= $uid > 0 ? '1' : '0' ?>" data-role="<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>" data-user-id="<?= $uid > 0 ? (int)$uid : '' ?>" data-lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>" data-app-base="<?= htmlspecialchars(defined('APP_BASE') ? APP_BASE : '/terkep', ENT_QUOTES, 'UTF-8') ?>" data-map-lat="<?= htmlspecialchars((string)(defined('MAP_CENTER_LAT') ? MAP_CENTER_LAT : 47.1625), ENT_QUOTES, 'UTF-8') ?>" data-map-lng="<?= htmlspecialchars((string)(defined('MAP_CENTER_LNG') ? MAP_CENTER_LNG : 19.5033), ENT_QUOTES, 'UTF-8') ?>" data-map-zoom="<?= htmlspecialchars((string)(defined('MAP_ZOOM') ? MAP_ZOOM : 7), ENT_QUOTES, 'UTF-8') ?>">

  <!-- loader (Mobilekit) -->
  <div id="loader">
    <div class="spinner-border text-primary" role="status"></div>
  </div>

  <!-- App Header -->
  <div class="appHeader bg-primary">
    <div class="left">
      <a href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>" class="headerButton">
        <i class="bi bi-arrow-left"></i>
      </a>
    </div>
    <div class="pageTitle">
      <?= htmlspecialchars(t('nav.map'), ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div class="right">
      <a href="#" class="headerButton toggle-searchbox" aria-label="<?= htmlspecialchars(t('search.aria'), ENT_QUOTES, 'UTF-8') ?>">
        <i class="bi bi-search"></i>
      </a>
    </div>
  </div>
  <!-- Search Component (ids needed by app.js) -->
  <div id="search" class="appHeader">
    <form class="search-form" id="mapSearchForm">
      <div class="form-group searchbox">
        <input id="mapSearchInput" type="search" class="form-control" placeholder="<?= htmlspecialchars(t('search.placeholder'), ENT_QUOTES, 'UTF-8') ?>">
        <a href="#" class="ms-1 close toggle-searchbox" aria-label="Close">
          <i class="bi bi-x-circle"></i>
        </a>
      </div>
      <div id="mapSearchResults" class="search-results" role="listbox" aria-label="<?= htmlspecialchars(t('search.results_aria'), ENT_QUOTES, 'UTF-8') ?>"></div>
    </form>
  </div>

  <!-- App Capsule -->
  <div id="appCapsule" class="full-height">
    <div id="mapWrap">
      <div id="map"></div>
      <button type="button" id="btnNewReport" class="fab-report" aria-label="<?= htmlspecialchars(t('fab.new_report'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(t('fab.new_report'), ENT_QUOTES, 'UTF-8') ?>">+ <?= htmlspecialchars(t('fab.report'), ENT_QUOTES, 'UTF-8') ?></button>
      <div class="legend" id="legend" aria-label="<?= htmlspecialchars(t('legend.title'), ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="legend-toggle" id="legendToggle" aria-expanded="false">
          <span class="legend-toggle-text"><?= htmlspecialchars(t('legend.title'), ENT_QUOTES, 'UTF-8') ?></span>
          <span class="legend-chevron" aria-hidden="true">▼</span>
          <span class="legend-count" id="legendCount">0</span>
        </button>
        <div class="legend-body" id="legendBody"></div>
      </div>
    </div>
  </div>

  <!-- App Bottom Menu -->
  <div class="appBottomMenu">
    <a href="<?= htmlspecialchars(app_url('/mobile/index.php'), ENT_QUOTES, 'UTF-8') ?>" class="item active">
      <div class="col">
        <i class="bi bi-map"></i>
        <strong class="name"><?= htmlspecialchars(t('nav.map'), ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </a>
    <a href="<?= htmlspecialchars(app_url('/user/my.php'), ENT_QUOTES, 'UTF-8') ?>" class="item">
      <div class="col">
        <i class="bi bi-flag"></i>
        <strong class="name"><?= htmlspecialchars(t('nav.my_reports'), ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </a>
    <a href="<?= htmlspecialchars(app_url('/user/settings.php'), ENT_QUOTES, 'UTF-8') ?>" class="item">
      <div class="col">
        <i class="bi bi-gear"></i>
        <strong class="name"><?= htmlspecialchars(t('nav.settings'), ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </a>
    <?php if (in_array($role ?? '', ['govuser', 'admin', 'superadmin'], true)): ?>
    <a href="<?= htmlspecialchars(app_url('/gov/index.php'), ENT_QUOTES, 'UTF-8') ?>" class="item">
      <div class="col">
        <i class="bi bi-building"></i>
        <strong class="name"><?= htmlspecialchars(t('nav.gov'), ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </a>
    <?php else: ?>
    <a href="<?= htmlspecialchars($uid > 0 ? app_url('/user/profile.php?id=' . (int)$uid) : app_url('/user/login.php'), ENT_QUOTES, 'UTF-8') ?>" class="item">
      <div class="col">
        <i class="bi bi-person"></i>
        <strong class="name"><?= htmlspecialchars($uid > 0 ? 'Profil' : t('nav.login'), ENT_QUOTES, 'UTF-8') ?></strong>
      </div>
    </a>
    <?php endif; ?>
  </div>

  <script src="<?= htmlspecialchars(app_url('/Mobilekit_v2-9-1/HTML/assets/js/lib/bootstrap.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(app_url('/Mobilekit_v2-9-1/HTML/assets/js/base.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>window.LANG = <?= json_encode($LANG_JS, JSON_UNESCAPED_UNICODE); ?>;</script>
  <script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <script src="<?= htmlspecialchars(app_url('/assets/app.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
</body>
</html>

