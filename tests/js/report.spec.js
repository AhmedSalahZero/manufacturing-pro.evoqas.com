// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Printable report tests (run: npm test)
//  Location: tests/js/report.spec.js
//  Checks: every label the server builds for the report exists in English and Arabic; the report page shows a contents list,
//  every input section, the statements and the results, in that order; each cell kind is written correctly.
// ══════════════════════════════════════════════════════════════════
import { describe, expect, it, vi } from 'vitest';
import { createApp, h, nextTick } from 'vue';
import { readFileSync } from 'node:fs';

vi.mock('@inertiajs/vue3', async () => {
    const vue = await import('vue');
    const passthrough = (tag) => vue.defineComponent({ props: ['href', 'as'], setup: (p, { slots, attrs }) => () => vue.h(tag, { href: p.href, ...attrs }, slots.default?.()) });
    return {
        Head: vue.defineComponent({ setup: () => () => null }),
        Link: passthrough('a'),
        router: { post() {}, put() {}, delete() {}, on() {} },
        usePage: () => ({ props: { auth: { user: { role: 'company_admin', name: 'Mona', email: 'm@x.test' } }, flash: {}, locale: 'en' } }),
    };
});
vi.mock('@/pwa/register', () => ({ applyUpdate() {}, updateReady: { value: false }, registerServiceWorker() {} }));
globalThis.route = (name, p) => `/${name}${p ? `/${p}` : ''}`;

const { default: ReportBlock } = await import('@/Components/ReportBlock.vue');
const { default: Show } = await import('@/Pages/Report/Show.vue');
const { default: en } = await import('@/lang/en.js');
const { default: ar } = await import('@/lang/ar.js');

const get = (o, path) => path.split('.').reduce((n, k) => n?.[k], o);
const mount = async (component, props) => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp({ render: () => h(component, props) });
    app.config.globalProperties.route = globalThis.route;
    app.mount(el);
    await nextTick();

    return el;
};

describe('words', () => {
    const source = readFileSync('app/Services/ReportInputsService.php', 'utf8');
    // every "$this->k('some.key'" in the service, plus the keys built from a loop (types, categories, kinds, terms, intervals)
    const fixed = [...source.matchAll(/\$this->k\('([a-zA-Z.]+)'[,)]/g)].map((m) => m[1]);
    const built = [
        ...['direct_labor', 'manufacturing_overheads', 'operational_salaries', 'sales', 'marketing', 'general'].map((k) => `manpower.types.${k}`),
        ...['manufacturing-expenses', 'other-operation-expenses', 'marketing', 'sales', 'general-expenses', 'pre-operating-expenses', 'start-up-expenses'].map((k) => `expenses.categories.${k}`),
        ...['fixed_monthly_repeating_amount', 'one_time_expense', 'expense_as_percentage', 'cost_per_unit'].map((k) => `expenses.kinds.${k}`),
        ...['cash', 'quarterly', 'semi-annually', 'annually', 'customize'].map((k) => `expenses.terms.${k}`),
        ...['cash', 'customize', 'installment'].map((k) => `assets.terms.${k}`),
        ...['monthly', 'quarterly', 'semi-annually', 'annually'].map((k) => `assets.intervals.${k}`),
        ...['raw_material_value', 'direct_labor_value', 'manufacturing_overheads_value'].map((k) => `products.inventory.parts.${k}`),
        ...['flat', 'quarterly', 'monthly'].map((k) => `products.season.${k}`),
        ...['local', 'export'].map((k) => `products.collection.${k}`),
        ...['summary', 'products', 'rawMaterials', 'manpower', 'expenses', 'assets', 'opening', 'statements', 'results'].map((k) => `report.sections.${k}`),
        'report.contents',
    ];

    it('has the words for every label the report can show, in both languages', () => {
        expect(fixed.length).toBeGreaterThan(60);
        for (const key of new Set([...fixed, ...built])) {
            expect(typeof get(en, key), `en ${key}`).toBe('string');
            expect(typeof get(ar, key), `ar ${key}`).toBe('string');
        }
    });
});

describe('a block', () => {
    it('writes each kind of cell', async () => {
        const el = await mount(ReportBlock, {
            block: {
                type: 'table', title: { k: 'report.contents' }, total: true,
                headers: [{ k: 'report.f.kind' }, 'B'],
                rows: [[{ text: 'Pasta' }, { pct: 12.5 }], [{ k: 'terms.days', p: { n: 30 } }, { n: 1234.567, d: 1 }], ['Total', 4500]],
            },
        });
        const text = el.textContent;

        expect(text).toContain('Contents');
        expect(text).toContain('Pasta');
        expect(text).toContain('12.5%');
        expect(text).toContain('30 days');
        expect(text).toContain('1,234.6');
        expect(text).toContain('4,500');
        expect(el.querySelectorAll('tbody tr').length).toBe(3);
        expect(el.querySelector('.totalrow')?.textContent).toContain('Total');
    });

    it('writes a paragraph for a text block', async () => {
        const el = await mount(ReportBlock, { block: { type: 'text', title: null, text: 'Prices follow the market.' } });

        expect(el.textContent).toContain('Prices follow the market.');
        expect(el.querySelector('table')).toBeNull();
    });
});

describe('the report page', () => {
    const years = [{ index: 0, year: 2026 }];
    const statements = { years, tabs: { income: [], cash: [], balance: [] }, check: { balanced: true, gap: 0 }, tiles: {} };
    const table = (id) => ({ type: 'table', title: { text: `T-${id}` }, headers: ['', 'A'], rows: [['x', 1]], total: false });

    it('has a contents list and every section in order', async () => {
        const ids = ['products', 'rawMaterials', 'manpower', 'expenses', 'assets', 'opening'];
        const el = await mount(Show, {
            project: { id: 1, name: 'Plant A', currency: 'EGP', intro: '', start: '2026-01', duration: 1, tax: 22.5, return: 15, growth: 2 },
            ready: true, comments: {}, date: '2026-10-27', statements,
            dashboard: { years, periods: ['2026'], monthly: false, cash: { in: [1], out: [1], accumulated: [1] }, revenue: { value: [1], growth: [0], accumulated: [1] },
                profit: Object.fromEntries(['gross_profit', 'ebitda', 'ebit', 'net_profit'].map((k) => [k, { value: [1], percent: [1] }])),
                costs: Object.fromEntries(['raw_material', 'direct_labor', 'manufacturing_overheads', 'marketing', 'sales', 'general'].map((k) => [k, { value: [1], percent: [1] }])),
                investment: { fixed_assets: 1, working_capital: 1, equity: 1, loans: 1 },
                dcf: { ...Object.fromEntries(['free_cash_flow', 'ebit', 'taxes', 'depreciation', 'working_capital', 'capex', 'wacc', 'terminal_value', 'with_terminal', 'discount_factor', 'discounted'].map((k) => [k, [1]])), terminal_ok: true },
                ratios: Object.fromEntries(['current_ratio', 'quick_ratio', 'cash_ratio', 'working_capital', 'dso', 'dio', 'dpo', 'ccc'].map((k) => [k, [1]])),
                kpi: { npv: 1, irr: 1, payback: { month: 10, months: 11, years: 0.9, date: '2026-11' }, investment: 1 } },
            inputs: ids.map((id) => ({ id, blocks: [table(id)] })),
        });

        const order = [...el.querySelectorAll('[data-report-section]')].map((n) => n.getAttribute('data-report-section'));
        expect(order).toEqual([...ids, 'statements', 'results']);
        expect(el.querySelectorAll('[data-report-contents] li').length).toBe(9);
        for (const id of ids) expect(el.textContent).toContain(`T-${id}`);
        expect(el.querySelector('input, textarea, select')).toBeNull();
    });

    it('leaves out the opening balances when there are none', async () => {
        const el = await mount(Show, {
            project: { id: 1, name: 'Plant A', currency: 'EGP', intro: '', start: '2026-01', duration: 1, tax: 22.5, return: 15, growth: 2 },
            ready: false, comments: {}, date: '', inputs: [],
        });

        expect(el.querySelector('[data-report-section]')).toBeNull();
    });
});
