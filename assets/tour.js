/**
 * CivicAI bemutató túra – Driver.js alapú lépésről lépésre súgó.
 * Használat: töltődjon be a Driver.js (CDN), majd ez a script; a "Bemutató indítása" gomb meghívja a start() függvényt.
 * Lang kulcsok: tour.start, tour.next, tour.prev, tour.done, tour.step_* (lásd docs/INTRO_TOUR.md).
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

  function pushStep(steps, candidates, desc, side, align) {
    var sel = firstExistingSelector(candidates);
    if (!sel) return;
    steps.push({
      element: sel,
      popover: {
        title: null,
        description: desc,
        side: side || 'bottom',
        align: align || 'center'
      }
    });
  }

  function getMapSteps() {
    var steps = [];
    pushStep(steps, ['#mapWrap'], t('tour.step_map', 'Itt látod a bejelentéseket, ötleteket és fákat a térképen.'), 'bottom', 'center');
    pushStep(steps, ['#btnNewReport', '.fab-report-desktop', '.fab-report'], t('tour.step_report', 'Új bejelentés: kattints ide, válassz kategóriát, majd add meg a részleteket.'), 'left', 'center');
    pushStep(steps, ['#legendMenuBtn', '#legendToggle'], t('tour.step_legend', 'Jelmagyarázat és szűrők: kategóriák, ötletek, fák, gyors műveletek.'), 'bottom', 'center');
    pushStep(steps, ['#mapSearchForm', '.topbar-search'], t('tour.step_search', 'Keresés címre vagy helyre a térképen.'), 'bottom', 'center');
    pushStep(steps, ['.topbar-links'], t('tour.step_menu', 'Fő menü: GYIK, költségvetés, felmérések, beállítások és egyéb oldalak.'), 'bottom', 'start');
    return steps;
  }

  function getGovSteps() {
    var steps = [];
    pushStep(steps, ['[data-tab="dashboard"]'], t('tour.step_gov_dashboard', 'Áttekintés: itt látod a városi egészség indexet, időjárást és fő statisztikákat.'), 'right', 'center');
    pushStep(steps, ['[data-tab="reports"]'], t('tour.step_gov_reports', 'Bejelentések kezelése: státuszfrissítés, megjegyzések, követés.'), 'right', 'center');
    pushStep(steps, ['[data-tab="ideas"]'], t('tour.step_gov_ideas', 'Ötletek: közösségi javaslatok és szavazatok áttekintése.'), 'right', 'center');
    pushStep(steps, ['[data-tab="iot"]'], t('tour.step_gov_iot', 'Szenzorok (IoT): összesítő, térképes nézet, sync és export.'), 'right', 'center');
    pushStep(steps, ['[data-tab="citybrain-live"]'], t('tour.step_gov_citybrain_live', 'City Brain / Live Intelligence: valós idejű állapotkép és gyors mutatók.'), 'right', 'center');
    pushStep(steps, ['[data-tab="citybrain-predictive"]'], t('tour.step_gov_citybrain_predictive', 'Predictive AI: trend alapú előrejelzések és várható terhelések.'), 'right', 'center');
    pushStep(steps, ['[data-tab="citybrain-hotspot"]'], t('tour.step_gov_citybrain_hotspot', 'Hotspot Detection: problémagócok térképes azonosítása.'), 'right', 'center');
    pushStep(steps, ['[data-tab="citybrain-behavior"]'], t('tour.step_gov_citybrain_behavior', 'Behavior & Trends: viselkedési és aktivitási mintázatok.'), 'right', 'center');
    pushStep(steps, ['[data-tab="citybrain-environmental"]'], t('tour.step_gov_citybrain_environmental', 'Environmental AI: levegő, hőmérséklet és környezeti mutatók elemzése.'), 'right', 'center');
    pushStep(steps, ['[data-tab="citybrain-insights"]'], t('tour.step_gov_citybrain_insights', 'AI Insights: automatikus összefoglalók és kiemelt megállapítások.'), 'right', 'center');
    pushStep(steps, ['[data-tab="citybrain-risk"]'], t('tour.step_gov_citybrain_risk', 'Risk & Alerts: kockázatok és riasztások prioritással.'), 'right', 'center');
    pushStep(steps, ['[data-tab="modules"]'], t('tour.step_gov_modules', 'Modulok: funkciók be- és kikapcsolása jogosultság szerint.'), 'right', 'center');

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
      allowClose: true,
      overlayClickBehavior: 'close',
      popoverClass: 'civic-tour-popover',
      steps: steps,
      nextBtnText: t('tour.next', 'Következő'),
      prevBtnText: t('tour.prev', 'Előző'),
      doneBtnText: t('tour.done', 'Kész'),
      showButtons: ['previous', 'next', 'close'],
      onDestroyed: function () {
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
