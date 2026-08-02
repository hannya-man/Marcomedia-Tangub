// Minimal service worker — this app is a normal server-rendered Laravel
// app (not an SPA), so this intentionally does NOT cache pages or POS
// data aggressively. Caching dynamic pages (stock counts, sales, etc.)
// would risk showing stale data at a register. It exists mainly so
// Chrome/Android recognizes the app as installable, and to lightly cache
// truly static assets (icons) for a faster repeat load.

const STATIC_CACHE = 'marcomedia-static-v1';
const STATIC_ASSETS = [
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== STATIC_CACHE).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Only intervene for the static assets above — everything else
    // (every page, every POS/inventory/appointments request) goes
    // straight to the network as normal, always fresh.
    if (STATIC_ASSETS.includes(url.pathname)) {
        event.respondWith(
            caches.match(event.request).then((cached) => cached || fetch(event.request))
        );
    }
});
