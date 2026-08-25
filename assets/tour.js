/**
 * CivicAI bemutató túra – Driver.js (startup pitch minőség).
 * Lang: tour.intro_*, tour.outro_*, tour.step_*, tour.progress, tour.next/prev/done
 */
(function () {
  'use strict';
  var activeDriver = null;

  function t(key, fallback) {
    if (typeof window.LANG !== 'undefined' && window.LANG[key]) return window.LANG[key];
    return fallback || key;
  }

  function getDriverFactory() {
    if (typeof window.driver === 'function') return window.driver;
    if (window.driver && window.driver.js && typeof window.driver.js.driver === 'function') return window.driver.js.driver;
    if (window.Driver && typeof window.Driver === 'function') return window.Driver;
    if (window.Driver && typeof window.Driver.driver === 'function') return window.Driver.driver;
    return null;
  }

  function firstExistingSelector(candidates) {
    for (var i = 0; i < candidates.length; i++) {
      try {
        if (document.querySelector(candidates[i])) return candidates[i];
      } catch (_) {}
    }
    return null;
  }

  function pushStep(steps, candidates, desc, side, align, title) {
    var sel = firstExistingSelector(candidates);
    if (!sel) return;
    var pop = {
      description: desc,
      side: side || 'bottom',
      align: align || 'center'
    };
    if (title) pop.title = title;
    steps.push({ element: sel, popover: pop });
  }

  function activateTabIfNeeded(element) {
    try {
      if (!element || !element.getAttribute) return;
      var tabKey = element.getAttribute('data-tab');
      if (!tabKey) return;
      if (typeof window.govSidebarRevealTab === 'function') {
        window.govSidebarRevealTab(tabKey);
      }
      if (!element.classList.contains('active')) {
        element.click();
      }
    } catch (_) {}
  }

  function getMapSteps() {
    var steps = [];
    pushStep(
      steps,
      ['#btnStartTour', '#mapWrap'],
      t('tour.intro_body_map', 'A túra a közösségi térkép fő funkcióit mutatja.'),
      'bottom',
      'center',
      t('tour.intro_title', 'CivicAI bemutató')
    );
    pushStep(steps, ['#mapWrap'], t('tour.step_map', 'Itt látod a bejelentéseket, ötleteket és fákat a térképen.'), 'bottom', 'center', t('tour.step_map_title', 'Közösségi térkép'));
    pushStep(steps, ['#btnNewReport', '.fab-report-desktop', '.fab-report'], t('tour.step_report', 'Új bejelentés indítása.'), 'left', 'center', t('tour.step_report_title', 'Bejelentés'));
    pushStep(steps, ['#legendMenuBtn', '#legendToggle'], t('tour.step_legend', 'Jelmagyarázat és szűrők.'), 'bottom', 'center', t('tour.step_legend_title', 'Szűrők'));
    pushStep(steps, ['#mapSearchForm', '.topbar-search'], t('tour.step_search', 'Keresés címre.'), 'bottom', 'center', t('tour.step_search_title', 'Keresés'));
    pushStep(steps, ['.topbar-links'], t('tour.step_menu', 'Fő menü.'), 'bottom', 'start', t('tour.step_menu_title', 'Menü'));
    pushStep(
      steps,
      ['#mapWrap', '#btnStartTour'],
      t('tour.outro_map', 'Ennyi a polgári élmény – próbáld ki a bejelentést, vagy nézd meg a közigazgatási dashboardot.'),
      'bottom',
      'center',
      t('tour.outro_title', 'Készen állsz')
    );
    return steps;
  }

  function getGovSteps() {
    var steps = [];
    pushStep(
      steps,
      ['#btnStartTour', '.sidebar-menu', '.app-sidebar'],
      t('tour.intro_body_gov', 'A bal menü szekciókra bontva vezet végig a platformon.'),
      'bottom',
      'center',
      t('tour.intro_title', 'CivicAI bemutató')
    );

    pushStep(
      steps,
      ['#govDashHeroKpis', '[data-tab="dashboard"]', '#tab-dashboard'],
      t('tour.step_gov_hero', 'Színes KPI-k: ügyek, klímaindex, városi egészség – egy pillantásra.'),
      'bottom',
      'start',
      t('tour.step_gov_hero_title', 'Áttekintés')
    );

    // Demo path – frissítve: Rétegek, EU, Copilot+Vision WOW, City Brain
    var govTabSteps = [
      { tab: 'reports', key: 'tour.step_gov_reports', titleKey: 'tour.step_gov_reports_title', fallback: 'Bejelentések kezelése.', titleFb: 'Ügyek' },
      { tab: 'trees', key: 'tour.step_gov_trees', titleKey: 'tour.step_gov_trees_title', fallback: 'Zöld & fák.', titleFb: 'Zöld' },
      { tab: 'map-layers', key: 'tour.step_gov_map_layers', titleKey: 'tour.step_gov_map_layers_title', fallback: 'Rétegek a térképen.', titleFb: 'Rétegek' },
      { tab: 'eu-open-data', key: 'tour.step_gov_eu_open_data', titleKey: 'tour.step_gov_eu_open_data_title', fallback: 'EU nyílt adatok.', titleFb: 'EU adatok' },
      { tab: 'climate', key: 'tour.step_gov_climate', titleKey: 'tour.step_gov_climate_title', fallback: 'Klíma platform.', titleFb: 'Klíma' },
      { tab: 'hu-open-data', key: 'tour.step_gov_hu_open_data', titleKey: 'tour.step_gov_hu_open_data_title', fallback: 'KSH & magyar adat.', titleFb: 'KSH' },
      { tab: 'ai', key: 'tour.step_gov_ai', titleKey: 'tour.step_gov_ai_title', fallback: 'AI Copilot.', titleFb: 'AI' },
      { tab: 'analytics', key: 'tour.step_gov_analytics', titleKey: 'tour.step_gov_analytics_title', fallback: 'Elemzés.', titleFb: 'Elemzés' },
      { tab: 'intel-reports', key: 'tour.step_gov_intel_reports', titleKey: 'tour.step_gov_intel_reports_title', fallback: 'Automatikus jelentések.', titleFb: 'Jelentések' },
      { tab: 'citybrain-copilot', key: 'tour.step_gov_citybrain_copilot', titleKey: 'tour.step_gov_citybrain_copilot_title', fallback: 'Copilot & AI Vision.', titleFb: 'Copilot & Vision' },
      { tab: 'citybrain-live', key: 'tour.step_gov_citybrain_overview', titleKey: 'tour.step_gov_citybrain_title', fallback: 'City Brain – élő intelligencia.', titleFb: 'City Brain' },
      { tab: 'modules', key: 'tour.step_gov_modules', titleKey: 'tour.step_gov_modules_title', fallback: 'Modulok.', titleFb: 'Modulok' }
    ];
    govTabSteps.forEach(function (stepDef) {
      pushStep(
        steps,
        ['[data-tab="' + stepDef.tab + '"]'],
        t(stepDef.key, stepDef.fallback),
        'right',
        'center',
        t(stepDef.titleKey, stepDef.titleFb)
      );
    });

    pushStep(
      steps,
      ['#govDashHeroKpis', '#govCityHealthCard', '#tab-dashboard', '[data-tab="dashboard"]'],
      t('tour.outro_gov', 'A CivicAI egy moduláris civic-tech platform: polgár + önkormányzat + AI + nyílt adatok.'),
      'bottom',
      'start',
      t('tour.outro_title', 'Készen állsz')
    );
    return steps;
  }

  function start() {
    var createDriver = getDriverFactory();
    if (!createDriver) {
      console.warn('CivicAI tour: Driver.js not loaded.');
      return;
    }
    var isGov = document.querySelector('[data-tab="dashboard"]') && window.location.pathname.indexOf('/gov/') !== -1;
    var steps = isGov ? getGovSteps() : getMapSteps();
    if (steps.length === 0) return;
    if (activeDriver && typeof activeDriver.destroy === 'function') {
      try { activeDriver.destroy(); } catch (_) {}
    }
    activeDriver = createDriver({
      showProgress: true,
      progressText: t('tour.progress', '{{current}} / {{total}}'),
      allowClose: true,
      overlayClickBehavior: 'close',
      popoverClass: 'civic-tour-popover',
      steps: steps,
      nextBtnText: t('tour.next', 'Következő'),
      prevBtnText: t('tour.prev', 'Előző'),
      doneBtnText: t('tour.done', 'Kész'),
      showButtons: ['previous', 'next', 'close'],
      onHighlightStarted: function (element) {
        activateTabIfNeeded(element);
      },
      onHighlighted: function (element) {
        try {
          var active = document.querySelector('.nav-link.tab.civic-tour-sidebar-active');
          if (active) active.classList.remove('civic-tour-sidebar-active');
          if (element && element.classList && element.classList.contains('tab')) {
            element.classList.add('civic-tour-sidebar-active');
            activateTabIfNeeded(element);
          }
        } catch (_) {}
      },
      onDestroyed: function () {
        try {
          var active = document.querySelector('.nav-link.tab.civic-tour-sidebar-active');
          if (active) active.classList.remove('civic-tour-sidebar-active');
        } catch (_) {}
        activeDriver = null;
        try { localStorage.setItem('civicai_tour_done', '1'); } catch (_) {}
      }
    });
    if (activeDriver && typeof activeDriver.drive === 'function') {
      activeDriver.drive();
    }
  }

  window.civicaiTour = { start: start };
})();
