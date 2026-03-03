<?php
require_once __DIR__ . '/../util.php';
require_admin();
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Admin – Problématérkép</title>

  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link rel="stylesheet" href="/terkep/assets/style.css">
</head>
<body class="page admin-page">

<div id="wrap">
  <div id="map" class="card"></div>

  <div id="side">
    <div class="card topcard">
      <div class="counts" id="counts"></div>

      <div class="row">
        <select id="status">
  <option value="new">Új</option>
  <option value="approved">Publikálva</option>
  <option value="in_progress">Megoldás alatt</option>
  <option value="solved">Megoldva</option>
  <option value="rejected">Elutasítva</option>
  <option value="pending">Pending (régi)</option>
  <option value="all">Összes</option>
</select>

        <button id="logout" class="soft" type="button">Kilépés</button>
      </div>

      <div class="row" style="grid-template-columns:1fr">
        <div class="btnbar">
          <button id="load" class="primary">Betöltés</button>
          <button id="refresh" class="soft">Frissítés</button>
        </div>
        <div class="hint">
          <div>
            Tipp: a listában egy sor fölé húzva az egeret odaugrik a térkép és megnyitja a popupot.
          </div>
          <div>
            <a href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>" target="_blank">Publikus térkép</a>
          </div>
        </div>
      </div>
    </div>

    <div class="card listcard">
      <div id="list">Kattints: <b>Betöltés</b>.</div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  window.APP_BASE = <?= json_encode(APP_BASE, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="admin.js?v=5"></script>
</body>
</html>