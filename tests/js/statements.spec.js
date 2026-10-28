// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Financial statements screen tests (run: npm test)
//  Location: tests/js/statements.spec.js
//  Checks: every row the server can send has a name in English and Arabic; the screen shows the three statements, opens a
//  year into its months, shows the balance check, and tells a new study that there is nothing to show yet.
// ══════════════════════════════════════════════════════════════════
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, h, nextTick } from 'vue';
import { readFileSync } from 'node:fs';

vi.mock('@inertiajs/vue3', async () => {
    const vue = await import('vue');
    const passthrough = (tag) => vue.defineComponent({ props: ['href', 'as'], setup: (p, { slots, attrs }) => () => vue.h(tag, { href: p.href, ...attrs }, slots.default?.()) });
    return {
        Head: vue.defineComponent({ setup: () => () => null }),
        Link: passthrough('a'),
        router: { post() {}, on() {} },
        usePage: () => ({ props: { auth: { user: { role: 'company_admin', name: 'Mona Ali', email: 'm@x.test' } }, flash: {}, locale: 'en' } }),
    };
});
vi.mock('@/pwa/register', () => ({ applyUpdate() {}, updateReady: { value: false }, registerServiceWorker() {} }));
globalThis.route = (name, p) => `/${name}${Array.isArray(p) ? '/' + p.join('/') : p ? `/${p}` : ''}`;

const { default: Show } = await import('@/Pages/Statements/Show.vue');
const { setLocale } = await import('@/lang/i18n');
const { default: en } = await import('@/lang/en.js');
const { default: ar } = await import('@/lang/ar.js');

const render = async (props) => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp({ render: () => h(Show, props) });
    app.config.globalProperties.route = globalThis.route;
    app.mount(el);
    await nextTick();

    return el;
};
const click = async (el) => { el.click(); await nextTick(); };

const row = (key, yearly, monthly = {}, style = 'normal') => ({ key, style, yearly, monthly });
const statements = (over = {}) => ({
    years: [
        { index: 0, year: 2026, months: [{ index: 0, month: 1 }, { index: 1, month: 2 }] },
        { index: 1, year: 2027, months: [{ index: 2, month: 1 }] },
    ],
    tabs: {
        income: [row('revenue', { 0: 1500, 1: 2500 }, { 0: 500, 1: 1000, 2: 2500 }, 'total'), row('net_margin', { 0: 12.5, 1: 20 }, { 0: 10, 1: 15, 2: 20 }, 'percent')],
        cash: [row('cash_end', { 0: 900, 1: 1200 }, { 0: 300, 1: 900, 2: 1200 }, 'total')],
        balance: [row('total_assets', { 0: 7000, 1: 8000 }, {}, 'total'), row('check', { 0: 0, 1: 0 }, {}, 'check')],
    },
    check: { balanced: true, gap: 0 },
    tiles: { revenue: 4000, net_profit: 640, net_margin: 16, cash_end: 1200, injection: 0 },
    ...over,
});
const props = (over = {}) => ({ project: { id: 5, name: 'Plant', newCompany: true }, ready: true, statements: statements(), taxRate: 22.5, ...over });

beforeEach(() => { document.body.innerHTML = ''; setLocale('en'); });

describe('names of the rows', () => {
    const source = readFileSync('app/Services/StatementsService.php', 'utf8');
    const keys = new Set([...source.matchAll(/\$(?:row|ratio)\(\s*'([a-z_]+)'/g)].map((m) => m[1]));
    ['general-expenses', 'sales', 'marketing', 'start-up-expenses', 'pre-operating-expenses'].forEach((c) => keys.add(`expense_${c.replace(/-/g, '_')}`));
    ['raw_material', 'direct_labor', 'manufacturing_overheads'].forEach((c) => keys.add(`cogs_${c}`));
    ['check'].forEach((k) => keys.add(k));

    it('finds the rows in the service', () => {
        expect(keys.size).toBeGreaterThan(50);
    });

    it('every row has a name in English and in Arabic', () => {
        const missing = [...keys].filter((k) => !en.statements.rows[k] || !ar.statements.rows[k]);
        expect(missing).toEqual([]);
    });
});

describe('the statements screen', () => {
    it('shows the income statement with a column per year and the tiles', async () => {
        const el = await render(props());
        expect(el.querySelector('[data-row="revenue"]').textContent).toContain('1,500');
        expect(el.querySelector('[data-row="revenue"]').textContent).toContain('2,500');
        expect(el.querySelector('[data-row="net_margin"]').textContent).toContain('12.5%');
        expect(el.querySelectorAll('.tile').length).toBe(4);
        expect(el.querySelector('[data-check="ok"]')).not.toBeNull();
    });

    it('switches to the cash flow and the balance sheet', async () => {
        const el = await render(props());
        await click(el.querySelector('[data-tab="cash"]'));
        expect(el.querySelector('[data-row="cash_end"]')).not.toBeNull();
        expect(el.querySelector('[data-row="revenue"]')).toBeNull();
        await click(el.querySelector('[data-tab="balance"]'));
        expect(el.querySelector('[data-row="total_assets"]').textContent).toContain('7,000');
    });

    it('opens a year into its months, and closes it again', async () => {
        const el = await render(props());
        expect(el.querySelectorAll('thead th.mo').length).toBe(0);
        await click(el.querySelector('thead th.yr'));
        const months = [...el.querySelectorAll('thead th.mo')].map((th) => th.textContent.trim());
        expect(months).toEqual(['2026-01', '2026-02']);
        expect(el.querySelector('[data-row="revenue"]').textContent).toContain('1,000');
        await click(el.querySelector('thead th.yr'));
        expect(el.querySelectorAll('thead th.mo').length).toBe(0);
    });

    it('warns when the balance sheet does not balance', async () => {
        const el = await render(props({ statements: statements({ check: { balanced: false, gap: 1234 } }) }));
        expect(el.querySelector('[data-check="bad"]').textContent).toContain('1,234');
        expect(el.querySelector('[data-check="ok"]')).toBeNull();
    });

    it('tells a study with no data yet that there is nothing to show', async () => {
        const el = await render({ project: { id: 5, name: 'Plant', newCompany: true }, ready: false });
        expect(el.querySelector('table')).toBeNull();
        expect(el.textContent).toContain(en.statements.notReady);
    });

    it('speaks Arabic', async () => {
        setLocale('ar');
        const el = await render(props());
        expect(el.querySelector('[data-row="revenue"] td').textContent.trim()).toBe(ar.statements.rows.revenue);
    });
});
