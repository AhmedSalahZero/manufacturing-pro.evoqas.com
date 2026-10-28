// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Service worker (the offline helper)
//  Location: resources/js/pwa/sw.js
//  Built by `npm run build` into public/build/sw.js, served at /sw.js.
//
//  WHAT IT KEEPS ON THE DEVICE
//    1. The app's files (JavaScript, styles, fonts, icons): the same for everybody,
//       listed automatically at build time.
//    2. A small "you are offline" page (/offline.html).
//    3. Public pictures (logo, icons, sign-in picture).
//
//  WHAT IT NEVER KEEPS: on purpose
//    · signed-in pages. They carry a person's financial plans inside the page, and a
//      stored copy could be shown to the wrong person (this happened on an earlier
//      project, see App\Http\Middleware\NoStoreForAuthenticated). They always come
//      fresh from the server.
//    · anything the server answers to a form (sign-in, saving).
//
//  When a page cannot be reached (no signal) the person sees the offline page instead
//  of the browser's error. Working on plans without any connection is a later step:
//  it will keep each person's own plans in a private database on the device.
//
//  UPDATES: a new version waits until the person taps "Update now" (register.js).
// ══════════════════════════════════════════════════════════════════
import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';
import { CacheFirst } from 'workbox-strategies';
import { ExpirationPlugin } from 'workbox-expiration';
import { clientsClaim } from 'workbox-core';

precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();
clientsClaim();

// Every page: ask the server. Only when the device is offline, show the offline page.
registerRoute(new NavigationRoute(async ({ request }) => {
    try {
        return await fetch(request);
    } catch {
        return (await matchPrecache('/offline.html')) ?? Response.error();
    }
}));

// Public images (logo, icons, sign-in picture): the same for everyone.
registerRoute(
    ({ url, request }) => url.origin === self.location.origin && request.destination === 'image' && url.pathname.startsWith('/images/'),
    new CacheFirst({ cacheName: 'zavero-images', plugins: [new ExpirationPlugin({ maxEntries: 40, maxAgeSeconds: 60 * 60 * 24 * 30 })] }),
);

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});
