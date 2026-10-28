// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Translations (English + Arabic)
//  Location: resources/js/lang/i18n.js
//
//  Every word on a screen lives in en.js / ar.js — nothing is typed inside a screen.
//    t('auth.signIn')                       → "Sign in" / "تسجيل الدخول"
//    t('home.welcome', { name: 'Mona' })    → "Welcome, Mona"
//  A missing key shows the key itself, so a gap is visible instead of blank.
//  The two files must have exactly the same keys (tests/js/i18n.spec.js checks it).
// ══════════════════════════════════════════════════════════════════
import { computed, ref } from 'vue';
import ar from './ar.js';
import en from './en.js';

const dictionaries = { ar, en };

export const currentLocale = ref(document.documentElement.lang === 'ar' ? 'ar' : 'en');

export function setLocale(locale) {
    currentLocale.value = locale === 'ar' ? 'ar' : 'en';
    document.documentElement.lang = currentLocale.value;
    document.documentElement.dir = currentLocale.value === 'ar' ? 'rtl' : 'ltr';
}

export function raw(key, locale = currentLocale.value) {
    return key.split('.').reduce((node, part) => (node == null ? undefined : node[part]), dictionaries[locale]);
}

export function translate(key, params = {}, locale = currentLocale.value) {
    const value = raw(key, locale);

    if (typeof value !== 'string') {
        return key;
    }

    return value.replace(/\{(\w+)\}/g, (match, name) => (params[name] ?? match));
}

export function useI18n() {
    return {
        t: (key, params) => translate(key, params, currentLocale.value),
        tr: (key) => raw(key, currentLocale.value),
        locale: currentLocale,
        isAr: computed(() => currentLocale.value === 'ar'),
    };
}

export { dictionaries };
