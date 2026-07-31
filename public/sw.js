const CACHE_NAME = 'bionotifier-pwa-v2';
const OFFLINE_URL = '/offline.html';

const FILES_TO_CACHE = [
  OFFLINE_URL,
  '/icon-v2.svg',
  '/icon-192-v3.png',
  '/icon-512-v3.png',
  '/manifest.json'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(FILES_TO_CACHE);
    }).then(() => {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', (event) => {
  if (event.request.mode === 'navigate') {
    event.respondWith(
      fetch(event.request)
        .catch(() => {
          return caches.open(CACHE_NAME).then((cache) => {
            return cache.match(OFFLINE_URL);
          });
        })
    );
  } else {
    // For other requests, try network first, then cache
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          // You could optionally cache dynamically here
          return response;
        })
        .catch(() => {
          return caches.match(event.request);
        })
    );
  }
});
