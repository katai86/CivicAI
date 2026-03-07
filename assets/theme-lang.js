(function(){
  'use strict';

  var THEME_KEY = 'civicai_theme';
  var DEFAULT_THEME = 'dark';

  function getTheme() {
    try {
      var s = localStorage.getItem(THEME_KEY);
      return (s === 'light' || s === 'dark') ? s : DEFAULT_THEME;
    } catch (_) { return DEFAULT_THEME; }
  }

  function setTheme(theme) {
    var root = document.documentElement;
    root.setAttribute('data-theme', theme);
    try { root.setAttribute('data-bs-theme', theme); } catch (_) {}
    try { localStorage.setItem(THEME_KEY, theme); } catch (_) {}
    var btn = document.getElementById('themeToggle');
    if (btn) {
      var title = theme === 'light' ? (btn.dataset.titleDark || 'Dark') : (btn.dataset.titleLight || 'Light');
      btn.setAttribute('title', title);
      btn.setAttribute('aria-label', title);
    }
  }

  function initTheme() {
    setTheme(getTheme());
  }

  function toggleTheme() {
    var next = getTheme() === 'dark' ? 'light' : 'dark';
    setTheme(next);
  }

  function initThemeToggle() {
    document.body.addEventListener('click', function(e) {
      if (!e.target.closest('#themeToggle')) return;
      e.preventDefault();
      e.stopPropagation();
      toggleTheme();
    });
  }

  function initLangDropdown() {
    var toggle = document.getElementById('langToggle');
    var menu = document.getElementById('langMenu');
    var container = toggle && toggle.closest('.lang-dropdown');
    if (!toggle || !menu || !container) return;

    toggle.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      container.classList.toggle('open');
      var expanded = container.classList.contains('open');
      toggle.setAttribute('aria-expanded', expanded);
      menu.setAttribute('aria-hidden', !expanded);
    });

    document.addEventListener('click', function() {
      container.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      menu.setAttribute('aria-hidden', 'true');
    });

    menu.addEventListener('click', function(e) { e.stopPropagation(); });
  }

  function run() {
    initTheme();
    initThemeToggle();
    initLangDropdown();
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', run);
  } else {
    run();
  }
})();
