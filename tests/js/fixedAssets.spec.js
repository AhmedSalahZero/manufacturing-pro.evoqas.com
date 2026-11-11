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

    // ── The four cards: land, construction, machines, other ──

    const machine = (over = {}) => row({
        id: 8, name: 'Production line', asset_type: 'machine', start: '2026-01', delivery: '2026-07', end: '2026-09', depreciation_duration: 10,
        payment_terms: 'milestone', equity_funding_rate: 100,
        milestones: { purchase: { rate: 50, days: 0 }, delivery: { rate: 30, days: 0 }, ready: { rate: 20, days: 0 } }, ...over,
    });
    const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
    const chooseTerms = async (rep, value) => {
        const terms = rep.querySelector('select[id$="-terms"]');
        terms.value = value;
        terms.dispatchEvent(new Event('change'));
        await tick();
    };

    it('has one card for each kind of asset, and an asset sits in the card of its kind', async () => {
        const el = await render(props({ rows: [row(), machine()] }));
        const cards = [...el.querySelectorAll('.assetcard')];
        expect(cards.map((c) => c.dataset.kindCard)).toEqual(['land', 'construction', 'machine', 'other']);
        expect(cards.map((c) => c.querySelector('h3').textContent)).toEqual(['Land', 'Construction', 'Machines and equipment', 'Other fixed assets']);
        expect(el.querySelectorAll('[data-kind-card=land] .rep').length).toBe(0);
        expect(el.querySelector('[data-kind-card=construction] .rep input[type=text]').value).toBe('Press');   // an old asset is a construction
        expect(el.querySelector('[data-kind-card=machine] .rep input[type=text]').value).toBe('Production line');
        expect(el.querySelector('[data-kind-card=machine] [data-milestones]').textContent).toContain('50% · 30% · 20%');
    });

    it('land has a single purchase date and no depreciation', async () => {
        const el = await render(props({ rows: [] }));
        await click(el.querySelector('[data-add=land]'));
        const land = el.querySelector('.rep[data-kind=land]');
        expect(land.textContent).toContain('Purchase date');
        expect(land.textContent).not.toContain('End date');
        expect(land.textContent).not.toContain('Depreciation');
        expect(land.querySelectorAll('.myi').length).toBe(1);
        el.querySelector('form').dispatchEvent(new Event('submit'));
        await nextTick();
        expect(globalThis.__put.data.rows[0].asset_type).toBe('land');
        expect(Number(globalThis.__put.data.rows[0].depreciation_duration)).toBe(0);
    });

    it('another asset has a single purchase date and is depreciated', async () => {
        const el = await render(props({ rows: [] }));
        await click(el.querySelector('[data-add=other]'));
        const other = el.querySelector('.rep[data-kind=other]');
        expect(other.textContent).toContain('Purchase date');
        expect(other.querySelectorAll('.myi').length).toBe(1);
        expect(other.textContent).toContain('Depreciation');
    });

    it('a construction has a start and an end date, a spending curve and a down payment ready on the total price', async () => {
        const el = await render(props({ rows: [] }));
        await click(el.querySelector('[data-add=construction]'));
        const hall = el.querySelector('.rep[data-kind=construction]');
        expect(hall.textContent).toContain('Start date');
        expect(hall.textContent).toContain('End date');
        expect(hall.querySelectorAll('.myi').length).toBe(2);
        const curves = [...hall.querySelectorAll('select[id$="-curve"] option')].map((o) => o.value);
        expect(curves).toEqual(['flat', 's_curve']);

        await chooseTerms(hall, 'customize');
        expect(modal().textContent).toContain('Taken from');
        const first = modal().querySelectorAll('.customRow')[0].querySelectorAll('select');
        expect(first[0].value).toBe('0');       // paid in the same month
        expect(first[1].value).toBe('true');    // taken from the total price: the down payment
    });

    it('each payment line of a construction keeps its own Taken from', async () => {
        const custom = [{ due_days: 0, rate: 25, from_total: true }, { due_days: 30, rate: 50, from_total: false }, { due_days: 60, rate: 25, from_total: false }];
        const el = await render(props({ rows: [row({ payment_terms: 'customize', custom })] }));
        await click([...el.querySelectorAll('.hirebtn')].find((b) => b.textContent.includes('3')));
        const basis = [...modal().querySelectorAll('.customRow')].map((r) => r.querySelectorAll('select')[1]);
        expect(basis.map((b) => b.disabled)).toEqual([false, false, false]);
        expect(basis.map((b) => b.value)).toEqual(['true', 'false', 'false']);
        expect(modal().querySelectorAll('.xbtn').length).toBe(3);   // every line can be removed
        expect(modal().querySelector('.total.okc')).not.toBeNull();     // 25 + 50 + 25
    });

    it('a machine has a purchase, a delivery and a ready date and can be paid by milestones', async () => {
        const el = await render(props({ rows: [] }));
        await click(el.querySelector('[data-add=machine]'));
        const line = el.querySelector('.rep[data-kind=machine]');
        expect(line.textContent).toContain('Purchase date');
        expect(line.textContent).toContain('Delivery date');
        expect(line.textContent).toContain('Date to be ready');
        expect(line.querySelectorAll('.myi').length).toBe(3);
        const terms = [...line.querySelectorAll('select[id$="-terms"] option')].map((o) => o.value);
        expect(terms).toEqual(['cash', 'customize', 'installment', 'milestone']);

        // The other kinds have no milestones.
        await click(el.querySelector('[data-add=other]'));
        const other = [...el.querySelectorAll('.rep[data-kind=other] select[id$="-terms"] option')].map((o) => o.value);
        expect(other).toEqual(['cash', 'customize', 'installment']);
    });

    it('Taken from exists only for a construction: a machine counts its days from the purchase', async () => {
        const custom = [{ due_days: 0, rate: 40, from_total: false }, { due_days: 60, rate: 60, from_total: false }];
        const el = await render(props({ rows: [machine({ payment_terms: 'customize', custom })] }));
        await click([...el.querySelectorAll('.hirebtn')].find((b) => b.textContent.includes('2')));
        expect(modal().textContent).not.toContain('Taken from');
        expect(modal().querySelectorAll('.customRow')[0].querySelectorAll('select').length).toBe(1);
        expect(modal().textContent).toContain('purchase date');
    });

    it('the milestone pop-up shows the three payments with a running total and saves what was typed', async () => {
        const el = await render(props({ rows: [] }));
        await click(el.querySelector('[data-add=machine]'));
        const line = el.querySelector('.rep[data-kind=machine]');
        await type(line.querySelector('input[type=text]'), 'Production line');
        await chooseTerms(line, 'milestone');

        const rows = [...modal().querySelectorAll('.customRow[data-milestone]')];
        expect(rows.map((r) => r.dataset.milestone)).toEqual(['purchase', 'delivery', 'ready']);
        expect(rows[0].textContent).toContain('Down payment at purchase date');
        expect(rows[2].textContent).toContain('After installation');
        expect(modal().querySelector('.total.dng')).not.toBeNull();   // nothing typed yet

        await type(rows[0].querySelector('input'), '50');
        await type(rows[1].querySelector('input'), '30');
        await type(rows[2].querySelector('input'), '20');
        expect(modal().querySelector('.total.okc')).not.toBeNull();
        expect(modal().querySelector('.total').textContent).toContain('100');

        await click(document.querySelector('.modal.md .acts .btn'));
        expect(line.querySelector('[data-milestones]').textContent).toContain('50% · 30% · 20%');

        el.querySelector('form').dispatchEvent(new Event('submit'));
        await nextTick();
        const saved = globalThis.__put.data.rows[0];
        expect(saved.asset_type).toBe('machine');
        expect(saved.payment_terms).toBe('milestone');
        expect(Number(saved.milestones.delivery.rate)).toBe(30);
        expect(saved.delivery).toBe(`${2026}-01`);
    });

    it('moving the purchase date of a machine later moves its delivery and ready dates with it', async () => {
        const el = await render(props({ rows: [machine()] }));
        const month = (field) => el.querySelector(`.rep[data-kind=machine] [data-date=${field}] select`);
        expect(month('start').value).toBe('1');

        month('start').value = '8';   // August 2026: after the delivery (July) and the ready date (September is later, so it stays)
        month('start').dispatchEvent(new Event('change'));
        await tick();
        await tick();
        expect(month('delivery').value).toBe('8');
        expect(month('end').value).toBe('9');
    });

    it('moving the start of a construction past its end moves the end with it', async () => {
        const el = await render(props({ rows: [row({ start: '2026-01', end: '2026-03' })] }));
        const month = (field) => el.querySelector(`.rep[data-kind=construction] [data-date=${field}] select`);

        month('start').value = '6';
        month('start').dispatchEvent(new Event('change'));
        await tick();
        await tick();
        expect(month('end').value).toBe('6');
    });

    it('shows the errors of a machine next to its dates and payments', async () => {
        globalThis.__errors = { 'rows.0.delivery': 'The delivery month cannot be before the purchase month or after the month the asset is ready.', 'rows.0.milestones': 'The milestone payments must add up to 100%.' };
        const el = await render(props({ rows: [machine()] }));
        const line = el.querySelector('.rep[data-kind=machine]');
        expect(line.querySelector('[data-date=delivery]').textContent).toContain('delivery month cannot be before');
        expect(line.textContent).toContain('milestone payments must add up');
        expect(line.querySelector('[data-milestones]').classList.contains('bad')).toBe(true);
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
