/* Service Worker Li·La — cache "réseau d'abord" (toujours la dernière version
   quand on est en ligne, cache en secours hors-ligne). Les appels API
   (Google Sheet / Apps Script / Tally) ne sont jamais mis en cache. */
const CACHE = 'lila-v1';
const SHELL = [
  './',
  './index.html',
  './manifest.json',
  './lila-logo-transparent.png',
  './lila-symbol-transparent.png'
];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).catch(() => {}));
  self.skipWaiting();
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  let url;
  try { url = new URL(req.url); } catch (_) { return; }
  // Ne pas intercepter les API dynamiques
  if (/script\.google\.com|googleapis|tally\.so|qrserver\.com/.test(url.hostname)) return;

  e.respondWith(
    fetch(req)
      .then((res) => {
        const copy = res.clone();
        caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {});
        return res;
      })
      .catch(() => caches.match(req).then((r) => r || caches.match('./index.html')))
  );
});
