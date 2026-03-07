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
    try { localStorage.setItem(THEME_KEY, theme); } catch (_) {}
  }

  function initTheme() {
    setTheme(getTheme());
  }

  function toggleTheme() {
    var next = getTheme() === 'dark' ? 'light' : 'dark';
    setTheme(next);
  }

  function initThemeToggle() {
    var btn = document.getElementById('themeToggle');
    if (!btn) return;
    btn.addEventListener('click', toggleTheme);
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

  initTheme();
  initThemeToggle();
  initLangDropdown();
})();
