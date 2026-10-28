// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Screen start-up
//  Location: resources/js/app.js
//  Starts Vue + Inertia, loads every screen from resources/js/Pages,
//  applies the saved language and theme, and starts the offline helper.
// ══════════════════════════════════════════════════════════════════
import '../css/app.css';
import './bootstrap';

import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { ZiggyVue } from 'ziggy-js';
import { initPreferences } from '@/composables/usePreferences';
import { setLocale } from '@/lang/i18n';
import { registerServiceWorker } from '@/pwa/register';

const appName = import.meta.env.VITE_APP_NAME || 'ZAVERO Manufacturing Pro';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        initPreferences(props.initialPage.props);
        router.on('success', (event) => setLocale(event.detail.page.props.locale));

        return createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: { color: '#2563EB', showSpinner: false },
});

registerServiceWorker();
