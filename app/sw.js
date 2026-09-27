/* Ellipsis offline shell. Bump CACHE on every deploy. */
var CACHE = 'ellipsis-v22';
var CORE = ["support.js","manifest.webmanifest","assets/audio/lofi.wav","assets/audio/afrobeats.wav","assets/audio/neosoul.wav","assets/audio/drill.wav","assets/audio/house.wav","assets/audio/ambient.wav"];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) {
    return Promise.all(CORE.map(function (u) { return c.add(u).catch(function () {}); }));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) {
    return Promise.all(ks.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  if (new URL(req.url).pathname.indexOf('/api/') !== -1) return; // live data is never cached
  if (req.mode === 'navigate' || req.destination === 'document') {
    e.respondWith(fetch(req).catch(function () { return caches.match('index.html'); }));
    return;
  }
  e.respondWith(caches.match(req).then(function (hit) {
    return hit || fetch(req).then(function (res) {
      var copy = res.clone();
      caches.open(CACHE).then(function (c) { c.put(req, copy); }).catch(function () {});
      return res;
    });
  }));
});
