/**
 * Shared fetch + JSON helpers for public map, admin, and user report UIs.
 * Configure per page via window.CIVIC_API = { loginUrl: '...', base: '/terkep' } (optional).
 */
(function (global) {
  'use strict';

  function getBase() {
    var cfg = global.CIVIC_API || {};
    if (cfg.base) return String(cfg.base).replace(/\/$/, '');
    var b = global.document && global.document.body && global.document.body.dataset && global.document.body.dataset.appBase;
    return String(b || '/terkep').replace(/\/$/, '');
  }

  /** Full URL to send the user when a request returns 401. */
  function getLoginUrl() {
    var cfg = global.CIVIC_API || {};
    if (cfg.loginUrl) return String(cfg.loginUrl);
    var p = cfg.loginPath;
    if (p) {
      p = String(p);
      if (/^https?:\/\//i.test(p)) return p;
      if (p.charAt(0) === '/') return p;
      return getBase() + '/' + p.replace(/^\//, '');
    }
    return getBase() + '/user/login.php';
  }

  function parseErrorMessage(j, fallbackText) {
    if (!j || typeof j !== 'object') return fallbackText || 'Error';
    if (typeof j.message === 'string' && j.message) return j.message;
    var e = j.error;
    if (typeof e === 'string' && e) return e;
    if (e && typeof e === 'object' && typeof e.message === 'string') return e.message;
    if (typeof j.error_message === 'string' && j.error_message) return j.error_message;
    return fallbackText || 'Error';
  }

  function sleep(ms) {
    return new Promise(function (resolve) {
      setTimeout(resolve, ms);
    });
  }

  var RETRY_STATUS = { 502: 1, 503: 1, 504: 1 };

  /**
   * @param {string} url
   * @param {RequestInit} [opts]
   * @param {{ maxRetries?: number, on401?: function(string, object|null): void, skip401Redirect?: boolean }} [clientOpts]
   * @returns {Promise<any>}
   */
  async function fetchJson(url, opts, clientOpts) {
    opts = opts || {};
    clientOpts = clientOpts || {};
    var maxRetries = typeof clientOpts.maxRetries === 'number' ? clientOpts.maxRetries : 2;
    var method = String(opts.method || 'GET').toUpperCase();
    var merged = Object.assign({}, opts, {
      credentials: opts.credentials != null ? opts.credentials : 'same-origin',
      headers: Object.assign({ Accept: 'application/json' }, opts.headers || {}),
    });

    var attempt = 0;
    while (true) {
      var res = await fetch(url, merged);
      var text = await res.text();
      var j = null;
      try {
        j = text ? JSON.parse(text) : null;
      } catch (_) {}

      if (res.status === 401) {
        var dest = getLoginUrl();
        if (!clientOpts.skip401Redirect) {
          if (typeof clientOpts.on401 === 'function') {
            clientOpts.on401(dest, j);
          } else if (global.location && typeof global.location.assign === 'function') {
            global.location.assign(dest);
          }
        }
        var err401 = new Error(parseErrorMessage(j, text));
        err401.status = 401;
        err401.payload = j;
        throw err401;
      }

      if (!res.ok) {
        var msg = parseErrorMessage(j, text);
        var err = new Error('HTTP ' + res.status + ': ' + msg);
        err.status = res.status;
        err.payload = j;
        if (method === 'GET' && RETRY_STATUS[res.status] && attempt < maxRetries) {
          await sleep(400 * Math.pow(2, attempt));
          attempt++;
          continue;
        }
        throw err;
      }

      return j != null ? j : {};
    }
  }

  global.CivicApi = {
    fetchJson: fetchJson,
    parseErrorMessage: parseErrorMessage,
    getBase: getBase,
    getLoginUrl: getLoginUrl,
  };
})(typeof window !== 'undefined' ? window : globalThis);
