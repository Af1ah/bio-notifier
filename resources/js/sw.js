import { precacheAndRoute, cleanupOutdatedCaches } from 'workbox-precaching';
import { registerRoute, NavigationRoute } from 'workbox-routing';
import { CacheFirst, NetworkFirst, StaleWhileRevalidate } from 'workbox-strategies';
import { ExpirationPlugin } from 'workbox-expiration';
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { setCatchHandler } from 'workbox-routing';

// Precache the assets injected by vite-plugin-pwa
precacheAndRoute(self.__WB_MANIFEST || []);

// Clean up old caches
cleanupOutdatedCaches();

// Cache static assets (Filament CSS/JS, Fonts, Icons)
registerRoute(
  ({ request }) => request.destination === 'style' || request.destination === 'script' || request.destination === 'worker',
  new StaleWhileRevalidate({
    cacheName: 'static-resources',
    plugins: [
      new CacheableResponsePlugin({
        statuses: [0, 200],
      }),
    ],
  })
);

// Cache images and icons
registerRoute(
  ({ request }) => request.destination === 'image',
  new CacheFirst({
    cacheName: 'images',
    plugins: [
      new ExpirationPlugin({
        maxEntries: 50,
        maxAgeSeconds: 30 * 24 * 60 * 60, // 30 Days
      }),
    ],
  })
);

// HTML / Navigation requests (Network First, fallback to offline)
// We match standard navigations, text/html requests, explicit Livewire headers, and any GET to our origin without a file extension (to catch Livewire prefetchHtml)
registerRoute(
  ({ request, url }) => {
    if (request.mode === 'navigate') return true;
    if (request.method === 'GET' && url.origin === self.location.origin) {
        if (request.headers.get('accept')?.includes('text/html')) return true;
        if (request.headers.has('x-livewire') || request.headers.has('x-livewire-navigate')) return true;
        if (!url.pathname.match(/\.[a-zA-Z0-9]+$/)) return true;
    }
    return false;
  },
  new NetworkFirst({
    cacheName: 'pages',
    plugins: [
      new CacheableResponsePlugin({
        statuses: [200],
      }),
    ],
  })
);

// Fallback handler when both network and cache fail
setCatchHandler(async ({ request, url }) => {
  let isPageRequest = request.mode === 'navigate' || 
                      request.headers.get('accept')?.includes('text/html') || 
                      request.headers.has('x-livewire') || 
                      request.headers.has('x-livewire-navigate') || 
                      (request.method === 'GET' && url.origin === self.location.origin && !url.pathname.match(/\.[a-zA-Z0-9]+$/));
  
  if (isPageRequest) {
    return new Response(
      `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - BIO-Notifier</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; margin: 0; background-color: #f3f4f6; color: #1f2937; text-align: center; padding: 1rem; }
        .icon { width: 64px; height: 64px; color: #9ca3af; margin-bottom: 1rem; }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        p { color: #4b5563; margin-bottom: 1.5rem; }
        button { background-color: #f59e0b; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 0.5rem; font-weight: 600; cursor: pointer; transition: background-color 0.2s; }
        button:hover { background-color: #d97706; }
        @media (prefers-color-scheme: dark) {
            body { background-color: #111827; color: #f9fafb; }
            .icon { color: #4b5563; }
            p { color: #9ca3af; }
        }
    </style>
</head>
<body>
    <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728m0 0l-2.829-2.829m2.829 2.829L21 21M15.536 8.485a5 5 0 010 7.072m0 0l-2.829-2.829m-4.243 2.829a4.978 4.978 0 01-1.414-2.83m-1.414 5.658a9 9 0 01-2.167-9.238m7.824 2.167a1 1 0 111.414 1.414m-1.414-1.414L3 3m8.293 8.293l1.414 1.414"></path></svg>
    <h1>You are currently offline</h1>
    <p>Please check your internet connection and try again.</p>
    <button onclick="window.location.reload()">Try Again</button>
</body>
</html>`,
      { headers: { 'Content-Type': 'text/html' } }
    );
  }
  return Response.error();
});

// Example for caching specific API requests with IndexedDB integration later
// registerRoute(
//   ({ url }) => url.pathname.startsWith('/api/dashboard'),
//   new NetworkFirst({
//     cacheName: 'api-responses',
//   })
// );

self.addEventListener('install', (event) => {
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});
