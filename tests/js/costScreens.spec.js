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

const { default: Manpower } = await import('@/Pages/Manpower/Edit.vue');
const { default: Expenses } = await import('@/Pages/Expenses/Edit.vue');
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
const click = async (el) => { el.click(); await nextTick(); };
const type = async (input, value) => { input.value = value; input.dispatchEvent(new Event('input')); await nextTick(); };

// A study that starts in January 2026 and runs 2 years: operation months 0..35 (2026, 2027 and the extra year 2028).
const months = Array.from({ length: 36 }, (_, i) => ({ index: i, year: 2026 + Math.floor(i / 12), month: (i % 12) + 1 }));
const manpower = (over = {}) => ({
    project: { id: 7, name: 'Plant A', newCompany: false, endYear: 2027, increase: 10, taxRate: 5, insurance: 10 },
    types: ['direct_labor', 'manufacturing_overheads', 'operational_salaries', 'sales', 'marketing', 'general'],
    allocated: ['direct_labor', 'manufacturing_overheads'],
    months,
    rows: [{ id: 11, type: 'direct_labor', position: 'Welder', avg_salary: 8500, existing_count: 2, hirings: { 0: 1 } }],
    products: [{ id: 1, name: 'Valve' }, { id: 2, name: 'Pump' }],
    allocations: { direct_labor: { 1: 50, 2: 50 }, manufacturing_overheads: { 1: 50, 2: 50 } },
    byRevenue: { direct_labor: true, manufacturing_overheads: true },
    results: { years: [2026, 2027, 2028], rows: { 11: { 2026: { headcount: 3, payments: 306000, expense: 360000 }, 2027: { headcount: 3, payments: 336600, expense: 396000 }, 2028: { headcount: 3, payments: 370260, expense: 435600 } } } },
    readOnly: false, ...over,
});

beforeEach(() => { document.body.innerHTML = ''; });

const modal = () => document.querySelector('.modal.md');

describe('manpower plan screen', () => {
    it('has one compact table per manpower type, summary tiles and no month grid on the page itself', async () => {
        setLocale('en');
        const el = await render(Manpower, manpower());
        expect(el.querySelector('h2').textContent).toBe('Manpower Plan');
        expect(el.textContent).toContain('Direct labour');
        expect(el.textContent).toContain('General and administrative staff');
        expect(el.querySelectorAll('.tile').length).toBe(4);
        expect(el.querySelectorAll('table.plan tbody tr').length).toBe(1);
        expect(el.querySelector('table.hire')).toBeNull();
        expect(el.querySelector('.hirebtn').textContent).toContain('1 to hire');
    });

    it('opens the hirings pop-up with the months of each year and the extra year', async () => {
        setLocale('en');
        const el = await render(Manpower, manpower());
        await click(el.querySelector('.hirebtn'));
        expect(modal()).not.toBeNull();
        expect(modal().textContent).toContain('Hirings: Welder');
        expect(modal().querySelectorAll('table.hire tbody tr').length).toBe(3);
        expect(modal().querySelector('table.hire').textContent).toContain('extra year after the study');
        expect(modal().querySelectorAll('table.hire tbody tr:first-child .cin').length).toBe(12);
        await click([...modal().querySelectorAll('.acts .btn')].pop());
        expect(modal()).toBeNull();
    });

    it('copies a typed hiring into the months after it, the last month has no dots, and the button shows the total', async () => {
        setLocale('en');
        const el = await render(Manpower, manpower());
        await click(el.querySelector('.hirebtn'));
        const cells = modal().querySelectorAll('table.hire tbody tr:first-child .cin');
        await type(cells[2].querySelector('input'), '4');
        await click(cells[2].querySelector('.cin-dots'));
        const all = [...modal().querySelectorAll('table.hire .cin input')].map((i) => i.value);
        expect(all[0]).toBe('1');
        expect(all[1]).toBe('');
        expect(all.slice(2).every((v) => v === '4')).toBe(true);
        expect([...modal().querySelectorAll('table.hire .cin')].pop().querySelector('.cin-dots')).toBeNull();
        expect(modal().textContent).toContain('Total hired: 137');
        await click([...modal().querySelectorAll('.acts .btn')].pop());
        expect(el.querySelector('.hirebtn').textContent).toContain('137 to hire');
    });

    it('locks the existing count for a new company', async () => {
        setLocale('en');
        const el = await render(Manpower, manpower({ project: { id: 7, name: 'Plant A', newCompany: true, endYear: 2027, increase: 0, taxRate: 0, insurance: 0 } }));
        expect(el.querySelector('.c-cnt input').disabled).toBe(true);
    });

    it('adds a position to the chosen type', async () => {
        setLocale('en');
        const el = await render(Manpower, manpower());
        const sales = el.querySelector('[data-type="sales"]');
        expect(sales.querySelector('table.plan')).toBeNull();
        await click(sales.querySelector('button.btn-line.sm'));
        expect(sales.querySelectorAll('table.plan tbody tr').length).toBe(1);
        expect(sales.querySelector('.hirebtn').textContent).toContain('Add hirings');
    });

    it('asks for product shares only when the cost is not shared by sales', async () => {
        setLocale('en');
        const el = await render(Manpower, manpower());
        expect(el.querySelector('input[id="mp-direct_labor-1"]')).toBeNull();
        const box = el.querySelector('.share input[type="checkbox"]');
        box.checked = false;
        box.dispatchEvent(new Event('change'));
        await nextTick();
        expect(el.querySelector('input[id="mp-direct_labor-1"]')).not.toBeNull();
    });

    it('opens a type in the results and changes the measure', async () => {
        setLocale('en');
        const el = await render(Manpower, manpower());
        const resultsTable = [...el.querySelectorAll('table.stm')].pop();
        expect(resultsTable.textContent).toContain('360,000');
        await click(resultsTable.querySelector('.rowtoggle'));
        expect(resultsTable.querySelector('.subrow').textContent).toContain('Welder');
        const measures = resultsTable.parentElement.querySelectorAll('.seg button');
        await click(measures[2]);
        expect(resultsTable.textContent).toContain('3.0');
    });

    it('sends the typed rows, with their hirings, when saved', async () => {
        setLocale('en');
        const el = await render(Manpower, manpower());
        el.querySelector('form').dispatchEvent(new Event('submit'));
        await nextTick();
        expect(globalThis.__put.url).toBe('/projects.manpower.update/7');
        expect(globalThis.__put.data.rows[0].position).toBe('Welder');
        expect(globalThis.__put.data.rows[0].hirings).toEqual({ 0: 1 });
        expect(globalThis.__put.data.salaries_tax_rate).toBe(5);
    });

    it('works in Arabic', async () => {
        setLocale('ar');
        const el = await render(Manpower, manpower());
        expect(el.textContent).toContain('العمالة المباشرة');
        expect(el.querySelector('h2').textContent).toBe('خطة العمالة');
        setLocale('en');
    });
});

const expenses = (over = {}) => ({
    project: { id: 7, name: 'Plant A', newCompany: false, startYear: 2026, endYear: 2027 },
    categories: [{ id: 'manufacturing-expenses', allocated: true }, { id: 'marketing', allocated: false }],
    kinds: ['fixed_monthly_repeating_amount', 'one_time_expense', 'expense_as_percentage', 'cost_per_unit'],
    products: [{ id: 1, name: 'Valve' }, { id: 2, name: 'Pump' }],
    rows: [{
        id: 5, relation_name: 'fixed_monthly_repeating_amount', category_id: 'manufacturing-expenses', name: 'Rent', amount: 10000, start: '2026-01', end: '2027-12',
        increase_rate: 10, monthly_percentage: 0, monthly_cost_of_unit: 0, amortization_months: 12, products: [1, 2], payment_terms: 'cash',
        custom: [{ due_days: '', rate: '' }], is_as_revenue_percentages: true, allocations: { 1: 0, 2: 0 },
    }],
    results: { years: [2026, 2027, 2028], rows: { 5: { 2026: { expense: 120000, payments: 120000 }, 2027: { expense: 132000, payments: 132000 } } } },
    options: { dueDays: [0, 15, 30, 60], slots: 5 }, readOnly: false, ...over,
});

describe('expenses screen', () => {
    it('has a section for each kind of expense and the saved expense is in the right one', async () => {
        setLocale('en');
        const el = await render(Expenses, expenses());
        const titles = [...el.querySelectorAll('section.card h3')].map((h) => h.textContent.trim());
        expect(titles).toEqual(['Fixed monthly expenses', 'One-time expenses', 'Expenses as a % of sales', 'Cost per unit sold', 'Calculated results']);
        expect(el.querySelector('input[id="ex-0-name"]').value).toBe('Rent');
    });

    it('opens a pop-up for customised payment terms with a repeater and a running total', async () => {
        setLocale('en');
        const el = await render(Expenses, expenses());
        expect(el.querySelector('.hirebtn')).toBeNull();
        const select = el.querySelector('select[id="ex-0-terms"]');
        select.value = 'customize';
        select.dispatchEvent(new Event('change'));
        await nextTick();
        const m = modal();
        expect(m).not.toBeNull();
        expect(m.querySelectorAll('.customRow').length).toBe(1);
        expect(m.querySelector('.total').classList.contains('dng')).toBe(true);
        await click([...m.querySelectorAll('button')].find((b) => b.textContent.includes('Add a payment')));
        expect(m.querySelectorAll('.customRow').length).toBe(2);
        const rates = m.querySelectorAll('.customRow input');
        await type(rates[0], '60');
        await type(rates[1], '40');
        const days = m.querySelectorAll('.customRow select');
        days[0].value = '0'; days[0].dispatchEvent(new Event('change'));
        days[1].value = '30'; days[1].dispatchEvent(new Event('change'));
        await nextTick();
        expect(m.querySelector('.total').classList.contains('dng')).toBe(false);
        await click([...m.querySelectorAll('.acts .btn')].pop());
        expect(modal()).toBeNull();
        expect(el.querySelector('.hirebtn').textContent).toContain('2 payment(s)');
    });

    it('calls the one-time expense "Amortised over"', async () => {
        setLocale('en');
        const el = await render(Expenses, expenses({ rows: [{ ...expenses().rows[0], id: 6, relation_name: 'one_time_expense', name: 'Licence' }] }));
        expect(el.querySelector('label[for="ex-0-am"]').textContent).toBe('Amortised over (months)');
        expect(el.textContent).not.toContain('Spread over');
    });

    it('offers the product share only for the allocated categories', async () => {
        setLocale('en');
        const el = await render(Expenses, expenses());
        expect(el.textContent).toContain('Share this expense between the products according to their sales');
        const category = el.querySelector('select[id="ex-0-cat"]');
        category.value = 'marketing';
        category.dispatchEvent(new Event('change'));
        await nextTick();
        expect(el.textContent).not.toContain('Share this expense between the products according to their sales');
    });

    it('adds a new line to the chosen section with the whole study as its months', async () => {
        setLocale('en');
        const el = await render(Expenses, expenses());
        const sections = el.querySelectorAll('section.card');
        await click(sections[2].querySelector('button.btn-line.sm'));
        expect(el.querySelector('input[id="ex-1-pct"]')).not.toBeNull();
        expect(el.querySelectorAll('.chips input[type="checkbox"]').length).toBe(2);
    });

    it('lists categories in the results and opens them', async () => {
        setLocale('en');
        const el = await render(Expenses, expenses());
        const table = [...el.querySelectorAll('table.stm')].pop();
        expect(table.textContent).toContain('120,000');
        await click(table.querySelector('.rowtoggle'));
        expect(table.querySelector('.subrow').textContent).toContain('Rent');
    });
});
