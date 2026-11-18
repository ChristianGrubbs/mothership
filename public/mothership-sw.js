/* The Mothership keeps application pages, files, API responses and credentials on the network.
 * Only the public connection-help document is cached. No successful navigation enters Cache Storage.
 * Change VERSION whenever this worker or the help document changes. Existing windows keep their worker
 * until closed; no forced reload can discard an edit. All application code still comes from the network.
 */
const VERSION = 'mothership-offline-v1';
const OFFLINE = new URL('pwa/offline', self.registration.scope).href;

self.addEventListener('install', event => {
    event.waitUntil((async () => {
        const response = await fetch(OFFLINE, { credentials: 'omit', cache: 'reload' });
        if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('text/html')) {
            throw new Error('Offline help is unavailable');
        }
        const cache = await caches.open(VERSION);
        await cache.put(OFFLINE, response);
    })());
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys.filter(key => key.startsWith('mothership-offline-') && key !== VERSION).map(key => caches.delete(key)));
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET' || event.request.mode !== 'navigate'
        || new URL(event.request.url).origin !== self.location.origin) return;

    event.respondWith((async () => {
        try {
            // Revalidate login and access for every navigation, including after logout or a user switch.
            return await fetch(event.request, { cache: 'no-store' });
        } catch (_) {
            return (await caches.match(OFFLINE)) || new Response('The Mothership requires a network connection.', {
                status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' },
            });
        }
    })());
});
