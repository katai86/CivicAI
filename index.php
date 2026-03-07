<?php
require_once __DIR__ . '/util.php';
start_secure_session();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$role = current_user_role() ?: 'guest';
$rankAll = $uid ? get_user_rank('all', $uid) : null;
?><!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Köz.Tér</title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="<?php echo htmlspecialchars(app_url('/assets/style.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body data-logged-in="<?php echo ($uid > 0 ? '1' : '0'); ?>" data-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" data-app-base="<?php echo htmlspecialchars(defined('APP_BASE') ? APP_BASE : '/terkep', ENT_QUOTES, 'UTF-8'); ?>" data-map-lat="<?php echo htmlspecialchars((string)(defined('MAP_CENTER_LAT') ? MAP_CENTER_LAT : 47.1625), ENT_QUOTES, 'UTF-8'); ?>" data-map-lng="<?php echo htmlspecialchars((string)(defined('MAP_CENTER_LNG') ? MAP_CENTER_LNG : 19.5033), ENT_QUOTES, 'UTF-8'); ?>" data-map-zoom="<?php echo htmlspecialchars((string)(defined('MAP_ZOOM') ? MAP_ZOOM : 7), ENT_QUOTES, 'UTF-8'); ?>">

<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b>Köz.Tér</b>
    </a>

    <form class="topbar-search" id="mapSearchForm">
      <div class="search-wrap">
        <input id="mapSearchInput" type="search" placeholder="Cím keresése (pl. Orosháza, Szabadság utca 12)" aria-label="Cím keresés">
        <div id="mapSearchResults" class="search-results" role="listbox" aria-label="Cím találatok"></div>
      </div>
      <button type="submit" class="search-btn" aria-label="Keresés">
        <span class="icon-search" aria-hidden="true"></span>
        <span class="sr-only">Keresés</span>
      </button>
    </form>

    <div class="topbar-links">
      <?php if ($role === 'govuser'): ?>
        <?php /* Gov user: csak térkép, beállítások, közigazgatási dashboard, kilépés – nincs barátok, saját ügyeim, toplista */ ?>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8'); ?>">Térkép</a>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/settings.php'), ENT_QUOTES, 'UTF-8'); ?>">Beállítások</a>
        <a class="topbtn primary" href="<?php echo htmlspecialchars(app_url('/gov/index.php'), ENT_QUOTES, 'UTF-8'); ?>">Közigazgatási</a>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/logout.php'), ENT_QUOTES, 'UTF-8'); ?>">Kilépés</a>
      <?php elseif ($uid > 0): ?>
      <?php if ($rankAll): ?>
        <span class="topbtn">
          Helyezésem: <b>#<?= (int)$rankAll['rank'] ?></b> (<?= (int)$rankAll['points'] ?> XP)
        </span>
      <?php endif; ?>
      <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/leaderboard.php'), ENT_QUOTES, 'UTF-8'); ?>">Toplista</a>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/my.php'), ENT_QUOTES, 'UTF-8'); ?>">Saját ügyeim</a>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/friends.php'), ENT_QUOTES, 'UTF-8'); ?>">Barátok</a>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/settings.php'), ENT_QUOTES, 'UTF-8'); ?>">Beállítások</a>
        <?php if ($role === 'admin' || $role === 'superadmin'): ?>
          <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/gov/index.php'), ENT_QUOTES, 'UTF-8'); ?>">Közigazgatási</a>
        <?php endif; ?>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/logout.php'), ENT_QUOTES, 'UTF-8'); ?>">Kilépés</a>
      <?php else: ?>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/login.php'), ENT_QUOTES, 'UTF-8'); ?>">Belépés</a>
        <a class="topbtn primary" href="<?php echo htmlspecialchars(app_url('/user/register.php'), ENT_QUOTES, 'UTF-8'); ?>">Regisztráció</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<div id="mapWrap">
  <div id="map"></div>
  <button type="button" id="btnNewReport" class="fab-report" aria-label="Új bejelentés" title="Új bejelentés">+ Bejelentés</button>

  <!-- Jelmagyarázat (mobilbarát: alapból összecsukva) -->
  <div class="legend" id="legend" aria-label="Jelmagyarázat">
    <button type="button" class="legend-toggle" id="legendToggle" aria-expanded="false">
      Jelmagyarázat
      <span class="legend-count" id="legendCount">0</span>
    </button>

    <div class="legend-body" id="legendBody">
      <div class="legend-filters" id="legendFilters">
        <button class="legend-filter active" data-cat="all" type="button">Összes</button>
        <button class="legend-filter" data-cat="road" type="button">Úthiba</button>
        <button class="legend-filter" data-cat="sidewalk" type="button">Járda</button>
        <button class="legend-filter" data-cat="lighting" type="button">Közvilágítás</button>
        <button class="legend-filter" data-cat="trash" type="button">Szemét</button>
        <button class="legend-filter" data-cat="green" type="button">Zöld</button>
        <button class="legend-filter" data-cat="traffic" type="button">Közlekedés</button>
        <button class="legend-filter" data-cat="idea" type="button">Ötlet</button>
        <button class="legend-filter" data-cat="civil_event" type="button">Civil</button>
      </div>
      <div class="legend-item"><span class="legend-badge b-road">🚧</span><span>Úthiba / kátyú</span></div>
      <div class="legend-item"><span class="legend-badge b-sidewalk">🚶</span><span>Járda / burkolat hiba</span></div>
      <div class="legend-item"><span class="legend-badge b-lighting">💡</span><span>Közvilágítás</span></div>
      <div class="legend-item"><span class="legend-badge b-trash">🗑️</span><span>Szemét / illegális</span></div>
      <div class="legend-item"><span class="legend-badge b-green">🌳</span><span>Zöldterület / veszélyes fa</span></div>
      <div class="legend-item"><span class="legend-badge b-traffic">🚦</span><span>Közlekedés / tábla</span></div>
      <div class="legend-item"><span class="legend-badge b-idea">❗</span><span>Ötlet / javaslat</span></div>
      <div class="legend-item"><span class="legend-badge b-civil">🤝</span><span>Civil esemény</span></div>

      <div class="legend-foot muted">
        Tipp: a jelölőre kattintva megnyílik a részletek.
      </div>
    </div>
  </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?php echo htmlspecialchars(app_url('/assets/app.js'), ENT_QUOTES, 'UTF-8'); ?>?v=27"></script>
</body>
</html>
