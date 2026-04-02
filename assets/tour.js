/**
 * CivicAI bemutató túra – Driver.js alapú lépésről lépésre súgó.
 * Használat: töltődjon be a Driver.js (CDN), majd ez a script; a "Bemutató indítása" gomb meghívja a start() függvényt.
 * Lang kulcsok: tour.intro_title, tour.intro_body_*, tour.progress, tour.start, tour.next, tour.prev, tour.done, tour.step_* (lásd docs/INTRO_TOUR.md).
 */
(function () {
  'use strict';
  var activeDriver = null;

  function t(key, fallback) {
    if (typeof window.LANG !== 'undefined' && window.LANG[key]) return window.LANG[key];
    return fallback || key;
  }

  function getDriverFactory() {
    // Driver.js különböző buildjei eltérő globális neveket adhatnak.
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

  function getMapSteps() {
    var steps = [];
    pushStep(
      steps,
      ['#btnStartTour', '#mapWrap'],
      t('tour.intro_body_map', 'A túra a térkép fő funkcióit mutatja be. A Következő gombbal lépsz.'),
      'bottom',
      'center',
      t('tour.intro_title', 'Rövid bemutató')
    );
    pushStep(steps, ['#mapWrap'], t('tour.step_map', 'Itt látod a bejelentéseket, ötleteket és fákat a térképen.'), 'bottom', 'center');
    pushStep(steps, ['#btnNewReport', '.fab-report-desktop', '.fab-report'], t('tour.step_report', 'Új bejelentés: kattints ide, válassz kategóriát, majd add meg a részleteket.'), 'left', 'center');
    pushStep(steps, ['#legendMenuBtn', '#legendToggle'], t('tour.step_legend', 'Jelmagyarázat és szűrők: kategóriák, ötletek, fák, gyors műveletek.'), 'bottom', 'center');
    pushStep(steps, ['#mapSearchForm', '.topbar-search'], t('tour.step_search', 'Keresés címre vagy helyre a térképen.'), 'bottom', 'center');
    pushStep(steps, ['.topbar-links'], t('tour.step_menu', 'Fő menü: GYIK, költségvetés, felmérések, beállítások és egyéb oldalak.'), 'bottom', 'start');
    return steps;
  }

  function getGovSteps() {
    var steps = [];
    pushStep(
      steps,
      ['#btnStartTour', '.sidebar-menu', '.app-sidebar'],
      t('tour.intro_body_gov', 'A túra a bal oldali menüpontokat mutatja be. A Következő gombbal lépsz; egyes elemek csak bekapcsolt modulnál látszanak.'),
      'bottom',
      'center',
      t('tour.intro_title', 'Rövid bemutató')
    );
    var govTabSteps = [
      { tab: 'dashboard', key: 'tour.step_gov_dashboard', fallback: 'Áttekintés: itt látod a városi egészség indexet, időjárást és fő statisztikákat.' },
      { tab: 'reports', key: 'tour.step_gov_reports', fallback: 'Bejelentések kezelése: státuszfrissítés, megjegyzések, követés.' },
      { tab: 'ideas', key: 'tour.step_gov_ideas', fallback: 'Ötletek: közösségi javaslatok és szavazatok áttekintése.' },
      { tab: 'surveys', key: 'tour.step_gov_surveys', fallback: 'Felmérések: kérdőívek létrehozása, kezelése és eredmények megtekintése.' },
      { tab: 'budget', key: 'tour.step_gov_budget', fallback: 'Részvételi költségvetés: projektek, szavazás és beállítások kezelése.' },
      { tab: 'trees', key: 'tour.step_gov_trees', fallback: 'Zöld & fakataszter: hatósághoz kötött fák, térkép és karbantartás.' },
      { tab: 'ai', key: 'tour.step_gov_ai', fallback: 'AI: Copilot és automatikus elemzések a hatóság adatai alapján.' },
      { tab: 'analytics', key: 'tour.step_gov_analytics', fallback: 'Elemzés: hőtérkép, statisztikák és trendek áttekintése.' },
      { tab: 'eu-open-data', key: 'tour.step_gov_eu_open_data', fallback: 'EU nyílt adatok: Copernicus, műhold, levegő, klíma, Eurostat – hatóság szerint.' },
      { tab: 'iot', key: 'tour.step_gov_iot', fallback: 'Szenzorok (IoT): összesítő, térképes nézet, sync és export.' },
      { tab: 'citybrain-live', key: 'tour.step_gov_citybrain_live', fallback: 'Valós idejű áttekintés: állapotkép és gyors mutatók.' },
      { tab: 'citybrain-predictive', key: 'tour.step_gov_citybrain_predictive', fallback: 'Előrejelző elemzés: trendek és várható terhelés.' },
      { tab: 'citybrain-hotspot', key: 'tour.step_gov_citybrain_hotspot', fallback: 'Problémagócok a térképen: sűrűség és helyek.' },
      { tab: 'citybrain-behavior', key: 'tour.step_gov_citybrain_behavior', fallback: 'Viselkedés és trendek: aktivitási minták.' },
      { tab: 'citybrain-environmental', key: 'tour.step_gov_citybrain_environmental', fallback: 'Környezeti elemzés: levegő, hőmérséklet, szenzorok.' },
      { tab: 'citybrain-insights', key: 'tour.step_gov_citybrain_insights', fallback: 'AI-összefoglalók: kiemelt megállapítások.' },
      { tab: 'citybrain-risk', key: 'tour.step_gov_citybrain_risk', fallback: 'Kockázat és riasztások: prioritás szerint.' },
      { tab: 'modules', key: 'tour.step_gov_modules', fallback: 'Modulok: funkciók be- és kikapcsolása jogosultság szerint.' }
    ];
    govTabSteps.forEach(function (stepDef) {
      pushStep(
        steps,
        ['[data-tab="' + stepDef.tab + '"]'],
        t(stepDef.key, stepDef.fallback),
        'right',
        'center'
      );
    });

    // Záró áttekintés a dashboard jelentéséről.
    pushStep(steps, ['#govCityHealthCard', '#tab-dashboard'], t('tour.step_gov_dashboard_explain', 'Zárásként: az Áttekintés menüpontban a Városi egészség index egy összesített mutató, az Időjárás kártya aktuális helyi adatokat ad, a státusz/kategória blokkok pedig a bejelentések eloszlását és trendjeit mutatják.'), 'bottom', 'start');
    return steps;
  }

  function start() {
    var createDriver = getDriverFactory();
    if (!createDriver) {
      console.warn('CivicAI tour: Driver.js not loaded. Include driver.js and driver.css from CDN.');
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
      onHighlighted: function (element) {
        try {
          var active = document.querySelector('.nav-link.tab.civic-tour-sidebar-active');
          if (active) active.classList.remove('civic-tour-sidebar-active');
          if (element && element.classList && element.classList.contains('tab')) {
            element.classList.add('civic-tour-sidebar-active');
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
