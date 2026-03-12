/**
 * CivicAI PWA install UX – beforeinstallprompt, iOS hint, localStorage, push readiness.
 * Production-ready, no framework dependency.
 */
(function () {
  'use strict';

  const STORAGE_KEY_DISMISSED = 'CivicAI_pwa_dismissed';
  const DISMISS_COOLDOWN_MS = 7 * 24 * 60 * 60 * 1000; // 7 days
  const BANNER_ID = 'civicai-pwa-install-banner';
  const IOS_CARD_ID = 'civicai-pwa-ios-card';

  // ----- Utilities -----

  /** Standalone: app already installed (launched from home screen). */
  function isStandalone() {
    return (
      window.matchMedia('(display-mode: standalone)').matches ||
      window.navigator.standalone === true ||
      document.referrer.includes('android-app://')
    );
  }

  /** iOS Safari (or in-app browser on iOS). */
  function isIOS() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  }

  /** User previously dismissed the install prompt; respect cooldown. */
  function wasDismissed() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY_DISMISSED);
      if (!raw) return false;
      const ts = parseInt(raw, 10);
      if (!Number.isFinite(ts)) return false;
      return Date.now() - ts < DISMISS_COOLDOWN_MS;
    } catch (_) {
      return false;
    }
  }

  function setDismissed() {
    try {
      localStorage.setItem(STORAGE_KEY_DISMISSED, String(Date.now()));
    } catch (_) {}
  }

  /** Whether we should show any install UI (Android/Chrome prompt or iOS hint). */
  function shouldShowInstallUI() {
    if (isStandalone()) return false;
    if (wasDismissed()) return false;
    return true;
  }

  /** Keskeny viewport / mobil: felugró megjelenéshez */
  function isMobileView() {
    return typeof window !== 'undefined' && window.innerWidth <= 768;
  }

  // ----- Install prompt (Android / Chrome / Edge) -----

  let deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    if (shouldShowInstallUI()) showBanner();
  });

  window.addEventListener('appinstalled', function () {
    deferredPrompt = null;
    hideBanner();
    hideIOSCard();
    if (typeof window.CivicAI_PWA !== 'undefined' && typeof window.CivicAI_PWA.onInstalled === 'function') {
      window.CivicAI_PWA.onInstalled();
    }
  });

  // ----- UI: Android/Chrome banner -----

  function getBanner() {
    return document.getElementById(BANNER_ID);
  }

  function showBanner() {
    if (isStandalone() || wasDismissed()) return;
    var el = getBanner();
    if (!el) return;
    el.classList.add('civicai-pwa-banner--visible');
    el.setAttribute('aria-hidden', 'false');
  }

  function hideBanner() {
    var el = getBanner();
    if (el) {
      el.classList.remove('civicai-pwa-banner--visible');
      el.setAttribute('aria-hidden', 'true');
    }
  }

  function onInstallClick() {
    var msgEl = document.getElementById('civicai-pwa-install-msg');
    if (!deferredPrompt) {
      if (msgEl) {
        msgEl.textContent = 'A böngésző jelenleg nem kínálja a telepítést. Chrome: menü (⋮) → Hozzáadás a kezdőképernyőhöz.';
        msgEl.style.display = 'block';
      }
      return;
    }
    var p = deferredPrompt;
    try {
      p.prompt();
    } catch (e) {
      if (msgEl) { msgEl.textContent = 'Telepítés nem elérhető.'; msgEl.style.display = 'block'; }
      deferredPrompt = null;
      return;
    }
    if (p.userChoice && typeof p.userChoice.then === 'function') {
      p.userChoice.then(function (choice) {
        if (choice && choice.outcome === 'accepted') hideBanner();
        deferredPrompt = null;
      }).catch(function () { deferredPrompt = null; });
    } else {
      deferredPrompt = null;
    }
  }

  function onDismissClick() {
    setDismissed();
    hideBanner();
  }

  // ----- UI: iOS card -----

  function getIOSCard() {
    return document.getElementById(IOS_CARD_ID);
  }

  function showIOSCard() {
    if (!shouldShowInstallUI() || !isIOS()) return;
    var el = getIOSCard();
    if (!el) return;
    el.classList.add('civicai-pwa-ios-card--visible');
    el.setAttribute('aria-hidden', 'false');
  }

  function hideIOSCard() {
    var el = getIOSCard();
    if (el) {
      el.classList.remove('civicai-pwa-ios-card--visible');
      el.setAttribute('aria-hidden', 'true');
    }
  }

  function onIOSOkClick() {
    setDismissed();
    hideIOSCard();
  }

  // ----- Bootstrap: create banner and iOS card if not in DOM -----

  function ensureBannerInDOM() {
    var el = getBanner();
    if (el) {
      if (isMobileView()) el.classList.add('civicai-pwa-banner--mobile');
      return;
    }
    var root = document.createElement('div');
    root.id = BANNER_ID;
    root.className = 'civicai-pwa-banner' + (isMobileView() ? ' civicai-pwa-banner--mobile' : '');
    root.setAttribute('aria-hidden', 'true');
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-labelledby', 'civicai-pwa-banner-title');
    root.innerHTML =
      '<div class="civicai-pwa-banner__inner">' +
        '<h2 id="civicai-pwa-banner-title" class="civicai-pwa-banner__title">Használd appként a CivicAI-t</h2>' +
        '<p class="civicai-pwa-banner__text">Tedd ki a CivicAI-t a kezdőképernyődre, hogy gyorsabban bejelenthess problémákat.</p>' +
        '<p id="civicai-pwa-install-msg" class="civicai-pwa-banner__msg" style="display:none;font-size:0.85rem;color:#94a3b8;margin-bottom:10px;"></p>' +
        '<div class="civicai-pwa-banner__actions">' +
          '<button type="button" class="civicai-pwa-banner__btn civicai-pwa-banner__btn--primary" id="civicai-pwa-install-btn">Telepítés</button>' +
          '<button type="button" class="civicai-pwa-banner__btn civicai-pwa-banner__btn--secondary" id="civicai-pwa-dismiss-btn">Most nem</button>' +
        '</div>' +
      '</div>';
    document.body.appendChild(root);

    document.getElementById('civicai-pwa-install-btn').addEventListener('click', onInstallClick);
    document.getElementById('civicai-pwa-dismiss-btn').addEventListener('click', onDismissClick);
  }

  function ensureIOSCardInDOM() {
    if (getIOSCard()) return;
    var root = document.createElement('div');
    root.id = IOS_CARD_ID;
    root.className = 'civicai-pwa-ios-card';
    root.setAttribute('aria-hidden', 'true');
    root.setAttribute('role', 'dialog');
    root.setAttribute('aria-labelledby', 'civicai-pwa-ios-title');
    root.innerHTML =
      '<div class="civicai-pwa-ios-card__inner">' +
        '<h2 id="civicai-pwa-ios-title" class="civicai-pwa-ios-card__title">Tedd ki a CivicAI-t a kezdőképernyődre</h2>' +
        '<p class="civicai-pwa-ios-card__steps">Lépések:<br>1. Nyisd meg a megosztás menüt<br>2. Válaszd a „Hozzáadás a kezdőképernyőhöz” opciót</p>' +
        '<button type="button" class="civicai-pwa-ios-card__btn" id="civicai-pwa-ios-ok">Rendben</button>' +
      '</div>';
    document.body.appendChild(root);

    document.getElementById('civicai-pwa-ios-ok').addEventListener('click', onIOSOkClick);
  }

  function init() {
    if (!shouldShowInstallUI()) return;
    ensureBannerInDOM();
    ensureIOSCardInDOM();

    if (isIOS()) {
      showIOSCard();
    }
    // Android/Chrome: banner is shown when beforeinstallprompt fires (already wired above)

    // Mobil: késleltetett megjelenés (2 s), hogy biztosan látszódjon a prompt
    setTimeout(function () {
      if (!shouldShowInstallUI()) return;
      if (isIOS()) {
        showIOSCard();
      } else if (deferredPrompt) {
        showBanner();
      }
    }, 2000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // ----- Public API (push readiness, analytics, etc.) -----

  window.CivicAI_PWA = {
    isStandalone: isStandalone,
    isIOS: isIOS,
    wasDismissed: wasDismissed,
    shouldShowInstallUI: shouldShowInstallUI,
    showBanner: showBanner,
    hideBanner: hideBanner,
    showIOSCard: showIOSCard,
    hideIOSCard: hideIOSCard,
    getInstallState: function () {
      return {
        standalone: isStandalone(),
        ios: isIOS(),
        dismissed: wasDismissed(),
        hasDeferredPrompt: !!deferredPrompt
      };
    },
    /** Call when you want to request notification permission (e.g. after install). */
    requestNotificationPermission: function () {
      if (!('Notification' in window)) return Promise.resolve('unsupported');
      if (Notification.permission === 'granted') return Promise.resolve('granted');
      if (Notification.permission === 'denied') return Promise.resolve('denied');
      return Notification.requestPermission().then(function (p) { return p; });
    },
    /** Whether push could be used later (SW + permission). */
    isPushReady: function () {
      return 'serviceWorker' in navigator && 'PushManager' in window;
    },
    onInstalled: null
  };
})();
