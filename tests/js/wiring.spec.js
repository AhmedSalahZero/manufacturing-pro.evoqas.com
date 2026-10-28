// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Wiring tests (run: npm test)
//  Location: tests/js/wiring.spec.js
//  Checks that the screens, the menu and the server agree with each other:
//    · every route('name') a screen uses is a route the server defines;
//    · every Inertia screen the server renders exists as a .vue file;
//    · every menu section points to a real route and has words in both languages.
// ══════════════════════════════════════════════════════════════════
import { describe, expect, it } from 'vitest';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { accents, sections } from '@/Layouts/navigation';
import en from '@/lang/en.js';
import ar from '@/lang/ar.js';

function files(dir, pattern, found = []) {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) files(path, pattern, found);
        else if (pattern.test(name) && !/\.spec\.js$/.test(name)) found.push(path);
    }

    return found;
}

const serverRoutes = files('routes', /\.php$/).map((f) => readFileSync(f, 'utf8')).join('\n');
const routeNames = new Set([...serverRoutes.matchAll(/->name\('([a-zA-Z0-9_.\-]+)'\)/g)].map((m) => m[1]));
const get = (object, path) => path.split('.').reduce((node, part) => node?.[part], object);

describe('screens and routes', () => {
    it('knows the server routes', () => {
        expect(routeNames.size).toBeGreaterThan(8);
        expect(routeNames.has('login')).toBe(true);
    });

    it('every route() a screen uses exists on the server', () => {
        const missing = [];
        for (const file of files('resources/js', /\.(vue|js)$/)) {
            for (const m of readFileSync(file, 'utf8').matchAll(/\broute\(\s*'([a-zA-Z0-9_.\-]+)'/g)) {
                if (!routeNames.has(m[1])) missing.push(`${file}: ${m[1]}`);
            }
        }
        expect(missing).toEqual([]);
    });

    it('every screen the server renders exists', () => {
        const names = new Set();
        for (const file of files('app', /\.php$/)) {
            for (const m of readFileSync(file, 'utf8').matchAll(/Inertia::render\(\s*'([A-Za-z0-9_\/]+)'/g)) names.add(m[1]);
        }
        expect(names.size).toBeGreaterThan(2);
        for (const name of names) {
            expect(existsSync(`resources/js/Pages/${name}.vue`), `Pages/${name}.vue`).toBe(true);
        }
    });
});

describe('the menu', () => {
    it('has at least the Home section', () => {
        expect(sections.some((s) => s.key === 'home')).toBe(true);
    });

    it('points every section to a real route, with words in both languages and a known colour', () => {
        for (const s of sections) {
            expect(routeNames.has(s.route), `route ${s.route}`).toBe(true);
            expect(typeof get(en, s.label), `en ${s.label}`).toBe('string');
            expect(typeof get(ar, s.label), `ar ${s.label}`).toBe('string');
            expect(accents, `accent ${s.accent}`).toContain(s.accent);
        }
    });

    it('has unique keys', () => {
        const keys = sections.map((s) => s.key);
        expect(new Set(keys).size).toBe(keys.length);
    });
});
