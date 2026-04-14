<?php
require_once __DIR__ . '/../util.php';
require_admin();
start_secure_session();
if (!empty($_GET['lang']) && in_array($_GET['lang'], LANG_ALLOWED, true)) {
  set_lang($_GET['lang']);
  header('Location: ' . (isset($_SERVER['REQUEST_URI']) ? strtok($_SERVER['REQUEST_URI'], '?') : app_url('/admin/index.php')));
  exit;
}
$currentLang = current_lang();
$LANG_JS = lang_array_for_js();
$adminUid = current_user_id() ? (int)current_user_id() : 0;
$geocodeClientUi = civic_geocode_client_config($adminUid);
?>
<!doctype html>
<html lang="<?= htmlspecialchars($currentLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars(app_url('/assets/fav_icon.png'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="apple-touch-icon" href="<?= htmlspecialchars(app_url('/assets/fav_icon.png'), ENT_QUOTES, 'UTF-8') ?>">
  <title><?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars(t('admin.page_title'), ENT_QUOTES, 'UTF-8') ?></title>
  <script>try{var t=localStorage.getItem('civicai_theme');t=(t==='light'||t==='dark')?t:'dark';document.documentElement.setAttribute('data-theme',t);document.documentElement.setAttribute('data-bs-theme',t);}catch(_){document.documentElement.setAttribute('data-theme','dark');document.documentElement.setAttribute('data-bs-theme','dark');}</script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/dashboard/dist/css/adminlte.min.css'), ENT_QUOTES, 'UTF-8') ?>">
  <link rel="stylesheet" href="<?= htmlspecialchars(app_url('/assets/admin.css'), ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="layout-fixed sidebar-expand-lg bg-body-tertiary" data-app-base="<?= htmlspecialchars(APP_BASE, ENT_QUOTES, 'UTF-8') ?>" data-map-lat="<?= htmlspecialchars((string)(defined('MAP_CENTER_LAT') ? MAP_CENTER_LAT : 46.565), ENT_QUOTES, 'UTF-8') ?>" data-map-lng="<?= htmlspecialchars((string)(defined('MAP_CENTER_LNG') ? MAP_CENTER_LNG : 20.667), ENT_QUOTES, 'UTF-8') ?>" data-map-zoom="<?= htmlspecialchars((string)(defined('MAP_ZOOM') ? MAP_ZOOM : 13), ENT_QUOTES, 'UTF-8') ?>">
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
          <span class="nav-link fw-semibold d-flex align-items-center gap-2">
          <img src="<?= htmlspecialchars(app_url('/assets/logo_dark.png'), ENT_QUOTES, 'UTF-8') ?>" alt="" class="civic-brand-img civic-brand-img--dark" style="height:1.5rem;width:auto;max-width:100px;object-fit:contain">
          <img src="<?= htmlspecialchars(app_url('/assets/logo_light.png'), ENT_QUOTES, 'UTF-8') ?>" alt="" class="civic-brand-img civic-brand-img--light" style="height:1.5rem;width:auto;max-width:100px;object-fit:contain">
          <span><?= htmlspecialchars(t('admin.page_title'), ENT_QUOTES, 'UTF-8') ?></span>
        </span>
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
      <a href="<?= htmlspecialchars(app_url('/'), ENT_QUOTES, 'UTF-8') ?>" class="brand-link d-flex align-items-center">
        <img src="<?= htmlspecialchars(app_url('/assets/logo_dark.png'), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?>" class="civic-brand-img civic-brand-img--dark" style="height:2rem;width:auto;max-width:120px;object-fit:contain">
        <img src="<?= htmlspecialchars(app_url('/assets/logo_light.png'), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars(t('site.name'), ENT_QUOTES, 'UTF-8') ?>" class="civic-brand-img civic-brand-img--light" style="height:2rem;width:auto;max-width:120px;object-fit:contain">
      </a>
    </div>
    <div class="sidebar-wrapper">
      <nav class="mt-2">
        <ul class="nav sidebar-menu flex-column">
          <li class="nav-item">
            <a href="#" class="nav-link tab active" data-tab="overview">
              <i class="nav-icon bi bi-house-door-fill"></i>
              <p><?= htmlspecialchars(t('admin.tab_overview'), ENT_QUOTES, 'UTF-8') ?></p>
            </a>
          </li>
          <li class="nav-header mt-3 mb-1 px-3 small text-uppercase text-muted sidebar-section-header" role="button" tabindex="0"><span><?= htmlspecialchars(t('admin.nav_section_manage'), ENT_QUOTES, 'UTF-8') ?></span><i class="bi bi-chevron-down nav-section-chevron"></i></li>
          <li class="nav-item">
            <a href="#" class="nav-link tab" data-tab="reports">
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
          <li class="nav-header mt-3 mb-1 px-3 small text-uppercase text-muted sidebar-section-header" role="button" tabindex="0"><span><?= htmlspecialchars(t('admin.nav_section_system'), ENT_QUOTES, 'UTF-8') ?></span><i class="bi bi-chevron-down nav-section-chevron"></i></li>
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
            <a href="#" class="nav-link tab" data-tab="modules">
              <i class="nav-icon bi bi-gear"></i>
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
        <div id="tab-overview" class="admin-tab-body">
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
                  <?php $fmsOk = false; $aiOk = false; try { $fmsOk = fms_enabled(); } catch (Throwable $e) { /* module_settings hiányzik esetén */ } try { $aiOk = ai_configured(); } catch (Throwable $e) { /* module_settings hiányzik esetén */ } ?>
                  <p class="text-secondary small mt-2 mb-0"><?= htmlspecialchars(t('admin.integration_status'), ENT_QUOTES, 'UTF-8') ?> FixMyStreet: <?= $fmsOk ? htmlspecialchars(t('admin.fms_configured'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(t('admin.fms_not_configured'), ENT_QUOTES, 'UTF-8') ?> | AI (Mistral): <?= $aiOk ? htmlspecialchars(t('admin.ai_configured'), ENT_QUOTES, 'UTF-8') : htmlspecialchars(t('admin.ai_not_configured'), ENT_QUOTES, 'UTF-8') ?></p>
                  <p class="text-secondary small mt-1 mb-0"><strong><?= htmlspecialchars(t('admin.analytics_label'), ENT_QUOTES, 'UTF-8') ?>:</strong> <a href="<?= htmlspecialchars(app_url('/api/analytics.php?format=json'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">JSON</a> · <a href="<?= htmlspecialchars(app_url('/api/analytics.php?format=csv'), ENT_QUOTES, 'UTF-8') ?>" download>CSV</a> &nbsp;|&nbsp; <strong><?= htmlspecialchars(t('admin.esg_label'), ENT_QUOTES, 'UTF-8') ?>:</strong> <a href="<?= htmlspecialchars(app_url('/api/esg_export.php?format=json'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">JSON</a> · <a href="<?= htmlspecialchars(app_url('/api/esg_export.php?format=csv'), ENT_QUOTES, 'UTF-8') ?>" download>CSV</a></p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div id="admin-tab-panel" class="admin-tab-body" hidden>
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
                      <option value="pending"><?= htmlspecialchars(t('admin.pending_legacy'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="all"><?= htmlspecialchars(t('legend.all'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                    <select id="authorityFilter" class="form-select form-select-sm" title="<?= htmlspecialchars(t('admin.authority_filter_title'), ENT_QUOTES, 'UTF-8') ?>">
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

                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <?php if (!empty($geocodeClientUi['show_selector']) && !empty($geocodeClientUi['providers'])): ?>
                    <select id="adminMapSearchProvider" class="form-select form-select-sm" style="max-width:220px" aria-label="<?= htmlspecialchars(t('search.provider_aria'), ENT_QUOTES, 'UTF-8') ?>">
                      <?php foreach ($geocodeClientUi['providers'] as $p): ?>
                      <option value="<?= htmlspecialchars((string)($p['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"<?= (($p['id'] ?? '') === ($geocodeClientUi['default'] ?? '')) ? ' selected' : '' ?>><?= htmlspecialchars((string)($p['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <input type="search" id="adminMapSearchInput" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.map_search_placeholder'), ENT_QUOTES, 'UTF-8') ?>" style="max-width:280px">
                    <button type="button" id="adminMapSearchGo" class="btn btn-sm btn-outline-primary"><?= htmlspecialchars(t('admin.map_search_go'), ENT_QUOTES, 'UTF-8') ?></button>
                  </div>
                  <div id="map" class="mb-3 rounded border" style="height:380px;width:100%;min-height:200px"></div>

                  <div class="counts mb-2" id="counts"></div>
                  <div class="admin-list" id="reportList"><?= htmlspecialchars(t('admin.initial_hint'), ENT_QUOTES, 'UTF-8') ?>: <b><?= htmlspecialchars(t('admin.load'), ENT_QUOTES, 'UTF-8') ?></b>.</div>
                </div>

                <div class="admin-tab-body" id="tab-users" hidden>
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <input id="userSearch" class="form-control form-control-sm" type="search" placeholder="<?= htmlspecialchars(t('admin.user_search_placeholder'), ENT_QUOTES, 'UTF-8') ?>">
                    <select id="userRoleFilter" class="form-select form-select-sm">
                      <option value=""><?= htmlspecialchars(t('admin.role_all'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="user"><?= htmlspecialchars(t('admin.role_user'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="civiluser"><?= htmlspecialchars(t('admin.role_civiluser'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="communityuser"><?= htmlspecialchars(t('admin.role_communityuser'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="govuser"><?= htmlspecialchars(t('admin.role_govuser'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="admin"><?= htmlspecialchars(t('admin.role_admin'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="superadmin"><?= htmlspecialchars(t('admin.role_superadmin'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                    <select id="userActiveFilter" class="form-select form-select-sm">
                      <option value=""><?= htmlspecialchars(t('admin.active_all'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="1"><?= htmlspecialchars(t('admin.active_yes'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="0"><?= htmlspecialchars(t('admin.active_no'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                    <button id="refreshUsers" class="btn btn-outline-secondary btn-sm ms-auto" type="button"><?= htmlspecialchars(t('admin.refresh'), ENT_QUOTES, 'UTF-8') ?></button>
                  </div>
                  <div class="admin-list" id="userList"><?= htmlspecialchars(t('admin.user_list_empty'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>

                <div class="admin-tab-body" id="tab-layers" hidden>
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <input id="layerKey" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.layer_key_placeholder'), ENT_QUOTES, 'UTF-8') ?>">
                    <input id="layerName" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.layer_name_placeholder'), ENT_QUOTES, 'UTF-8') ?>">
                    <select id="layerAuthority" class="form-select form-select-sm" title="<?= htmlspecialchars(t('admin.layer_authority_title'), ENT_QUOTES, 'UTF-8') ?>">
                      <option value=""><?= htmlspecialchars(t('admin.layer_authority_none'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                    <select id="layerCategory" class="form-select form-select-sm">
                      <option value="election"><?= htmlspecialchars(t('admin.layer_category_election'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="public"><?= htmlspecialchars(t('admin.layer_category_public'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="tourism"><?= htmlspecialchars(t('admin.layer_category_tourism'), ENT_QUOTES, 'UTF-8') ?></option>
                      <option value="trees"><?= htmlspecialchars(t('admin.layer_category_trees'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                    <span id="layerTreesHint" class="small text-info ms-2" style="display:none"><?= htmlspecialchars(t('admin.layer_trees_hint'), ENT_QUOTES, 'UTF-8') ?></span>
                    <label class="form-check form-check-inline">
                      <input type="checkbox" class="form-check-input" id="layerActive" checked>
                      <span class="form-check-label"><?= htmlspecialchars(t('admin.layer_active'), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <label class="form-check form-check-inline">
                      <input type="checkbox" class="form-check-input" id="layerTemporary">
                      <span class="form-check-label"><?= htmlspecialchars(t('admin.layer_temporary'), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <input id="layerFrom" class="form-control form-control-sm" type="date" placeholder="<?= htmlspecialchars(t('admin.layer_from_placeholder'), ENT_QUOTES, 'UTF-8') ?>">
                    <input id="layerTo" class="form-control form-control-sm" type="date" placeholder="<?= htmlspecialchars(t('admin.layer_to_placeholder'), ENT_QUOTES, 'UTF-8') ?>">
                    <button id="createLayer" class="btn btn-primary btn-sm ms-auto" type="button"><?= htmlspecialchars(t('admin.layer_create'), ENT_QUOTES, 'UTF-8') ?></button>
                  </div>

                  <div class="admin-list" id="layerList"><?= htmlspecialchars(t('admin.user_list_empty'), ENT_QUOTES, 'UTF-8') ?></div>

                  <div class="fw-semibold mt-3"><?= htmlspecialchars(t('admin.point_add_title'), ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                    <select id="pointLayerSelect" class="form-select form-select-sm"></select>
                    <input id="pointName" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.point_name'), ENT_QUOTES, 'UTF-8') ?>">
                    <input id="pointLat" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.point_lat'), ENT_QUOTES, 'UTF-8') ?>">
                    <input id="pointLng" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.point_lng'), ENT_QUOTES, 'UTF-8') ?>">
                    <input id="pointAddress" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.point_address'), ENT_QUOTES, 'UTF-8') ?>">
                    <input id="pointMeta" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.point_meta'), ENT_QUOTES, 'UTF-8') ?>">
                    <button id="createPoint" class="btn btn-outline-secondary btn-sm ms-auto" type="button"><?= htmlspecialchars(t('admin.point_save'), ENT_QUOTES, 'UTF-8') ?></button>
                  </div>
                  <div class="admin-list mt-2" id="pointList"><?= htmlspecialchars(t('admin.point_list_hint'), ENT_QUOTES, 'UTF-8') ?></div>
                </div>

                <div class="admin-tab-body" id="tab-authorities" hidden>
                  <div class="fw-semibold mb-2"><?= htmlspecialchars(t('admin.authority_create_title'), ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
                    <input id="authorityName" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.authority_name'), ENT_QUOTES, 'UTF-8') ?>">
                    <input id="authorityCity" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.authority_city'), ENT_QUOTES, 'UTF-8') ?>">
                    <input id="authorityCountry" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.authority_country'), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(t('admin.authority_country_hint'), ENT_QUOTES, 'UTF-8') ?>" style="width:120px">
                    <input id="authorityAddress" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.authority_address'), ENT_QUOTES, 'UTF-8') ?>" style="min-width:220px">
                    <input id="authorityEmail" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.authority_email'), ENT_QUOTES, 'UTF-8') ?>">
                    <input id="authorityPhone" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.authority_phone'), ENT_QUOTES, 'UTF-8') ?>">
                  </div>
                  <div class="small text-secondary mb-1"><?= htmlspecialchars(t('admin.authority_bounds_hint'), ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                    <input id="authorityMinLat" class="form-control form-control-sm" type="number" step="any" placeholder="min_lat (pl. 47.4)" style="width:100px">
                    <input id="authorityMaxLat" class="form-control form-control-sm" type="number" step="any" placeholder="max_lat (pl. 47.6)" style="width:100px">
                    <input id="authorityMinLng" class="form-control form-control-sm" type="number" step="any" placeholder="min_lng (pl. 18.9)" style="width:100px">
                    <input id="authorityMaxLng" class="form-control form-control-sm" type="number" step="any" placeholder="max_lng (pl. 19.2)" style="width:100px">
                    <button id="createAuthority" class="btn btn-primary btn-sm ms-auto" type="button"><?= htmlspecialchars(t('admin.authority_save'), ENT_QUOTES, 'UTF-8') ?></button>
                  </div>
                  <div class="admin-list" id="authorityList"><?= htmlspecialchars(t('admin.user_list_empty'), ENT_QUOTES, 'UTF-8') ?></div>

                  <div class="fw-semibold mt-4"><?= htmlspecialchars(t('admin.contact_title'), ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                    <select id="contactAuthoritySelect" class="form-select form-select-sm"></select>
                    <input id="contactCode" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.contact_code'), ENT_QUOTES, 'UTF-8') ?>">
                    <input id="contactName" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.contact_name'), ENT_QUOTES, 'UTF-8') ?>">
                    <input id="contactDesc" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.contact_desc'), ENT_QUOTES, 'UTF-8') ?>">
                    <button id="createContact" class="btn btn-outline-secondary btn-sm ms-auto" type="button"><?= htmlspecialchars(t('admin.authority_save'), ENT_QUOTES, 'UTF-8') ?></button>
                  </div>
                  <div class="admin-list mt-2" id="contactList"><?= htmlspecialchars(t('admin.user_list_empty'), ENT_QUOTES, 'UTF-8') ?></div>

                  <div class="fw-semibold mt-4"><?= htmlspecialchars(t('admin.assign_title'), ENT_QUOTES, 'UTF-8') ?></div>
                  <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
                    <select id="assignAuthoritySelect" class="form-select form-select-sm"></select>
                    <input id="assignEmail" class="form-control form-control-sm" placeholder="<?= htmlspecialchars(t('admin.assign_email'), ENT_QUOTES, 'UTF-8') ?>">
                    <button id="assignUser" class="btn btn-outline-secondary btn-sm ms-auto" type="button"><?= htmlspecialchars(t('admin.assign_btn'), ENT_QUOTES, 'UTF-8') ?></button>
                  </div>
                  <div class="admin-list mt-2" id="assignList"><?= htmlspecialchars(t('admin.user_list_empty'), ENT_QUOTES, 'UTF-8') ?></div>
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
    </div>
  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
<script src="<?= htmlspecialchars(app_url('/dashboard/dist/js/adminlte.min.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script>window.LANG = <?= json_encode($LANG_JS, JSON_UNESCAPED_UNICODE); ?>;</script>
<script>window.CIVIC_GEOCODE = <?= json_encode($geocodeClientUi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
<script>window.CIVIC_API = <?= json_encode(['loginUrl' => app_url('/admin/login.php')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="<?= htmlspecialchars(app_url('/assets/theme-lang.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="<?= htmlspecialchars(app_url('/assets/api_client.js'), ENT_QUOTES, 'UTF-8') ?>?v=1"></script>
<script src="admin.js?v=10"></script>
</body>
</html>
