import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createApp, h, nextTick } from 'vue';

vi.mock('@inertiajs/vue3', async () => {
    const vue = await import('vue');
    const passthrough = (tag) => vue.defineComponent({ props: ['href', 'as'], setup: (p, { slots, attrs }) => () => vue.h(tag, { href: p.href, ...attrs }, slots.default?.()) });
    return {
        Head: vue.defineComponent({ setup: () => () => null }),
        Link: passthrough('a'),
        router: { post() {}, on() {} },
        usePage: () => ({ props: { auth: { user: { role: 'company_admin', name: 'Mona Ali', email: 'm@x.test' } }, flash: {}, locale: 'en' } }),
        useForm: (data) => vue.reactive({ ...JSON.parse(JSON.stringify(data)), errors: globalThis.__errors ?? {}, hasErrors: Object.keys(globalThis.__errors ?? {}).length > 0, processing: false,
            put(url) { globalThis.__put = { url, data: JSON.parse(JSON.stringify(this)) }; }, post() {}, delete() {}, clearErrors() {}, reset() {}, transform() { return this; } }),
    };
});
vi.mock('@/pwa/register', () => ({ applyUpdate() {}, updateReady: { value: false }, registerServiceWorker() {} }));
globalThis.route = (name, p) => `/${name}${Array.isArray(p) ? '/' + p.join('/') : p ? `/${p}` : ''}`;

const { default: Assets } = await import('@/Pages/FixedAssets/Edit.vue');
const { setLocale } = await import('@/lang/i18n');

const render = async (props) => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp({ render: () => h(Assets, props) });
    app.config.globalProperties.route = globalThis.route;
    app.mount(el);
    await nextTick();
    return el;
};
const click = async (el) => { el.click(); await nextTick(); };
const type = async (input, value) => { input.value = value; input.dispatchEvent(new Event('input')); await nextTick(); };
const modal = () => document.querySelector('.modal.md');

const row = (over = {}) => ({
    id: 5, name: 'Press', counts: 2, amount: 1000, depreciation_duration: 5, start: '2026-01', end: '2026-03',
    admin_depreciation_percentage: 20, replacement_cost_rate: 0, replacement_cost_interval: 1,
    payment_terms: 'cash', custom: [{ due_days: 0, rate: 100, from_total: false }],
    reservation_rate: 0, contractual_rate: 0, after_months: 0, installment_grace_period: 0, installment_count: 1, payment_installment_interval: 'monthly',
    equity_funding_rate: 40, interest_rate: 12, grace_period: 3, tenor: 24, installment_interval: 'monthly',
    is_as_revenue_percentages: true, allocations: { 1: 0, 2: 0 }, ...over,
});
const props = (over = {}) => ({
    project: { id: 7, name: 'Plant A', startYear: 2026, endYear: 2027 },
    products: [{ id: 1, name: 'Valve' }, { id: 2, name: 'Pump' }],
    rows: [row()],
    summary: { 5: { cost: 2000, loan: 1200, capitalized: 30, transferred: '2026-04', value: 2030 } },
    results: { years: [2026, 2027, 2028, 2029], rows: { 5: { 2026: { depreciation: 100, payments: 2000, net: 1900, loan: 1200 }, 2027: { depreciation: 400, payments: 0, net: -400, loan: 900 }, 2028: { depreciation: 7, payments: 0, net: -7, loan: 0 } } } },
    options: { dueDays: [0, 30, 60], slots: 5, loanIntervals: ['monthly', 'quarterly', 'semi-annually'], paymentIntervals: ['monthly', 'quarterly', 'semi-annually', 'annually'] },
    readOnly: false, ...over,
});

beforeEach(() => { document.body.innerHTML = ''; globalThis.__errors = undefined; globalThis.__put = undefined; });

describe('fixed assets screen', () => {
    it('shows the tiles, the three-line asset and what the calculation found', async () => {
        setLocale('en');
        const el = await render(props());
        expect(el.querySelector('h2').textContent).toBe('Fixed Assets');
        const tiles = [...el.querySelectorAll('.tile b')].map((b) => b.textContent);
        expect(tiles[0]).toBe('1');
        expect(el.querySelector('input[type=text]').value).toBe('Press');
        expect(el.querySelector('.found').textContent).toContain('2,000');
    });

    it('works out the total, the manufacturing share and the bank share without typing them', async () => {
        const el = await render(props());
        const outs = [...el.querySelectorAll('.rep output')].map((o) => o.textContent.trim());
        expect(outs).toContain('2,000');   // 2 x 1000
        expect(outs).toContain('80.00');   // 100 - 20
        expect(outs).toContain('60.00');   // 100 - 40
    });

    it('shows the loan button only when the own funding is below 100%', async () => {
        const el = await render(props({ rows: [row({ equity_funding_rate: 100 })] }));
        expect(el.textContent).not.toContain('12.00% · 24');
        document.body.innerHTML = '';
        const el2 = await render(props());
        expect(el2.textContent).toContain('12.00% · 24');
    });

    it('opens the loan pop-up and saves what was typed', async () => {
        const el = await render(props());
        await click([...el.querySelectorAll('.hirebtn')].find((b) => b.textContent.includes('12.00%')));
        expect(modal()).not.toBeNull();
        await type(modal().querySelector('#ln-tenor'), '36');
        await click(document.querySelector('.modal.md button.btn, .modal.md .btn'));
        el.querySelector('form').dispatchEvent(new Event('submit'));
        await nextTick();
        expect(globalThis.__put.url).toBe('/projects.assets.update/7');
        expect(Number(globalThis.__put.data.rows[0].tenor)).toBe(36);
    });

    it('opens the payments pop-up for customised terms, with a running total', async () => {
        const el = await render(props({ rows: [row({ payment_terms: 'customize', custom: [{ due_days: 0, rate: 40, from_total: false }] })] }));
        await click([...el.querySelectorAll('.hirebtn')].find((b) => b.textContent.includes('1')));
        expect(modal().textContent).toContain('40');
        expect(modal().querySelector('.total.dng')).not.toBeNull();   // 40% is not 100%
    });

    it('lets the user add a new asset and remove one after confirming', async () => {
        const el = await render(props());
        await click([...el.querySelectorAll('button')].find((b) => b.textContent.includes('Add')));
        expect(el.querySelectorAll('.rep').length).toBe(2);
        await click(el.querySelector('.delbtn'));
        expect(document.body.textContent).toContain('Remove');
    });

    it('shows only the study years plus one in the results', async () => {
        const el = await render(props());
        const heads = [...el.querySelectorAll('.stm th')].map((th) => th.textContent.trim());
        expect(heads).toContain('2028');
        expect(heads).not.toContain('2029');
    });

    it('is read-only for a colleague who may only view', async () => {
        const el = await render(props({ readOnly: true }));
        expect(el.querySelector('fieldset').disabled).toBe(true);
        expect(el.textContent).not.toContain('Save and close');
    });

    it('speaks Arabic', async () => {
        setLocale('ar');
        const el = await render(props());
        expect(el.querySelector('h2').textContent).not.toBe('Fixed Assets');
        setLocale('en');
    });
});
