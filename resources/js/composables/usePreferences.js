// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — usePreferences (language and theme)
//  Location: resources/js/composables/usePreferences.js
//
//  initPreferences(props) runs once at start-up: sets language, time zone and theme.
//  usePreferences().savePreference({ language?: 'en'|'ar', theme?: 'light'|'dark' })
//  changes the screen at once and saves the choice on the server (POST /preferences).
//  The theme is the data-theme attribute on <html>.
// ══════════════════════════════════════════════════════════════════
import { router } from '@inertiajs/vue3';
import { setLocale } from '@/lang/i18n';
import { storageGet, storageSet } from '@/Utils/safeStorage';
import { setTimezone } from '@/Utils/format';

const THEME_COLOR = { light: '#0B2A78', dark: '#0B1730' };

export function applyTheme(theme) {
    const value = theme === 'dark' ? 'dark' : 'light';

    document.documentElement.setAttribute('data-theme', value);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', THEME_COLOR[value]);

    return value;
}

export function initPreferences(props) {
    setLocale(props.locale);
    setTimezone(props.timezone);
    applyTheme(props.auth?.user?.theme ?? props.theme ?? storageGet('zavero.theme', 'light'));
}

export function usePreferences() {
    function savePreference(changes) {
        if (changes.theme) {
            applyTheme(changes.theme);
            storageSet('zavero.theme', changes.theme);
        }
        if (changes.language) {
            setLocale(changes.language);
        }

        router.post(route('preferences.update'), changes, { preserveScroll: true, preserveState: true });
    }

    return { savePreference };
}
