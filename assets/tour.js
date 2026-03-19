/**
 * CivicAI bemutató túra – Driver.js alapú lépésről lépésre súgó.
 * Használat: töltődjon be a Driver.js (CDN), majd ez a script; a "Bemutató indítása" gomb meghívja a start() függvényt.
 * Lang kulcsok: tour.start, tour.next, tour.prev, tour.done, tour.step_* (lásd docs/INTRO_TOUR.md).
 */
(function () {
  'use strict';
  var activeDriver = null;

  function t(key) {
    return (typeof window.LANG !== 'undefined' && window.LANG[key]) ? window.LANG[key] : key;
  }

  function getMapSteps() {
    return [
      { element: '#mapWrap', popover: { title: null, description: t('tour.step_map'), side: 'bottom', align: 'center' } },
      { element: '#btnNewReport, .fab-report-desktop', popover: { title: null, description: t('tour.step_report'), side: 'left', align: 'center' } },
      { element: '#legendMenuBtn', popover: { title: null, description: t('tour.step_legend'), side: 'bottom', align: 'center' } },
      { element: '#mapSearchForm, .topbar-search', popover: { title: null, description: t('tour.step_search'), side: 'bottom', align: 'center' } },
      { element: '.topbar-links', popover: { title: null, description: t('tour.step_menu'), side: 'bottom', align: 'start' } }
    ].filter(function (s) {
      try { return document.querySelector(s.element.split(',')[0].trim()); } catch (_) { return false; }
    });
  }

  function getGovSteps() {
    var selectors = [
      '[data-tab="dashboard"]',
      '[data-tab="reports"]',
      '[data-tab="ideas"]',
      '[data-tab="iot"]',
      '[data-tab="citybrain-live"]',
      '[data-tab="modules"]'
    ];
    var keys = ['tour.step_gov_dashboard', 'tour.step_gov_reports', 'tour.step_gov_ideas', 'tour.step_gov_iot', 'tour.step_gov_citybrain', 'tour.step_gov_modules'];
    var steps = [];
    for (var i = 0; i < selectors.length; i++) {
      try {
        if (document.querySelector(selectors[i])) {
          steps.push({
            element: selectors[i],
            popover: { title: null, description: t(keys[i]), side: 'right', align: 'center' }
          });
        }
      } catch (_) {}
    }
    return steps;
  }

  function start() {
    if (typeof window.driver === 'undefined') {
      console.warn('CivicAI tour: Driver.js not loaded. Include driver.js and driver.css from CDN.');
      return;
    }
    var isGov = document.querySelector('[data-tab="dashboard"]') && window.location.pathname.indexOf('/gov/') !== -1;
    var steps = isGov ? getGovSteps() : getMapSteps();
    if (steps.length === 0) return;
    if (activeDriver && typeof activeDriver.destroy === 'function') {
      try { activeDriver.destroy(); } catch (_) {}
    }
    activeDriver = window.driver({
      showProgress: true,
      steps: steps,
      nextBtnText: t('tour.next'),
      prevBtnText: t('tour.prev'),
      doneBtnText: t('tour.done'),
      onDestroyStarted: function () {
        try { localStorage.setItem('civicai_tour_done', '1'); } catch (_) {}
      }
    });
    if (activeDriver && typeof activeDriver.drive === 'function') {
      activeDriver.drive();
    }
  }

  window.civicaiTour = { start: start };
})();
