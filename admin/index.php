<?php
require_once __DIR__ . '/../util.php';
require_admin();
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Köz.Tér – Admin</title>

  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link rel="stylesheet" href="/terkep/assets/style.css">
  <link rel="stylesheet" href="/terkep/assets/admin.css">
</head>
<body class="page admin-page" data-app-base="<?= htmlspecialchars(APP_BASE, ENT_QUOTES, 'UTF-8') ?>">
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand brand-link" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">
      <span class="brand-logo" aria-hidden="true"></span>
      <b>Köz.Tér – Admin</b>
    </a>
    <div class="topbar-links">
      <a class="topbtn" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">Térkép</a>
      <a class="topbtn" href="<?= htmlspecialchars(app_url('/admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">Kilépés</a>
    </div>
  </div>
</header>

<div class="admin-dashboard">
  <section class="admin-kpis" id="kpiRow">
    <div class="kpi card">
      <div class="kpi-label">Új bejelentések (7 nap)</div>
      <div class="kpi-value" id="kpiReports7">—</div>
    </div>
    <div class="kpi card">
      <div class="kpi-label">Státusz megoszlás</div>
      <div class="kpi-value" id="kpiStatus">—</div>
    </div>
    <div class="kpi card">
      <div class="kpi-label">Új felhasználók (7 nap)</div>
      <div class="kpi-value" id="kpiUsers7">—</div>
    </div>
  </section>

  <div class="admin-content">
    <div id="map" class="card admin-map"></div>

    <div class="card admin-panel">
      <div class="admin-tabs">
        <button class="tab active" data-tab="reports" type="button">Bejelentések</button>
        <button class="tab" data-tab="users" type="button">Felhasználók</button>
        <button class="tab" data-tab="layers" type="button">Layerek</button>
      </div>

      <div class="admin-tab-body" id="tab-reports">
        <div class="admin-toolbar">
          <select id="statusFilter">
            <option value="new">Új</option>
            <option value="approved">Publikálva</option>
            <option value="in_progress">Megoldás alatt</option>
            <option value="solved">Megoldva</option>
            <option value="rejected">Elutasítva</option>
            <option value="pending">Pending (régi)</option>
            <option value="all">Összes</option>
          </select>
          <input id="reportSearch" type="search" placeholder="Keresés cím/ID/szöveg">
          <select id="reportLimit">
            <option value="200">200</option>
            <option value="300" selected>300</option>
            <option value="500">500</option>
            <option value="1000">1000</option>
            <option value="2000">2000</option>
          </select>
          <div class="btnbar">
            <button id="loadReports" class="primary" type="button">Betöltés</button>
            <button id="refreshReports" class="soft" type="button">Frissítés</button>
          </div>
        </div>

        <div class="counts" id="counts"></div>

        <div class="admin-list" id="reportList">Kattints: <b>Betöltés</b>.</div>
      </div>

      <div class="admin-tab-body" id="tab-users" hidden>
        <div class="admin-toolbar">
          <input id="userSearch" type="search" placeholder="Keresés név/e-mail">
          <select id="userRoleFilter">
            <option value="">Minden szerep</option>
            <option value="user">User</option>
            <option value="civil">Civil</option>
            <option value="admin">Admin</option>
            <option value="superadmin">SuperAdmin</option>
          </select>
          <select id="userActiveFilter">
            <option value="">Minden állapot</option>
            <option value="1">Aktív</option>
            <option value="0">Tiltott</option>
          </select>
          <button id="refreshUsers" class="soft" type="button">Frissítés</button>
        </div>
        <div class="admin-list" id="userList">Nincs adat.</div>
      </div>

      <div class="admin-tab-body" id="tab-layers" hidden>
        <div class="admin-toolbar">
          <input id="layerKey" placeholder="Layer kulcs (pl. election)">
          <input id="layerName" placeholder="Név (pl. Szavazóhelyiségek)">
          <select id="layerCategory">
            <option value="election">Választás</option>
            <option value="public">Közszolgáltató</option>
            <option value="tourism">Turisztika</option>
            <option value="trees">Faültetés</option>
          </select>
          <label class="check"><input type="checkbox" id="layerActive" checked> Aktív</label>
          <label class="check"><input type="checkbox" id="layerTemporary"> Ideiglenes</label>
          <input id="layerFrom" type="date" placeholder="Kezdete">
          <input id="layerTo" type="date" placeholder="Vége">
          <button id="createLayer" class="primary" type="button">Layer létrehozás</button>
        </div>

        <div class="admin-list" id="layerList">Nincs adat.</div>

        <div class="admin-subtitle">Pont hozzáadása</div>
        <div class="admin-toolbar">
          <select id="pointLayerSelect"></select>
          <input id="pointName" placeholder="Név">
          <input id="pointLat" placeholder="Lat">
          <input id="pointLng" placeholder="Lng">
          <input id="pointAddress" placeholder="Cím">
          <input id="pointMeta" placeholder="Meta JSON (opcionális)">
          <button id="createPoint" class="soft" type="button">Pont mentése</button>
        </div>
        <div class="admin-list" id="pointList">Válassz layert.</div>
      </div>
    </div>
  </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="admin.js?v=5"></script>
</body>
</html>