<?php
require_once __DIR__ . '/util.php';
start_secure_session();
$uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
$role = current_user_role() ?: 'guest';
$rankAll = $uid ? get_user_rank('all', $uid) : null;
$categories = [
  'road' => 'Úthiba',
  'sidewalk' => 'Járda',
  'lighting' => 'Közvilágítás',
  'trash' => 'Szemét',
  'green' => 'Zöld',
  'traffic' => 'Közlekedés',
  'idea' => 'Ötlet',
  'civil_event' => 'Civil',
];
$cat = isset($_GET['cat']) ? (string)$_GET['cat'] : 'road';
if (!isset($categories[$cat])) $cat = 'road';
$lbCatMini = get_category_leaderboard('week', $cat, 5);
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
      <?php if ($uid > 0 && $rankAll): ?>
        <span class="topbtn">
          Helyezésem: <b>#<?= (int)$rankAll['rank'] ?></b> (<?= (int)$rankAll['points'] ?> XP)
        </span>
      <?php endif; ?>
      <a class="topbtn" href="<?php echo htmlspecialchars(app_url('/leaderboard.php'), ENT_QUOTES, 'UTF-8'); ?>">Toplista</a>
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
      <div class="legend-item"><span class="legend-badge b-civil">🤝</span><span>Civil esemény</span></div>

      <div class="legend-foot muted">
        Tipp: a jelölőre kattintva megnyílik a részletek.
      </div>
    </div>
  </div>

  <div class="leaderboard-mini" id="lbMini" aria-label="Toplista">
    <button class="lb-toggle" type="button" id="lbToggle" aria-expanded="true">
      Toplista (heti) – <?= htmlspecialchars($categories[$cat], ENT_QUOTES, 'UTF-8') ?>
    </button>
    <div class="lb-body" id="lbBody">
    <div class="lb-tabs">
      <?php foreach ($categories as $key => $label): ?>
        <a class="lb-tab <?= $key === $cat ? 'active' : '' ?>" href="<?php echo htmlspecialchars(app_url('/?cat=' . $key), ENT_QUOTES, 'UTF-8'); ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
      <?php endforeach; ?>
    </div>
    <?php if (!$lbCatMini): ?>
      <div class="muted">Nincs adat.</div>
    <?php else: ?>
      <?php foreach ($lbCatMini as $i => $row): ?>
        <div class="lb-row">
          <span>#<?= (int)($i+1) ?></span>
          <?php if (!empty($row['avatar_filename'])): ?>
            <img class="lb-avatar" src="<?= htmlspecialchars(app_url('/uploads/avatars/' . $row['avatar_filename']), ENT_QUOTES, 'UTF-8'); ?>" alt="">
          <?php endif; ?>
          <?php $lvl = (int)($row['level'] ?? 0); ?>
          <?php if ($lvl > 0): ?>
            <img class="lb-badge" src="<?= htmlspecialchars(app_url('/assets/badges/level_' . $lvl . '.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="">
          <?php endif; ?>
          <span><?= htmlspecialchars($row['display_name'] ?: ('User #' . $row['id']), ENT_QUOTES, 'UTF-8'); ?></span>
          <span class="muted">(<?= (int)$row['count'] ?>)</span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <div class="lb-foot"><a href="<?php echo htmlspecialchars(app_url('/leaderboard.php?category=' . $cat), ENT_QUOTES, 'UTF-8'); ?>">Teljes toplista</a></div>
    </div>
  </div>
</div>

<script>
  window.TERKEP_LOGGED_IN = <?php echo ($uid > 0 ? 'true' : 'false'); ?>;
  window.TERKEP_ROLE = <?php echo json_encode($role, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  // JS oldalon ebből tudjuk, hogy belépett-e a felhasználó
  window.TERKEP_LOGGED_IN = <?php echo ($uid > 0) ? 'true' : 'false'; ?>;
  window.TERKEP_ROLE = <?php echo json_encode($role, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="/terkep/assets/app.js?v=26"></script>
<script>
  (function initLbToggle(){
    const btn = document.getElementById('lbToggle');
    const body = document.getElementById('lbBody');
    if (!btn || !body) return;
    btn.addEventListener('click', () => {
      const isOpen = btn.getAttribute('aria-expanded') === 'true';
      btn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
      body.style.display = isOpen ? 'none' : '';
    });
  })();
</script>
</body>
</html>
