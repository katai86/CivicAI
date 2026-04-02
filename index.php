<?php
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';
$uid = 0;
$role = 'guest';
$rankAll = null;
$userPreferredTheme = null;
try {
    start_secure_session();
    // Mobilon (webapp) Mobilekit UI, desktopon marad a jelenlegi
    if (use_mobile_layout()) {
        header('Location: ' . app_url('/mobile/index.php'));
        exit;
    }
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
$flash = $_SESSION['flash'] ?? null;
if (isset($_SESSION['flash'])) unset($_SESSION['flash']);
?><!doctype html>
<html lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(app_url('/assets/fav_icon.png'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars(app_url('/assets/fav_icon.png'), ENT_QUOTES, 'UTF-8') ?>">
  <title><?php echo htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8'); ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');<?php if ($userPreferredTheme): ?>if(!t)localStorage.setItem('civicai_theme',<?= json_encode($userPreferredTheme, JSON_UNESCAPED_UNICODE) ?>);t=localStorage.getItem('civicai_theme');<?php endif; ?>t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');document.documentElement.setAttribute('data-bs-theme','dark');}</script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">
</head>
<body data-logged-in="<?php echo ($uid > 0 ? '1' : '0'); ?>" data-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" data-user-id="<?php echo $uid > 0 ? (int)$uid : ''; ?>" data-lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8'); ?>" data-app-base="<?php echo htmlspecialchars(defined('APP_BASE') ? APP_BASE : '/terkep', ENT_QUOTES, 'UTF-8'); ?>" data-map-lat="<?php echo htmlspecialchars((string)(defined('MAP_CENTER_LAT') ? MAP_CENTER_LAT : 47.1625), ENT_QUOTES, 'UTF-8'); ?>" data-map-lng="<?php echo htmlspecialchars((string)(defined('MAP_CENTER_LNG') ? MAP_CENTER_LNG : 19.5033), ENT_QUOTES, 'UTF-8'); ?>" data-map-zoom="<?php echo htmlspecialchars((string)(defined('MAP_ZOOM') ? MAP_ZOOM : 7), ENT_QUOTES, 'UTF-8'); ?>">

<?php $desktop_topbar_show_search = true; $show_tour_button = true; require __DIR__ . '/inc_desktop_topbar.php'; ?>

<div id="mapWrap">
  <?php if (!empty($flash)): ?>
  <div class="map-flash" id="mapFlash" role="status"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <div id="map"></div>
  <div class="desktop-map-actions">
    <button type="button" id="btnNewReport" class="fab-report fab-report-desktop" aria-label="<?= htmlspecialchars(t('fab.new_report'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(t('fab.new_report'), ENT_QUOTES, 'UTF-8') ?>">+ <?= htmlspecialchars(t('fab.report'), ENT_QUOTES, 'UTF-8') ?></button>
  </div>
</div>

<script>window.LANG = <?= json_encode($LANG_JS, JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script src="<?php echo htmlspecialchars(app_url('/assets/app.js'), ENT_QUOTES, 'UTF-8'); ?>?v=30"></script>
<script src="<?php echo htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
<script src="<?php echo htmlspecialchars(app_url('/assets/tour.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
(function(){ var b = document.getElementById('btnStartTour'); if (b && window.civicaiTour) b.addEventListener('click', function(){ window.civicaiTour.start(); }); })();
</script>
</body>
</html>
