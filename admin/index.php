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
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
  <link rel="stylesheet" href="/terkep/dashboard/dist/css/adminlte.min.css">
  <link rel="stylesheet" href="/terkep/assets/admin.css">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary" data-app-base="<?= htmlspecialchars(APP_BASE, ENT_QUOTES, 'UTF-8') ?>">
<div class="app-wrapper">
  <nav class="app-header navbar navbar-expand bg-body">
    <div class="container-fluid">
      <ul class="navbar-nav">
        <li class="nav-item">
          <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button">
            <i class="bi bi-list"></i>
          </a>
        </li>
        <li class="nav-item d-none d-md-block">
          <span class="nav-link fw-semibold">Köz.Tér Admin</span>
        </li>
      </ul>
      <ul class="navbar-nav ms-auto">
        <li class="nav-item">
          <a class="nav-link" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>">Térkép</a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="<?= htmlspecialchars(app_url('/admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>">Kilépés</a>
        </li>
      </ul>
    </div>
  </nav>

  <aside class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark">
    <div class="sidebar-brand">
      <a href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>" class="brand-link">
        <span class="brand-text fw-light">Köz.Tér</span>
      </a>
    </div>
    <div class="sidebar-wrapper">
      <nav class="mt-2">
        <ul class="nav sidebar-menu flex-column">
          <li class="nav-item">
            <a href="#" class="nav-link tab active" data-tab="reports">
              <i class="nav-icon bi bi-flag-fill"></i>
              <p>Bejelentések</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="users">
              <i class="nav-icon bi bi-people-fill"></i>
              <p>Felhasználók</p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="layers">
              <i class="nav-icon bi bi-layers-fill"></i>
              <p>Layerek</p>
            </a>
          </li>
        </ul>
      </nav>
    </div>
  </aside>

  <main class="app-main">
    <div class="app-content">
      <div class="container-fluid">
        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <div class="card">
              <div class="card-body">
                <div class="text-secondary">Új bejelentések (7 nap)</div>
                <div class="fs-4 fw-bold" id="kpiReports7">—</div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card">
              <div class="card-body">
                <div class="text-secondary">Státusz megoszlás</div>
                <div class="fw-semibold" id="kpiStatus">—</div>
              </div>
            </div>
          </div>
          <div class="col-md-4">
            <div class="card">
              <div class="card-body">
                <div class="text-secondary">Új felhasználók (7 nap)</div>
                <div class="fs-4 fw-bold" id="kpiUsers7">—</div>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-lg-7">
            <div class="card">
              <div class="card-body p-0">
                <div id="map" class="admin-map"></div>
              </div>
            </div>
          </div>
          <div class="col-lg-5">
            <div class="card">
              <div class="card-body">
                <div class="admin-tab-body" id="tab-reports">
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <select id="statusFilter" class="form-select form-select-sm">
                      <option value="new">Új</option>
                      <option value="approved">Publikálva</option>
                      <option value="in_progress">Megoldás alatt</option>
                      <option value="solved">Megoldva</option>
                      <option value="rejected">Elutasítva</option>
                      <option value="pending">Pending (régi)</option>
                      <option value="all">Összes</option>
                    </select>
                    <input id="reportSearch" class="form-control form-control-sm" type="search" placeholder="Keresés cím/ID/szöveg">
                    <select id="reportLimit" class="form-select form-select-sm">
                      <option value="200">200</option>
                      <option value="300" selected>300</option>
                      <option value="500">500</option>
                      <option value="1000">1000</option>
                      <option value="2000">2000</option>
                    </select>
                    <div class="d-flex gap-2 ms-auto">
                      <button id="loadReports" class="btn btn-primary btn-sm" type="button">Betöltés</button>
                      <button id="refreshReports" class="btn btn-outline-secondary btn-sm" type="button">Frissítés</button>
                    </div>
                  </div>

                  <div class="counts mb-2" id="counts"></div>
                  <div class="admin-list" id="reportList">Kattints: <b>Betöltés</b>.</div>
                </div>

                <div class="admin-tab-body" id="tab-users" hidden>
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <input id="userSearch" class="form-control form-control-sm" type="search" placeholder="Keresés név/e-mail">
                    <select id="userRoleFilter" class="form-select form-select-sm">
                      <option value="">Minden szerep</option>
                      <option value="user">User</option>
                      <option value="civil">Civil</option>
                      <option value="admin">Admin</option>
                      <option value="superadmin">SuperAdmin</option>
                    </select>
                    <select id="userActiveFilter" class="form-select form-select-sm">
                      <option value="">Minden állapot</option>
                      <option value="1">Aktív</option>
                      <option value="0">Tiltott</option>
                    </select>
                    <button id="refreshUsers" class="btn btn-outline-secondary btn-sm ms-auto" type="button">Frissítés</button>
                  </div>
                  <div class="admin-list" id="userList">Nincs adat.</div>
                </div>

                <div class="admin-tab-body" id="tab-layers" hidden>
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <input id="layerKey" class="form-control form-control-sm" placeholder="Layer kulcs (pl. election)">
                    <input id="layerName" class="form-control form-control-sm" placeholder="Név (pl. Szavazóhelyiségek)">
                    <select id="layerCategory" class="form-select form-select-sm">
                      <option value="election">Választás</option>
                      <option value="public">Közszolgáltató</option>
                      <option value="tourism">Turisztika</option>
                      <option value="trees">Faültetés</option>
                    </select>
                    <label class="form-check form-check-inline">
                      <input type="checkbox" class="form-check-input" id="layerActive" checked>
                      <span class="form-check-label">Aktív</span>
                    </label>
                    <label class="form-check form-check-inline">
                      <input type="checkbox" class="form-check-input" id="layerTemporary">
                      <span class="form-check-label">Ideiglenes</span>
                    </label>
                    <input id="layerFrom" class="form-control form-control-sm" type="date" placeholder="Kezdete">
                    <input id="layerTo" class="form-control form-control-sm" type="date" placeholder="Vége">
                    <button id="createLayer" class="btn btn-primary btn-sm ms-auto" type="button">Layer létrehozás</button>
                  </div>

                  <div class="admin-list" id="layerList">Nincs adat.</div>

                  <div class="fw-semibold mt-3">Pont hozzáadása</div>
                  <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                    <select id="pointLayerSelect" class="form-select form-select-sm"></select>
                    <input id="pointName" class="form-control form-control-sm" placeholder="Név">
                    <input id="pointLat" class="form-control form-control-sm" placeholder="Lat">
                    <input id="pointLng" class="form-control form-control-sm" placeholder="Lng">
                    <input id="pointAddress" class="form-control form-control-sm" placeholder="Cím">
                    <input id="pointMeta" class="form-control form-control-sm" placeholder="Meta JSON (opcionális)">
                    <button id="createPoint" class="btn btn-outline-secondary btn-sm ms-auto" type="button">Pont mentése</button>
                  </div>
                  <div class="admin-list mt-2" id="pointList">Válassz layert.</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="/terkep/dashboard/dist/js/adminlte.min.js"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="admin.js?v=6"></script>
</body>
</html>