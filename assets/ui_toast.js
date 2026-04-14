/**
 * CivicUI – toast visszajelzés (desktop: saját host; mobil webapp: Mobilekit .toast-box + toastbox()).
 * API: CivicUi.toast({ type, title?, message, timeoutMs? })
 */
(function (global) {
  'use strict';

  var TOAST_ID = 'civicUiToast';

  function escHtml(s) {
    var d = global.document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function useMobilekitToast() {
    return (
      global.document &&
      global.document.body &&
      global.document.body.classList.contains('civicai-mobile') &&
      typeof global.toastbox === 'function'
    );
  }

  function mkBgClass(type) {
    if (type === 'success') return 'bg-success';
    if (type === 'error') return 'bg-danger';
    return 'bg-warning';
  }

  function showMobilekitToast(opts) {
    var old = global.document.getElementById(TOAST_ID);
    if (old) old.remove();

    var title = opts.title ? '<div class="toast-title">' + escHtml(opts.title) + '</div>' : '';
    var body = '<div class="text">' + title + '<div>' + escHtml(opts.message || '') + '</div></div>';
    var div = global.document.createElement('div');
    div.id = TOAST_ID;
    div.className = 'toast-box toast-top tap-to-close ' + mkBgClass(opts.type || 'info');
    div.innerHTML = '<div class="in">' + body + '</div>';
    global.document.body.appendChild(div);

    div.addEventListener('click', function () {
      div.classList.remove('show');
    });

    global.toastbox(TOAST_ID, opts.timeoutMs != null ? opts.timeoutMs : 3500);
  }

  var desktopTimer = null;

  function hideDesktopToast(el) {
    if (!el) return;
    el.classList.remove('civic-ui-toast--show');
    global.setTimeout(function () {
      if (el && el.parentNode) el.parentNode.removeChild(el);
    }, 220);
  }

  function showDesktopToast(opts) {
    if (desktopTimer) {
      global.clearTimeout(desktopTimer);
      desktopTimer = null;
    }
    var prev = global.document.querySelector('.civic-ui-toast-host');
    if (prev) hideDesktopToast(prev);

    var el = global.document.createElement('div');
    el.className =
      'civic-ui-toast-host civic-ui-toast--' +
      (opts.type === 'success' || opts.type === 'error' || opts.type === 'info' ? opts.type : 'info');
    var titleHtml = opts.title
      ? '<div class="civic-ui-toast-title">' + escHtml(opts.title) + '</div>'
      : '';
    el.innerHTML = titleHtml + '<div class="civic-ui-toast-msg">' + escHtml(opts.message || '') + '</div>';
    el.setAttribute('role', 'status');
    global.document.body.appendChild(el);

    global.requestAnimationFrame(function () {
      el.classList.add('civic-ui-toast--show');
    });

    el.addEventListener('click', function () {
      if (desktopTimer) {
        global.clearTimeout(desktopTimer);
        desktopTimer = null;
      }
      hideDesktopToast(el);
    });

    var ms = opts.timeoutMs != null ? opts.timeoutMs : opts.type === 'error' ? 5200 : 3600;
    desktopTimer = global.setTimeout(function () {
      desktopTimer = null;
      hideDesktopToast(el);
    }, ms);
  }

  function toast(opts) {
    if (!global.document || !global.document.body) return;
    opts = opts || {};
    if (!opts.message && opts.title) {
      opts.message = opts.title;
      opts.title = '';
    }
    if (useMobilekitToast()) {
      showMobilekitToast(opts);
    } else {
      showDesktopToast(opts);
    }
  }

  global.CivicUi = global.CivicUi || {};
  global.CivicUi.toast = toast;
})(typeof window !== 'undefined' ? window : globalThis);
