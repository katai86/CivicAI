<?php
try {
  require_once __DIR__ . '/../util.php';
  require_admin();
  start_secure_session();
} catch (Throwable $e) {
  if (function_exists('log_error')) log_error('Admin bootstrap: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
  header('Content-Type: text/html; charset=utf-8');
  http_response_code(500);
  echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Admin hiba</title></head><body style="font-family:sans-serif;padding:2rem;max-width:600px;">';
  echo '<h1>Admin betöltési hiba</h1><p><strong>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</strong></p>';
  echo '<p>Fájl: ' . htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8') . ' (sor ' . (int)$e->getLine() . ')</p>';
  echo '<p>Ellenőrizd a szerver <code>error.log</code> vagy a projekt gyökérben <code>error.log</code> fájlt.</p></body></html>';
  exit;
}
if (!empty($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
  set_lang($_GET['lang']);
  header('Location: ' . (isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : app_url('/admin/index.php')));
  exit;
}
$currentLang = current_lang();
$LANG_JS = lang_array_for_js();
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?> – Admin</title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');document.documentElement.setAttribute('data-bs-theme','dark');}</script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/dashboard/dist/css/adminlte.min.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/admin.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary" data-app-base="<?= htmlspecialchars(defined('APP_BASE') ? APP_BASE : (defined('APP_BASE_URL') ? rtrim(parse_url(APP_BASE_URL, PHP_URL_PATH) ?: '/terkep', '/') : '/terkep'), ENT_QUOTES, 'UTF-8') ?>" data-map-lat="<?= htmlspecialchars((string)(defined('MAP_CENTER_LAT') ? MAP_CENTER_LAT : 46.565), ENT_QUOTES, 'UTF-8') ?>" data-map-lng="<?= htmlspecialchars((string)(defined('MAP_CENTER_LNG') ? MAP_CENTER_LNG : 20.667), ENT_QUOTES, 'UTF-8') ?>" data-map-zoom="<?= htmlspecialchars((string)(defined('MAP_ZOOM') ? MAP_ZOOM : 13), ENT_QUOTES, 'UTF-8') ?>">
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
          <span class="nav-link fw-semibold"><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?> Admin</span>
        </li>
      </ul>
      <ul class="navbar-nav ms-auto align-items-center">
        <li class="nav-item">
          <button type="button" id="themeToggle" class="btn btn-link nav-link py-2" aria-label="<?= htmlspecialchars(t('theme.aria'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(t('theme.dark'), ENT_QUOTES, 'UTF-8') ?>" data-title-light="<?= htmlspecialchars(t('theme.light'), ENT_QUOTES, 'UTF-8') ?>" data-title-dark="<?= htmlspecialchars(t('theme.dark'), ENT_QUOTES, 'UTF-8') ?>">
            <span class="theme-icon theme-sun" aria-hidden="true"><i class="bi bi-sun-fill"></i></span>
            <span class="theme-icon theme-moon" aria-hidden="true"><i class="bi bi-moon-fill"></i></span>
          </button>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="adminLangDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false"><?= htmlspecialchars(strtoupper($currentLang), ENT_QUOTES, 'UTF-8') ?></a>
          <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="adminLangDropdown">
            <?php foreach (LANG_ALLOWED as $code): ?>
              <li><a class="dropdown-item<?= $code === $currentLang ? ' active' : '' ?>" href="<?= htmlspecialchars(app_url('/admin/index.php?lang=' . $code), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(strtoupper($code), ENT_QUOTES, 'UTF-8') ?></a></li>
            <?php endforeach; ?>
          </ul>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.map'), ENT_QUOTES, 'UTF-8') ?></a>
        </li>
        <li class="nav-item">
          <a class="nav-link" href="<?= htmlspecialchars(app_url('/admin/logout.php'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(t('nav.logout'), ENT_QUOTES, 'UTF-8') ?></a>
        </li>
      </ul>
    </div>
  </nav>

  <aside class="app-sidebar bg-body-secondary shadow">
    <div class="sidebar-brand">
      <a href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>" class="brand-link">
        <span class="brand-text fw-light">CivicAI</span>
      </a>
    </div>
    <div class="sidebar-wrapper">
      <nav class="mt-2">
        <ul class="nav sidebar-menu flex-column">
          <li class="nav-item">
            <a href="#" class="nav-link tab active" data-tab="reports">
              <i class="nav-icon bi bi-flag-fill"></i>
              <p><?= htmlspecialchars(t('admin.reports'), ENT_QUOTES, 'UTF-8') ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="users">
              <i class="nav-icon bi bi-people-fill"></i>
              <p><?= htmlspecialchars(t('admin.users'), ENT_QUOTES, 'UTF-8') ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="layers">
              <i class="nav-icon bi bi-layers-fill"></i>
              <p><?= htmlspecialchars(t('admin.layers'), ENT_QUOTES, 'UTF-8') ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="authorities">
              <i class="nav-icon bi bi-shield-check"></i>
              <p><?= htmlspecialchars(t('admin.authorities'), ENT_QUOTES, 'UTF-8') ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="budget">
              <i class="nav-icon bi bi-cash-stack"></i>
              <p><?= htmlspecialchars(t('admin.budget_projects') ?: 'Költségvetési projektek'), ENT_QUOTES, 'UTF-8') ?></p>
            </a>
          </li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="modules">
              <i class="nav-icon bi bi-puzzle"></i>
              <p><?= htmlspecialchars(t('admin.modules'), ENT_QUOTES, 'UTF-8') ?></p>
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
          <div class="col-12">
            <div class="card">
              <div class="card-body">
                <h6 class="card-title d-flex align-items-center gap-2">
                  <?= htmlspecialchars(t('admin.statistics'), ENT_QUOTES, 'UTF-8') ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="refreshStats" title="<?= htmlspecialchars(t('admin.refresh'), ENT_QUOTES, 'UTF-8') ?>">↻</button>
                </h6>
                <div class="row g-2">
                  <div class="col-md-2"><div class="d-flex flex-column"><span class="text-secondary small"><?= htmlspecialchars(t('admin.reports_today'), ENT_QUOTES, 'UTF-8') ?></span><span class="fw-bold fs-5" id="kpiReports1">—</span></div></div>
                  <div class="col-md-2"><div class="d-flex flex-column"><span class="text-secondary small"><?= htmlspecialchars(t('admin.reports_7d'), ENT_QUOTES, 'UTF-8') ?></span><span class="fw-bold fs-5" id="kpiReports7">—</span></div></div>
                  <div class="col-md-2"><div class="d-flex flex-column"><span class="text-secondary small"><?= htmlspecialchars(t('admin.users_7d'), ENT_QUOTES, 'UTF-8') ?></span><span class="fw-bold fs-5" id="kpiUsers7">—</span></div></div>
                  <div class="col-md-3"><div class="d-flex flex-column"><span class="text-secondary small"><?= htmlspecialchars(t('admin.status'), ENT_QUOTES, 'UTF-8') ?></span><span class="small" id="kpiStatus">—</span></div></div>
                  <div class="col-md-3"><div class="d-flex flex-column"><span class="text-secondary small"><?= htmlspecialchars(t('admin.category'), ENT_QUOTES, 'UTF-8') ?></span><span class="small" id="kpiCategory">—</span></div></div>
                </div>
                <div class="row g-3 mt-2">
                  <div class="col-md-6">
                    <h6 class="text-secondary small mb-2"><?= htmlspecialchars(t('admin.status_dist'), ENT_QUOTES, 'UTF-8') ?></h6>
                    <div id="chartStatus" class="admin-chart"></div>
                  </div>
                  <div class="col-md-6">
                    <h6 class="text-secondary small mb-2"><?= htmlspecialchars(t('admin.category_dist'), ENT_QUOTES, 'UTF-8') ?></h6>
                    <div id="chartCategory" class="admin-chart"></div>
                  </div>
                </div>
                <p class="text-secondary small mt-2 mb-0"><?= htmlspecialchars(t('admin.integration_status'), ENT_QUOTES, 'UTF-8') ?> FixMyStreet: <?= fms_enabled() ? htmlspecialchars(t('admin.fms_configured'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(t('admin.fms_not_configured'), ENT_QUOTES, 'UTF-8') ?> | AI (Mistral): <?= ai_configured() ? htmlspecialchars(t('admin.ai_configured'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(t('admin.ai_not_configured'), ENT_QUOTES, 'UTF-8') ?></p>
                <p class="text-secondary small mt-1 mb-0"><strong>CivicAI Analytics:</strong> <a href="<?= htmlspecialchars(app_url('/api/analytics.php?format=json'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">JSON</a> · <a href="<?= htmlspecialchars(app_url('/api/analytics.php?format=csv'), ENT_QUOTES, 'UTF-8') ?>" download>CSV</a> &nbsp;|&nbsp; <strong>ESG:</strong> <a href="<?= htmlspecialchars(app_url('/api/esg_export.php?format=json'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">JSON</a> · <a href="<?= htmlspecialchars(app_url('/api/esg_export.php?format=csv'), ENT_QUOTES, 'UTF-8') ?>" download>CSV</a></p>
              </div>
            </div>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-12">
            <div class="card">
              <div class="card-body">
                <div class="admin-tab-body" id="tab-reports">
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <select id="statusFilter" class="form-select form-select-sm">
                      <option value="new"><?= htmlspecialchars(t('admin.new'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="approved"><?= htmlspecialchars(t('admin.published'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="in_progress"><?= htmlspecialchars(t('admin.in_progress'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="solved"><?= htmlspecialchars(t('admin.solved'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="rejected"><?= htmlspecialchars(t('admin.rejected'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="pending">Pending (régi)</option>
                      <option value="all"><?= htmlspecialchars(t('legend.all'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                    <select id="authorityFilter" class="form-select form-select-sm" title="Hatóság szűrés (multi-city)">
                      <option value=""><?= htmlspecialchars(t('admin.all_authorities'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                    <input id="reportSearch" class="form-control form-control-sm" type="search" placeholder="<?= htmlspecialchars(t('admin.search_placeholder'), ENT_QUOTES, 'UTF-8') ?>">
                    <select id="reportLimit" class="form-select form-select-sm">
                      <option value="200">200</option>
                      <option value="300" selected>300</option>
                      <option value="500">500</option>
                      <option value="1000">1000</option>
                      <option value="2000">2000</option>
                    </select>
                    <div class="d-flex gap-2 ms-auto">
                      <button id="loadReports" class="btn btn-primary btn-sm" type="button"><?= htmlspecialchars(t('admin.load'), ENT_QUOTES, 'UTF-8') ?></button>
                      <button id="refreshReports" class="btn btn-outline-secondary btn-sm" type="button"><?= htmlspecialchars(t('admin.refresh'), ENT_QUOTES, 'UTF-8') ?></button>
                    </div>
                  </div>

                  <div class="counts mb-2" id="counts"></div>
                  <div class="admin-list" id="reportList"><?= htmlspecialchars(t('admin.initial_hint'), ENT_QUOTES, 'UTF-8') ?>: <b><?= htmlspecialchars(t('admin.load'), ENT_QUOTES, 'UTF-8') ?></b>.</div>
                </div>

                <div class="admin-tab-body" id="tab-users" hidden>
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <input id="userSearch" class="form-control form-control-sm" type="search" placeholder="Keresés név/e-mail">
                    <select id="userRoleFilter" class="form-select form-select-sm">
                      <option value="">Minden szerep</option>
                      <option value="user">User</option>
                      <option value="civiluser">Civil</option>
                      <option value="communityuser">Közület</option>
                      <option value="govuser">Közigazgatási</option>
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
                    <select id="layerAuthority" class="form-select form-select-sm" title="Hatóság (opcionális)">
                      <option value="">— Nincs —</option>
                    </select>
                    <select id="layerCategory" class="form-select form-select-sm">
                      <option value="election">Választás</option>
                      <option value="public">Közszolgáltató</option>
                      <option value="tourism">Turisztika</option>
                      <option value="trees">Fák (fakataszter)</option>
                    </select>
                    <span id="layerTreesHint" class="small text-info ms-2" style="display:none">Fakataszter – a térképen a fa réteg láthatóságát kapcsolja.</span>
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

                <div class="admin-tab-body" id="tab-authorities" hidden>
                  <div class="fw-semibold mb-2">Hatóság létrehozása</div>
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <input id="authorityName" class="form-control form-control-sm" placeholder="Név">
                    <input id="authorityCity" class="form-control form-control-sm" placeholder="Város">
                    <input id="authorityAddress" class="form-control form-control-sm" placeholder="Cím (pl. Orosháza, Szabadság u. 1)" style="min-width:220px">
                    <input id="authorityEmail" class="form-control form-control-sm" placeholder="Email">
                    <input id="authorityPhone" class="form-control form-control-sm" placeholder="Telefon">
                    <button id="createAuthority" class="btn btn-primary btn-sm ms-auto" type="button">Mentés</button>
                  </div>
                  <div class="admin-list" id="authorityList">Nincs adat.</div>

                  <div class="fw-semibold mt-4">Szolgáltatás (Open311)</div>
                  <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                    <select id="contactAuthoritySelect" class="form-select form-select-sm"></select>
                    <input id="contactCode" class="form-control form-control-sm" placeholder="service_code (pl. road)">
                    <input id="contactName" class="form-control form-control-sm" placeholder="Megnevezés">
                    <input id="contactDesc" class="form-control form-control-sm" placeholder="Leírás">
                    <button id="createContact" class="btn btn-outline-secondary btn-sm ms-auto" type="button">Mentés</button>
                  </div>
                  <div class="admin-list mt-2" id="contactList">Nincs adat.</div>

                  <div class="fw-semibold mt-4">Hatósági felhasználó</div>
                  <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                    <select id="assignAuthoritySelect" class="form-select form-select-sm"></select>
                    <input id="assignEmail" class="form-control form-control-sm" placeholder="Felhasználó e-mail">
                    <button id="assignUser" class="btn btn-outline-secondary btn-sm ms-auto" type="button">Hozzárendelés</button>
                  </div>
                  <div class="admin-list mt-2" id="assignList">Nincs adat.</div>
                </div>

                <div class="admin-tab-body" id="tab-budget" hidden>
                  <p class="text-secondary small mb-3"><?= htmlspecialchars(t('admin.budget_intro') ?: 'Közös költségvetés: projektek létrehozása, szerkesztése, közzététele. A polgárok a nyilvános oldalon szavazhatnak.'), ENT_QUOTES, 'UTF-8') ?></p>
                  <div class="mb-3">
                    <button type="button" class="btn btn-sm btn-primary" id="btnBudgetAdd"><?= htmlspecialchars(t('admin.budget_add') ?: 'Új projekt'), ENT_QUOTES, 'UTF-8') ?></button>
                  </div>
                  <div id="budgetProjectList"><?= htmlspecialchars(t('admin.load'), ENT_QUOTES, 'UTF-8') ?>...</div>
                </div>
                <div class="admin-tab-body" id="tab-modules" hidden>
                  <p class="text-secondary small mb-3"><?= htmlspecialchars(t('admin.modules_intro'), ENT_QUOTES, 'UTF-8') ?></p>
                  <div id="moduleList"><?= htmlspecialchars(t('admin.load'), ENT_QUOTES, 'UTF-8') ?>...</div>
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
<script src="<?= htmlspecialchars(app_url('/dashboard/dist/js/adminlte.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>window.LANG = <?= json_encode($LANG_JS, JSON_UNESCAPED_UNICODE); ?>;</script>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="admin.js?v=8"></script>
</body>
</html>