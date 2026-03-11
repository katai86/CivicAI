/**
 * CivicAI PWA service worker – cache, offline, push-ready.
 * Scope: same directory and below. Register from mobile/index.php via base.js (relative path).
 */
'use strict';

var CACHE_VERSION = 'civicai-1';
var CACHE_NAME = 'civicai-pwa-' + CACHE_VERSION;

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(function (cache) {
        return cache.addAll(['index.php', './']);
      })
      .then(function () { return self.skipWaiting(); })
      .catch(function () { return self.skipWaiting(); })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) { return k.startsWith('civicai-pwa-') && k !== CACHE_NAME; }).map(function (k) { return caches.delete(k); })
      );
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (event) {
  if (event.request.method !== 'GET') return;
  event.respondWith(
    fetch(event.request).catch(function () {
      return caches.match(event.request);
    })
  );
});

// Push: ready for subscription and push events later
self.addEventListener('push', function (event) {
  var data = event.data ? event.data.json() : {};
  var title = data.title || 'CivicAI';
  var opts = {
    body: data.body || '',
    icon: data.icon || '/Mobilekit_v2-9-1/HTML/assets/img/icon/192x192.png',
    badge: data.badge,
    tag: data.tag,
    data: data
  };
  event.waitUntil(self.registration.showNotification(title, opts));
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var data = event.notification.data || {};
  var url = data.url ? data.url : (self.registration.scope + 'index.php');
  event.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].url.indexOf(self.registration.scope) !== -1) {
        list[i].focus();
        return list[i].navigate(url);
      }
    }
    if (clients.openWindow) return clients.openWindow(url);
  }));
});
