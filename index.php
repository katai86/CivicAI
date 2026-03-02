<?php
require_once __DIR__ . '/util.php';
start_secure_session();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
?><!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Problématérkép</title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
  <link rel="stylesheet" href="/terkep/assets/style.css">
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <div class="brand">
      <b>Problématérkép</b>
      <span class="muted">Kattints a térképre → kategória → leírás → <b>Beküldés</b> (ellenőrzés után látszik).</span>
    </div>

    <div class="topbar-links">
      <?php if ($uid > 0): ?>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/my.php'), ENT_QUOTES, 'UTF-8'); ?>">Saját ügyeim</a>
        <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/user/settings.php'), ENT_QUOTES, 'UTF-8'); ?>">Beállítások</a>
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

  <!-- Jelmagyarázat (mobilbarát: alapból összecsukva) -->
  <div class="legend" id="legend" aria-label="Jelmagyarázat">
    <button type="button" class="legend-toggle" id="legendToggle" aria-expanded="false">
      Jelmagyarázat
      <span class="legend-count" id="legendCount">0</span>
    </button>

    <div class="legend-body" id="legendBody">
      <div class="legend-item"><span class="legend-badge b-road">🚧</span><span>Úthiba / kátyú</span></div>
      <div class="legend-item"><span class="legend-badge b-sidewalk">🚶</span><span>Járda / burkolat hiba</span></div>
      <div class="legend-item"><span class="legend-badge b-lighting">💡</span><span>Közvilágítás</span></div>
      <div class="legend-item"><span class="legend-badge b-trash">🗑️</span><span>Szemét / illegális</span></div>
      <div class="legend-item"><span class="legend-badge b-green">🌳</span><span>Zöldterület / veszélyes fa</span></div>
      <div class="legend-item"><span class="legend-badge b-traffic">🚦</span><span>Közlekedés / tábla</span></div>
      <div class="legend-item"><span class="legend-badge b-idea">❗</span><span>Ötlet / javaslat</span></div>

      <div class="legend-foot muted">
        Tipp: a jelölőre kattintva megnyílik a részletek.
      </div>
    </div>
  </div>
</div>

<script>window.TERKEP_LOGGED_IN = <?php echo ($uid > 0 ? 'true' : 'false'); ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  // JS oldalon ebből tudjuk, hogy belépett-e a felhasználó
  window.TERKEP_LOGGED_IN = <?php echo ($uid > 0) ? 'true' : 'false'; ?>;
</script>
<script src="/terkep/assets/app.js?v=26"></script>
</body>
</html>
