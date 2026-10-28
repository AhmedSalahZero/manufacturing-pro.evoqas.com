// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Registers the offline helper (service worker)
//  Location: resources/js/pwa/register.js
//
//  Only in the built app (not while `npm run dev`). A new version waits until the
//  person taps "Update now", so nothing changes in the middle of their work.
// ══════════════════════════════════════════════════════════════════
import { ref } from 'vue';
import { Workbox } from 'workbox-window';

export const updateReady = ref(false);

let wb = null;

export function registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD) return;

    wb = new Workbox('/sw.js', { scope: '/' });
    wb.addEventListener('waiting', () => (updateReady.value = true));
    wb.register().catch((error) => console.warn('ZAVERO: the offline helper could not start.', error));
}

export function applyUpdate() {
    if (!wb) return;

    wb.addEventListener('controlling', () => window.location.reload());
    wb.messageSkipWaiting();
}
