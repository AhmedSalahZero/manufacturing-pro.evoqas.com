// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Translation tests (run: npm test)
//  Location: tests/js/i18n.spec.js
//  English and Arabic must have exactly the same words, nothing empty,
//  the same {placeholders}, and every word a screen asks for must exist.
// ══════════════════════════════════════════════════════════════════
import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import en from '@/lang/en.js';
import ar from '@/lang/ar.js';
import { translate } from '@/lang/i18n';

function flatten(object, prefix = '') {
    return Object.entries(object).flatMap(([key, value]) => (
        value !== null && typeof value === 'object' ? flatten(value, `${prefix}${key}.`) : [[`${prefix}${key}`, value]]
    ));
}

const english = Object.fromEntries(flatten(en));
const arabic = Object.fromEntries(flatten(ar));

function sourceFiles(dir, found = []) {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) sourceFiles(path, found);
        else if (/\.(vue|js)$/.test(name) && !/\.spec\.js$/.test(name) && !path.includes(`${join('resources', 'js', 'lang')}`)) found.push(path);
    }

    return found;
}

describe('English and Arabic words', () => {
    it('have exactly the same keys', () => {
        expect(Object.keys(arabic).sort()).toEqual(Object.keys(english).sort());
    });

    it('are never empty', () => {
        for (const [key, value] of [...Object.entries(english), ...Object.entries(arabic)]) {
            expect(typeof value, key).toBe('string');
            expect(value.trim(), key).not.toBe('');
        }
    });

    it('use the same {placeholders} in both languages', () => {
        const names = (text) => [...text.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort();
        for (const key of Object.keys(english)) {
            expect(names(arabic[key]), key).toEqual(names(english[key]));
        }
    });

    it('fill a placeholder, and show the key when a word is missing', () => {
        expect(translate('home.welcome', { name: 'Mona' }, 'en')).toBe('Welcome, Mona');
        expect(translate('home.welcome', { name: 'منى' }, 'ar')).toBe('مرحبًا، منى');
        expect(translate('no.such.key', {}, 'en')).toBe('no.such.key');
    });
});

describe('every word a screen asks for exists in both languages', () => {
    const used = new Set();
    for (const file of sourceFiles('resources/js')) {
        const text = readFileSync(file, 'utf8');
        for (const m of text.matchAll(/\b(?:t|tr)\(\s*'([a-zA-Z0-9_.]+)'/g)) used.add(m[1]);
        for (const m of text.matchAll(/label:\s*'([a-zA-Z0-9_.]+)'/g)) used.add(m[1]);
    }

    it('found the words the screens use', () => {
        expect(used.size).toBeGreaterThan(20);
    });

    it('has each of them', () => {
        for (const key of used) {
            const known = key in english || Object.keys(english).some((k) => k.startsWith(`${key}.`));
            expect(known, `"${key}" is used by a screen but missing from en.js / ar.js`).toBe(true);
        }
    });
});
