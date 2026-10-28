// ZAVERO Manufacturing Pro — language and theme tests (run: npm test)
// Location: tests/js/preferences.spec.js
import { beforeEach, describe, expect, it } from 'vitest';
import { applyTheme, initPreferences } from '@/composables/usePreferences';
import { currentLocale, setLocale } from '@/lang/i18n';

beforeEach(() => {
    document.head.innerHTML = '<meta name="theme-color" content="#000000">';
    document.documentElement.removeAttribute('data-theme');
    setLocale('en');
});

describe('theme', () => {
    it('sets data-theme and the browser bar colour', () => {
        expect(applyTheme('dark')).toBe('dark');
        expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
        expect(document.querySelector('meta[name="theme-color"]').getAttribute('content')).toBe('#0B1730');

        expect(applyTheme('light')).toBe('light');
        expect(document.documentElement.getAttribute('data-theme')).toBe('light');
        expect(document.querySelector('meta[name="theme-color"]').getAttribute('content')).toBe('#0B2A78');
    });

    it('treats anything unknown as light', () => {
        expect(applyTheme('purple')).toBe('light');
        expect(applyTheme(undefined)).toBe('light');
    });
});

describe('language', () => {
    it('Arabic is right-to-left, English left-to-right', () => {
        setLocale('ar');
        expect(document.documentElement.lang).toBe('ar');
        expect(document.documentElement.dir).toBe('rtl');
        expect(currentLocale.value).toBe('ar');

        setLocale('en');
        expect(document.documentElement.dir).toBe('ltr');
    });

    it('anything unknown falls back to English', () => {
        setLocale('fr');
        expect(currentLocale.value).toBe('en');
    });
});

describe('initPreferences', () => {
    it('takes the signed-in person\'s language and theme', () => {
        initPreferences({ locale: 'ar', timezone: 'Africa/Cairo', auth: { user: { theme: 'dark' } }, theme: 'light' });
        expect(document.documentElement.dir).toBe('rtl');
        expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    });

    it('takes the guest\'s choice on the sign-in page', () => {
        initPreferences({ locale: 'en', timezone: 'Africa/Cairo', auth: { user: null }, theme: 'dark' });
        expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
    });
});
