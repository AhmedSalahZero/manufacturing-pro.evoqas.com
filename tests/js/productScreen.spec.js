import { describe, expect, it, vi } from 'vitest';
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

const { default: Edit } = await import('@/Pages/Products/Edit.vue');
const { default: Payments } = await import('@/Pages/RawMaterials/Payments.vue');
const { setLocale } = await import('@/lang/i18n');

const render = async (component, props) => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp({ render: () => h(component, props) });
    app.config.globalProperties.route = globalThis.route;
    app.mount(el);
    await nextTick();
    return el;
};

const years = [0, 1, 2].map((i) => ({ index: i, year: 2026 + i }));
const by = (v) => Object.fromEntries([0, 1, 2].map((i) => [i, v]));
const policy = () => [{ cash_payment: 100, rate: [0, 0], due_in_days: [30, 60] }, { cash_payment: 100, rate: [0, 0], due_in_days: [30, 60] }];
const props = (over = {}) => ({
    project: { id: 7, name: 'Plant A', new_company: false, duration: 3, periods: 2 },
    product: { id: 4, name: 'Valve', measurement_unit: 'piece', selling_start: '2026-01', vat_rate: 14, withhold_rate: 1, comment: null },
    years,
    sales: { max_capacity: { 0: 6000, 1: 6600, 2: 6600 }, target_percentages: by(50), local_target_quantity_percentages: by(80), local_growth_rates: by(10), local_price_per_unit: { 0: 60000, 1: 66000, 2: 72600 }, export_growth_rates: by(10), export_price_per_unit: by(50000) },
    seasonality: { type: 'flat', quarterly: [25, 25, 25, 25], monthly: [8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.34, 8.34, 8.34, 8.34] },
    inventory: { quantity: 1500, value: 9000000, coverage: 75, breakdowns: { raw_material_value: 50, direct_labor_value: 30, manufacturing_overheads_value: 20 } },
    policy: { local: policy(), export: policy() },
    recipe: [{ raw_material_id: 2, percentages: by(30) }],
    rawMaterials: [{ id: 2, name: 'Steel', measurement_unit: 'ton' }, { id: 3, name: 'Paint', measurement_unit: 'kg' }],
    nextProduct: 5, results: [{ year: 2026, sales_value: 168000000, sales_quantity: 2880, made_quantity: 2400, closing_inventory: 600 }],
    readOnly: false, options: { dueDays: [15, 30, 45, 60], coverageDays: [0, 15, 30, 75] }, ...over,
});


const rowOf = (el, label) => [...el.querySelectorAll('tbody tr')].find((tr) => tr.firstElementChild?.textContent.trim() === label);
const click = async (el) => { el.click(); await nextTick(); };

describe('product screen: copy buttons, side-by-side terms and expandable results', () => {
    it('has a three-dots button on every year cell except the last one, and it copies to the cells after it', async () => {
        setLocale('en');
        const el = await render(Edit, props());
        const capacityCells = rowOf(el, 'Maximum capacity').querySelectorAll('td .cin');
        expect(capacityCells.length).toBe(3);
        expect(capacityCells[0].querySelector('.cin-dots')).not.toBeNull();
        expect(capacityCells[2].querySelector('.cin-dots')).toBeNull();

        const first = capacityCells[0].querySelector('input');
        first.value = '9000';
        first.dispatchEvent(new Event('input'));
        await nextTick();
        await click(capacityCells[0].querySelector('.cin-dots'));

        const after = [...rowOf(el, 'Maximum capacity').querySelectorAll('td .cin input')].map((i) => i.value);
        expect(after).toEqual(['9000', '9000', '9000']);
    });

    it('copying a price makes the following years the same price with 0 % growth', async () => {
        const el = await render(Edit, props());
        const row = rowOf(el, 'Local price per unit');
        await click(row.querySelector('.cin-dots'));
        expect([...row.querySelectorAll('input')].map((i) => i.value)).toEqual(['60000', '60000', '60000']);
        const growth = rowOf(el, 'Local price growth %');
        expect([...growth.querySelectorAll('input')].map((i) => i.value)).toEqual(['0', '0']);
    });

    it('says the raw material figure is a percentage of the selling price', async () => {
        const el = await render(Edit, props());
        expect(el.innerHTML).toContain('% of selling price');
        expect(el.querySelector('.cin-suffix')?.textContent).toBe('%');
    });

    it('puts the first-year and following-years terms side by side for local and export', async () => {
        const el = await render(Edit, props());
        const pairs = el.querySelectorAll('.pair');
        expect(pairs.length).toBe(2);
        pairs.forEach((p) => {
            expect(p.querySelectorAll('.terms').length).toBe(2);
            expect(p.querySelectorAll('.terms.later').length).toBe(1);
        });
    });

    it('calls the column Manufactured Units, and shows local and export sales only when a year is opened', async () => {
        const el = await render(Edit, props({ results: [{ year: 2026, sales_value: 174000000, local_value: 144000000, export_value: 30000000, sales_quantity: 3000, local_quantity: 2400, export_quantity: 600, made_quantity: 2400, closing_inventory: 600 }] }));
        expect(el.innerHTML).toContain('Manufactured Units');
        expect(el.innerHTML).not.toContain('Units to make');
        expect(el.querySelectorAll('.subrow').length).toBe(0);

        await click(el.querySelector('.rowtoggle'));
        const rows = [...el.querySelectorAll('.subrow')];
        expect(rows.length).toBe(2);
        expect(rows[0].textContent).toContain('Local sales');
        expect(rows[0].textContent).toContain('144,000,000');
        expect(rows[1].textContent).toContain('Export sales');
        expect(rows[1].textContent).toContain('30,000,000');

        await click(el.querySelector('.rowtoggle'));
        expect(el.querySelectorAll('.subrow').length).toBe(0);
    });

    it('can open and close every year at once', async () => {
        const el = await render(Edit, props({ results: [2026, 2027].map((year) => ({ year, sales_value: 1, local_value: 1, export_value: 0, sales_quantity: 1, local_quantity: 1, export_quantity: 0, made_quantity: 1, closing_inventory: 0 })) }));
        const all = [...el.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Expand all');
        await click(all);
        expect(el.querySelectorAll('.subrow').length).toBe(4);
        const none = [...el.querySelectorAll('button')].find((b) => b.textContent.trim() === 'Collapse all');
        await click(none);
        expect(el.querySelectorAll('.subrow').length).toBe(0);
    });

    it('also shows the raw material payment terms side by side', async () => {
        const policyRows = () => [{ cash_payment: 50, rate: [50, 0], due_in_days: [30, 60] }, { cash_payment: 0, rate: [100, 0], due_in_days: [60, 90] }];
        const el = await render(Payments, {
            project: { id: 7, name: 'Plant A', periods: 2, comment: null },
            materials: [{ id: 2, name: 'Steel', measurement_unit: 'ton', vat_rate: 14, withhold_rate: 0, coverage_days: 30, policy: policyRows() }, { id: 3, name: 'Paint', measurement_unit: 'kg', vat_rate: 14, withhold_rate: 0, coverage_days: 0, policy: policyRows() }],
            lastProduct: 4, readOnly: false, options: { dueDays: [15, 30, 45, 60, 90] },
        });
        const pairs = el.querySelectorAll('.pair');
        expect(pairs.length).toBe(2);
        pairs.forEach((p) => {
            expect(p.querySelectorAll('.terms').length).toBe(2);
            expect(p.querySelectorAll('.terms.later').length).toBe(1);
        });
    });
});
