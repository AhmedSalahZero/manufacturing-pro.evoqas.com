// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Vite (builds the screens)
//  Location: vite.config.js
//
//  `npm run dev`   → while working: screens reload as files change
//  `npm run build` → for real use: writes everything to public/build
//
//  Plugins:
//    tailwindcss() → Tailwind CSS v4
//    vue()         → Vue single-file screens (.vue)
//    VitePWA()     → makes ZAVERO installable (phone, tablet, desktop) and builds the
//                    offline helper (service worker) from resources/js/pwa/sw.js.
//                    It lands in public/build/sw.js and is served at /sw.js by
//                    App\Http\Controllers\PwaController.
//
//  '@' means resources/js, so imports read  import AppIcon from '@/Components/AppIcon.vue'
// ══════════════════════════════════════════════════════════════════

import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';
import { fileURLToPath, URL } from 'node:url';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';

// The offline page is a plain file in /public; its "version" is a fingerprint of its content,
// so the helper re-downloads it exactly when it changes.
const offlinePage = createHash('md5').update(readFileSync('public/offline.html')).digest('hex');

export default defineConfig({
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            'ziggy-js': fileURLToPath(new URL('./vendor/tightenco/ziggy', import.meta.url)),
        },
    },

    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),

        tailwindcss(),

        vue({
            template: { transformAssetUrls: { base: null, includeAbsolute: false } },
        }),

        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js/pwa',
            filename: 'sw.js',
            outDir: 'public/build',
            buildBase: '/build/',
            scope: '/',
            base: '/',
            injectRegister: false,
            manifestFilename: 'manifest.webmanifest',
            injectManifest: {
                globDirectory: 'public/build',
                globPatterns: ['**/*.{js,css,woff2,png,svg,ico,webmanifest}'],
                additionalManifestEntries: [{ url: '/offline.html', revision: offlinePage }],
                modifyURLPrefix: { '': '/build/' },
                maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
            },
            manifest: {
                id: '/',
                name: 'ZAVERO Manufacturing Pro',
                short_name: 'ZAVERO',
                description: 'Financial planning for the manufacturing sector',
                lang: 'en',
                start_url: '/',
                scope: '/',
                display: 'standalone',
                background_color: '#F3F5F9',
                theme_color: '#0B2A78',
                icons: [
                    { src: '/images/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/images/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
                    { src: '/images/icons/maskable-512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
                ],
            },
        }),
    ],
});
