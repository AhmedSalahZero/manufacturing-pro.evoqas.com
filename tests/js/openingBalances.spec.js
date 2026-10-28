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

const { default: Opening } = await import('@/Pages/OpeningBalances/Edit.vue');
const { setLocale } = await import('@/lang/i18n');

const render = async (props) => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp({ render: () => h(Opening, props) });
    app.config.globalProperties.route = globalThis.route;
    app.mount(el);
    await nextTick();
    return el;
};
const click = async (el) => { el.click(); await nextTick(); };
const type = async (input, value) => { input.value = value; input.dispatchEvent(new Event('input')); await nextTick(); };
const modal = () => document.querySelector('.modal.md');

// A 2-year study from January 2026 (months 0..23).
const months = Array.from({ length: 24 }, (_, i) => ({ index: i, year: 2026 + Math.floor(i / 12), month: (i % 12) + 1 }));
const data = (over = {}) => ({
    fixed_assets: [{ id: 3, name: 'Press', gross_amount: 120000, accumulated_depreciation: 30000, monthly_depreciation: 1000, monthly_counts: 24, admin_depreciation_percentage: 20, is_as_revenue_percentages: true, allocations: { 1: 0, 2: 0 } }],
    cash: { cash_and_bank_amount: 10000, customer_receivable_amount: 50000, settlements: { 1: 50000 } },
    other_debtors: [], suppliers: { amount: 20000, settlements: {} }, other_creditors: [],
    taxes: { vat_amount: 0, credit_withhold_taxes: 0, corporate_taxes_payable: 0 },
    loans: [{ id: 4, amount: 30000, installments: {}, interests: {} }], other_long_term: [],
    equity: { paid_up_capital_amount: 70000, legal_reserve: 0, retained_earnings: 0 },
    ...over,
});
const props = (over = {}) => ({
    project: { id: 7, name: 'Plant A', startYear: 2026, endYear: 2027 },
    months, products: [{ id: 1, name: 'Valve' }, { id: 2, name: 'Pump' }],
    data: data(), inventory: 20000, readOnly: false, ...over,
});

beforeEach(() => { document.body.innerHTML = ''; globalThis.__errors = undefined; globalThis.__put = undefined; });

describe('opening balances screen', () => {
    it('shows three cards and the balance tiles', async () => {
        setLocale('en');
        const el = await render(props());
        expect(el.querySelector('h2').textContent).toBe('Opening Balances');
        expect(el.querySelectorAll('section.card').length).toBe(3);
        const tiles = [...el.querySelectorAll('.tile b')].map((b) => b.textContent);
        // assets: net 90,000 + cash 10,000 + receivables 50,000 + inventory 20,000 = 170,000
        expect(tiles[0]).toBe('170,000');
        // liabilities and equity: 20,000 + 30,000 + 70,000 = 120,000
        expect(tiles[1]).toBe('120,000');
        expect(tiles[2]).toBe('-50,000');
    });

    it('says Balanced when assets equal liabilities plus equity', async () => {
        const el = await render(props({ data: data({ equity: { paid_up_capital_amount: 120000, legal_reserve: 0, retained_earnings: 0 } }) }));
        expect(el.querySelector('.okTile').textContent).toContain('Balanced');
    });

    it('works out the net amount and the manufacturing share', async () => {
        const el = await render(props());
        const outs = [...el.querySelectorAll('.rep output')].map((o) => o.textContent.trim());
        expect(outs).toContain('90,000');
        expect(outs).toContain('80.00');
    });

    it('updates the difference as numbers are typed', async () => {
        const el = await render(props());
        await type(el.querySelector('#eq-paid'), '120000');
        expect(el.querySelector('.okTile')).not.toBeNull();
    });

    it('opens the months pop-up and saves what was typed by month', async () => {
        const el = await render(props());
        const buttons = [...el.querySelectorAll('.hirebtn')];
        await click(buttons.find((b) => b.textContent.includes('1 month')));
        expect(modal()).not.toBeNull();
        expect(modal().querySelectorAll('.cin').length).toBe(24);
        expect(modal().textContent).toContain('Entered 50,000 of 50,000');
        await type(modal().querySelectorAll('.cin input')[2], '1500');
        el.querySelector('form').dispatchEvent(new Event('submit'));
        await nextTick();
        expect(globalThis.__put.url).toBe('/projects.opening.update/7');
        expect(Number(globalThis.__put.data.cash.settlements[2])).toBe(1500);
    });

    it('spreads a balance evenly over some months', async () => {
        const el = await render(props({ data: data({ suppliers: { amount: 1000, settlements: {} } }) }));
        const supplier = [...el.querySelectorAll('.hirebtn')].find((b) => b.textContent.includes('Set months') && b.closest('.line')?.querySelector('#sup-amount'));
        await click(supplier);
        await type(modal().querySelector('.spread input'), '3');
        await click([...modal().querySelectorAll('.spread button')].find((b) => b.textContent.includes('Fill in')));
        const values = [...modal().querySelectorAll('.cin input')].map((i) => i.value).filter((v) => v !== '');
        expect(values).toEqual(['333.33', '333.33', '333.34']);
    });

    it('adds and removes lines', async () => {
        const el = await render(props());
        await click([...el.querySelectorAll('button')].find((b) => b.textContent.includes('Add a debtor')));
        expect(el.querySelectorAll('[data-section="other_debtors"] .line').length).toBe(1);
        await click(el.querySelector('[data-section="other_debtors"] .delbtn'));
        expect(document.body.textContent).toContain('Remove this line?');
    });

    it('is read-only for a colleague who may only view', async () => {
        const el = await render(props({ readOnly: true }));
        expect(el.querySelector('fieldset').disabled).toBe(true);
        expect(el.textContent).not.toContain('Save and close');
    });

    it('speaks Arabic', async () => {
        setLocale('ar');
        const el = await render(props());
        expect(el.querySelector('h2').textContent).not.toBe('Opening Balances');
        setLocale('en');
    });
});
