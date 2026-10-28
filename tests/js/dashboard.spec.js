// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Dashboard, what-if, public link and report screen tests (run: npm test)
//  Location: tests/js/dashboard.spec.js
//  Checks: every word the dashboard can show exists in English and Arabic; the dashboard shows the KPI tiles and switches
//  between its tabs; "No IRR" and "never" are said in words; the public page offers dashboard and statements only.
// ══════════════════════════════════════════════════════════════════
import { describe, expect, it, vi } from 'vitest';
import { createApp, h, nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () => {
    const vue = await import('vue');
    const passthrough = (tag) => vue.defineComponent({ props: ['href', 'as'], setup: (p, { slots, attrs }) => () => vue.h(tag, { href: p.href, ...attrs }, slots.default?.()) });
    return {
        Head: vue.defineComponent({ setup: () => () => null }),
        Link: passthrough('a'),
        router: { post() {}, put() {}, delete() {}, on() {} },
        useForm: (d) => vue.reactive({ ...JSON.parse(JSON.stringify(d)), errors: {}, processing: false, put() {}, post() {}, reset() {} }),
        usePage: () => ({ props: { auth: { user: { role: 'company_admin', name: 'Mona', email: 'm@x.test' } }, flash: {}, locale: 'en' } }),
    };
});
vi.mock('@/pwa/register', () => ({ applyUpdate() {}, updateReady: { value: false }, registerServiceWorker() {} }));
globalThis.route = (name, p) => `/${name}${p ? `/${p}` : ''}`;

const { default: DashboardView } = await import('@/Components/DashboardView.vue');
const { default: Shared } = await import('@/Pages/Shared/Show.vue');
const { default: en } = await import('@/lang/en.js');
const { default: ar } = await import('@/lang/ar.js');

const years = [{ index: 0, year: 2026 }, { index: 1, year: 2027 }];
const two = (a, b) => [a, b];
const dash = (over = {}) => ({
    years,
    periods: ['2026', '2027'],
    monthly: false,
    cash: { in: two(500, 600), out: two(400, 450), accumulated: two(100, 250) },
    revenue: { value: two(1000, 1200), growth: two(0, 20), accumulated: two(1000, 2200) },
    profit: Object.fromEntries(['gross_profit', 'ebitda', 'ebit', 'net_profit'].map((k) => [k, { value: two(100, 200), percent: two(10, 16.7) }])),
    costs: Object.fromEntries(['raw_material', 'direct_labor', 'manufacturing_overheads', 'marketing', 'sales', 'general'].map((k) => [k, { value: two(10, 12), percent: two(1, 1) }])),
    investment: { fixed_assets: 5000, working_capital: 1000, equity: 4000, loans: 2000 },
    dcf: Object.fromEntries(['free_cash_flow', 'ebit', 'taxes', 'depreciation', 'working_capital', 'capex', 'wacc', 'terminal_value', 'with_terminal', 'discount_factor', 'discounted'].map((k) => [k, two(1, 2)])) ,
    ratios: Object.fromEntries(['current_ratio', 'quick_ratio', 'cash_ratio', 'working_capital', 'dso', 'dio', 'dpo', 'ccc'].map((k) => [k, two(1, 2)])),
    kpi: { npv: 1500, irr: 22.5, payback: { month: 40, months: 41, years: 3.4, date: '2029-05' }, investment: 6000 },
    ...over,
});
dash().dcf.terminal_ok = true;
const withDcf = (over = {}) => { const d = dash(over); d.dcf.terminal_ok = true; return d; };

const mount = async (component, props) => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp({ render: () => h(component, props) });
    app.config.globalProperties.route = globalThis.route;
    app.mount(el);
    await nextTick();

    return el;
};
const get = (o, path) => path.split('.').reduce((n, k) => n?.[k], o);

describe('words', () => {
    const keys = [
        ...['irr', 'npv', 'payback', 'investment'].map((k) => `dashboard.tiles.${k}`),
        ...['overview', 'profit', 'costs', 'ratios', 'value'].map((k) => `dashboard.tabs.${k}`),
        ...['cashInOut', 'cashIn', 'cashOut', 'cashAccumulated'].map((k) => `dashboard.charts.${k}`),
        'dashboard.table.value', 'dashboard.table.percent',
        ...['raw_material', 'direct_labor', 'manufacturing_overheads', 'marketing', 'sales', 'general'].map((k) => `dashboard.costs.${k}`),
        ...['fixed_assets', 'working_capital', 'equity', 'loans'].map((k) => `dashboard.investment.${k}`),
        ...['ebit', 'taxes', 'depreciation', 'working_capital', 'capex', 'free_cash_flow', 'wacc', 'terminal_value', 'with_terminal', 'discount_factor', 'discounted'].map((k) => `dashboard.dcf.rows.${k}`),
        ...['enterprise', 'irr', 'payback', 'terminalShare'].map((k) => `dashboard.dcf.summary.${k}`),
        'dashboard.dcf.summaryTitle', 'dashboard.dcf.summaryNote',
        ...['liquidity', 'activity', 'current_ratio', 'quick_ratio', 'cash_ratio', 'working_capital', 'dso', 'dio', 'dpo', 'ccc'].map((k) => `dashboard.ratios.${k}`),
        ...['revenue', 'growth', 'accumulated', 'margin', 'gross_profit', 'ebitda', 'ebit', 'net_profit', 'costsPercent'].map((k) => `dashboard.charts.${k}`),
        ...['start', 'duration', 'currency', 'tax', 'returnRate', 'growth'].map((k) => `report.summary.${k}`),
        ...['revenue', 'netProfit', 'npv', 'irr', 'payback'].map((k) => `sensitivity.lines.${k}`),
        'projects.form.stepsDashboard', 'projects.form.stepsSensitivity', 'projects.form.stepsReport', 'projects.form.stepsLinks',
    ];

    it('every dynamic key exists in English and Arabic', () => {
        for (const key of keys) {
            expect(get(en, key), `en ${key}`).toBeTruthy();
            expect(get(ar, key), `ar ${key}`).toBeTruthy();
        }
    });

    it('the placeholders match in both languages', () => {
        const names = (s) => [...String(s).matchAll(/\{(\w+)\}/g)].map((m) => m[1]).sort().join();
        for (const key of ['dashboard.paybackValue', 'dashboard.currencyNote', 'sharing.deleteText', 'report.preparedOn']) {
            expect(names(get(ar, key)), key).toBe(names(get(en, key)));
        }
    });
});

describe('dashboard', () => {
    it('shows the four KPI tiles and the overview first', async () => {
        const el = await mount(DashboardView, { dashboard: withDcf(), currency: 'EGP' });

        expect(el.querySelector('[data-kpi="irr"]').textContent).toContain('22.5%');
        expect(el.querySelector('[data-kpi="npv"]').textContent).toContain('1,500');
        expect(el.querySelector('[data-kpi="payback"]').textContent).toContain('3.4 years');
        expect(el.querySelector('[data-kpi="investment"]').textContent).toContain('6,000');
        expect(el.querySelector('[data-section="overview"]')).not.toBeNull();
        expect(el.querySelector('[data-section="value"]')).toBeNull();
    });

    it('has the tabs in the order overview, profitability, costs, ratios, valuation', async () => {
        const el = await mount(DashboardView, { dashboard: withDcf() });

        expect([...el.querySelectorAll('[data-tab]')].map((b) => b.dataset.tab)).toEqual(['overview', 'profit', 'costs', 'ratios', 'value']);
        expect(el.querySelector('[data-tab="value"]').textContent).toBe('Valuation');
    });

    it('shows the cash in / out chart and the accumulated cash flow in the overview', async () => {
        const el = await mount(DashboardView, { dashboard: withDcf() });

        expect(el.querySelector('[data-chart="cash"]')).not.toBeNull();
        expect(el.querySelector('[data-chart="cash-accumulated"]')).not.toBeNull();
    });

    it('shows each profit chart with its own table (value and percentage), one after the other', async () => {
        const el = await mount(DashboardView, { dashboard: withDcf() });
        el.querySelector('[data-tab="profit"]').click();
        await nextTick();

        const blocks = [...el.querySelectorAll('[data-profit]')];
        expect(blocks.map((b) => b.dataset.profit)).toEqual(['gross_profit', 'ebitda', 'ebit', 'net_profit']);
        for (const b of blocks) {
            expect(b.querySelector('svg')).not.toBeNull();
            expect(b.querySelectorAll('tbody tr').length).toBe(2);
        }
    });

    it('a one-year study is shown month by month', async () => {
        const months = ['2026-01', '2026-02', '2026-03'];
        const three = (a) => [a, a, a];
        const d = withDcf({ monthly: true, periods: months, cash: { in: three(5), out: three(4), accumulated: three(1) },
            revenue: { value: three(10), growth: three(0), accumulated: three(10) } });
        for (const k of Object.keys(d.profit)) d.profit[k] = { value: three(1), percent: three(10) };
        const el = await mount(DashboardView, { dashboard: d });
        el.querySelector('[data-tab="profit"]').click();
        await nextTick();

        expect(el.querySelector('[data-profit="ebit"] thead').textContent).toContain('2026-03');
        expect(el.querySelectorAll('[data-profit="ebit"] thead th').length).toBe(4);
    });

    it('switches between the tabs', async () => {
        const el = await mount(DashboardView, { dashboard: withDcf() });

        for (const tab of ['profit', 'costs', 'ratios', 'value']) {
            el.querySelector(`[data-tab="${tab}"]`).click();
            await nextTick();
            expect(el.querySelector(`[data-section="${tab}"]`), tab).not.toBeNull();
            expect(el.querySelector('[data-section="overview"]')).toBeNull();
        }
    });

    it('shows the discounted cash flow and the valuation result under the valuation table', async () => {
        const d = withDcf();
        d.dcf.discounted = [100, 1500];
        d.dcf.terminal_pv = 1000;
        const el = await mount(DashboardView, { dashboard: d });
        el.querySelector('[data-tab="value"]').click();
        await nextTick();

        expect(el.querySelector('[data-row="discounted"]').textContent).toContain('1,500');
        const tile = (k) => el.querySelector(`[data-summary="${k}"]`).textContent;
        expect(tile('enterprise')).toContain('1,500');         // the NPV of the study
        expect(tile('irr')).toContain('22.5%');
        expect(tile('payback')).toContain('2029-05');
        expect(tile('terminalShare')).toContain('66.7%');      // 1 000 of 1 500
    });

    it('shows no terminal share when the enterprise value is not positive', async () => {
        const d = withDcf();
        d.kpi.npv = -50;
        d.kpi.irr = null;
        const el = await mount(DashboardView, { dashboard: d });
        el.querySelector('[data-tab="value"]').click();
        await nextTick();

        expect(el.querySelector('[data-summary="terminalShare"]').textContent).toContain('-');
        expect(el.querySelector('[data-summary="irr"]').textContent).toContain('No IRR');
    });

    it('says so in words when there is no IRR or no payback', async () => {
        const d = withDcf();
        d.kpi.irr = null;
        d.kpi.payback = { month: null, months: null, years: null, date: null };
        const el = await mount(DashboardView, { dashboard: d });

        expect(el.querySelector('[data-kpi="irr"]').textContent).toContain('No IRR');
        expect(el.querySelector('[data-kpi="payback"]').textContent).toContain('Not within 20 years');
    });

    it('warns when the terminal value was left out', async () => {
        const d = withDcf();
        d.dcf.terminal_ok = false;
        const el = await mount(DashboardView, { dashboard: d });
        el.querySelector('[data-tab="value"]').click();
        await nextTick();

        expect(el.querySelector('[data-note="terminal"]')).not.toBeNull();
    });

    it('the report shows every section at once (no tabs)', async () => {
        const el = await mount(DashboardView, { dashboard: withDcf(), all: true });

        for (const s of ['overview', 'profit', 'costs', 'value', 'ratios']) expect(el.querySelector(`[data-section="${s}"]`), s).not.toBeNull();
        expect(el.querySelector('[data-tab]')).toBeNull();
    });
});

describe('public page', () => {
    it('offers the dashboard and the statements and nothing else', async () => {
        const statements = { years: [], tabs: { income: [], cash: [], balance: [] }, check: { balanced: true, gap: 0 }, tiles: { revenue: 1, net_profit: 1, net_margin: 1, cash_end: 1 } };
        const el = await mount(Shared, { project: { name: 'Plant A', currency: 'EGP', intro: '' }, link: { name: 'Bank' }, ready: true, comments: { one: 'Steady.' }, dashboard: withDcf(), statements });

        expect(el.querySelectorAll('[data-view]').length).toBe(2);
        expect(el.textContent).toContain('Steady.');
        expect(el.querySelector('input, textarea')).toBeNull();
        el.querySelector('[data-view="statements"]').click();
        await nextTick();
        expect(el.querySelector('[data-kpi]')).toBeNull();
    });
});
