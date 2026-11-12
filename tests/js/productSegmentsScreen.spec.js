import { afterEach, describe, expect, it, vi } from 'vitest';
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
            put(url, options) { globalThis.__put = { url, options, data: JSON.parse(JSON.stringify(this)) }; }, post() {}, delete() {}, clearErrors() {}, reset() {}, transform() { return this; } }),
    };
});
vi.mock('@/pwa/register', () => ({ applyUpdate() {}, updateReady: { value: false }, registerServiceWorker() {} }));
globalThis.route = (name, p) => `/${name}${Array.isArray(p) ? '/' + p.join('/') : p ? `/${p}` : ''}`;

const { default: Edit } = await import('@/Pages/Products/Edit.vue');
const { setLocale } = await import('@/lang/i18n');

const render = async (props) => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp({ render: () => h(Edit, props) });
    app.config.globalProperties.route = globalThis.route;
    app.mount(el);
    await nextTick();
    return el;
};

const Y = [0, 1, 2];
const years = Y.map((i) => ({ index: i, year: 2026 + i }));
const by = (v) => Object.fromEntries(Y.map((i) => [i, v]));
const policy = () => [{ cash_payment: 100, rate: [0, 0], due_in_days: [30, 60] }, { cash_payment: 100, rate: [0, 0], due_in_days: [30, 60] }];

// The agreed example: capacity 12,000, half local / half export, base price 10,000, 1 USD = 50, 1 EUR = 55.
const channel = (id, name, share, difference) => ({ sales_channel_id: id, name, allocation_percentages: by(share), price_differences: by(difference), uses_own_terms: false, collection_policy_value: policy() });
const market = (id, name, currency, share, price) => ({ export_market_id: id, name, currency, allocation_percentages: by(share), first_foreign_price: price, foreign_growth_rates: by(0), uses_own_terms: false, collection_policy_value: policy() });
const segments = () => ({
    local: [channel(1, 'Retail', 50, 10), channel(2, 'Wholesale', 30, 0), channel(3, 'Distributors', 20, -5)],
    export: [market(1, 'Saudi Arabia', 'usd', 50, 230), market(2, 'UAE', 'usd', 30, 220), market(3, 'Italy', 'euro', 20, 190)],
});
const settings = (over = {}) => ({ enabled: true, hasExport: true, currency: 'egp', channels: [], markets: [], fx: { usd: { 0: 50, 1: 54, 2: 58 }, euro: { 0: 55, 1: 58, 2: 61 } }, ...over });
const pattern = () => ({ type: 'flat', quarterly: [25, 25, 25, 25], monthly: [8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.33, 8.34, 8.34, 8.34, 8.34] });

const props = (over = {}) => ({
    project: { id: 7, name: 'Plant A', new_company: true, duration: 3, periods: 2 },
    product: { id: 4, name: 'Valve', measurement_unit: 'ton', selling_start: '2026-01', vat_rate: 14, withhold_rate: 1, comment: null },
    years,
    sales: { max_capacity: by(12000), target_percentages: by(100), local_target_quantity_percentages: by(50), local_growth_rates: by(0), local_price_per_unit: by(10000), export_growth_rates: by(0), export_price_per_unit: by(0) },
    seasonality: pattern(), salesSettings: settings(), segments: segments(), exportSeasonality: pattern(),
    inventory: { quantity: 0, value: 0, coverage: 30, breakdowns: { raw_material_value: 100, direct_labor_value: 0, manufacturing_overheads_value: 0 } },
    policy: { local: policy(), export: policy() }, recipe: [], rawMaterials: [], nextProduct: null, results: [],
    readOnly: false, options: { dueDays: [15, 30, 45, 60], coverageDays: [0, 30] }, ...over,
});

const click = async (el) => { el.click(); await nextTick(); };
const button = (el, text) => [...el.querySelectorAll('button')].find((b) => b.textContent.trim() === text);
const panel = (el) => [...el.querySelectorAll('section.card')].find((s) => s.querySelector('h3')?.textContent.trim() === 'Sales channels and export markets');
const rowOf = (root, label) => [...root.querySelectorAll('tbody tr')].find((tr) => tr.firstElementChild?.textContent.trim().startsWith(label));

afterEach(() => { setLocale('en'); globalThis.__errors = {}; document.body.innerHTML = ''; });

describe('product screen: sales channels and export markets', () => {
    it('shows a Local and an Export tab with the channels and what they give', async () => {
        const el = await render(props());
        const box = panel(el);
        expect(box).toBeTruthy();
        expect([...box.querySelectorAll('.seg button')].map((b) => b.textContent.trim())).toEqual(['Local', 'Export']);
        expect(box.querySelectorAll('tbody input[type=number]').length).toBeGreaterThan(0);

        const text = box.textContent;
        expect(text).toContain('Retail');
        expect(text).toContain('33,000,000');
        expect(text).toContain('18,000,000');
        expect(text).toContain('11,400,000');
        expect(text).toContain('62,400,000');
        expect(rowOf(box, 'Average local price').textContent).toContain('10,400.00');
    });

    it('the Export tab shows the exchange rates and the values in both currencies', async () => {
        const el = await render(props());
        await click(button(panel(el), 'Export'));
        const box = panel(el);

        expect(rowOf(box, '1 USD =').textContent).toContain('50.00');
        expect(rowOf(box, '1 EUR =').textContent).toContain('55.00');
        expect(box.textContent).toContain('34,500,000');
        expect(box.textContent).toContain('690,000');          // Saudi Arabia in USD
        expect(box.textContent).toContain('228,000');          // Italy in EUR
        expect(rowOf(box, 'Total export sales').textContent).toContain('66,840,000');
        expect(rowOf(box, 'Average export price').textContent).toContain('11,140.00');
    });

    it('the sales table adds the channels and the markets up (129,240,000)', async () => {
        const el = await render(props());
        expect(rowOf(el.querySelector('table.stm'), 'Target sales value').textContent).toContain('129,240,000');
        // The product-level export price rows are replaced by the markets' prices.
        expect(el.innerHTML).not.toContain('Export price per unit');
    });

    it('the last row of the sales table is the total of local AND export, with both parts shown above it', async () => {
        const el = await render(props());
        const table = el.querySelector('table.stm');

        expect(rowOf(table, 'Local sales value').textContent).toContain('62,400,000');
        expect(rowOf(table, 'Export sales value').textContent).toContain('66,840,000');
        expect(rowOf(table, 'Target sales value (local + export)').textContent).toContain('129,240,000');
    });

    it('with export switched off the table shows one total and no export row', async () => {
        const el = await render(props({ salesSettings: settings({ hasExport: false }) }));
        const table = el.querySelector('table.stm');

        expect(rowOf(table, 'Export sales value')).toBeUndefined();
        expect(rowOf(table, 'Target sales value').textContent).toContain('62,400,000');
    });

    it('Save and next builds the next product again from the server (the old figures are not kept)', async () => {
        const el = await render(props());
        button(el, 'Save and go to raw material payments')?.click() ?? [...el.querySelectorAll('button')].find((b) => /^Save and/.test(b.textContent.trim()) && b.textContent.includes('next'))?.click();
        await nextTick();

        const keep = globalThis.__put?.options?.preserveState;
        expect(typeof keep).toBe('function');
        expect(keep({ props: { errors: {} } })).toBe(false);                 // saved: show the server's figures, whatever product comes next
        expect(keep({ props: { errors: { 'target_percentages.1': 'x' } } })).toBe(true);   // errors: keep what the user typed
    });

    it('shows a red total while a year does not add up to 100', async () => {
        const el = await render(props());
        const box = panel(el);
        const badges = () => [...rowOf(box, 'Total').querySelectorAll('.badge')];
        expect(badges().every((b) => b.classList.contains('b-green'))).toBe(true);

        const retail = rowOf(box, 'Retail').querySelector('input');
        retail.value = '40';
        retail.dispatchEvent(new Event('input'));
        await nextTick();
        expect(badges()[0].classList.contains('b-red')).toBe(true);
        expect(badges()[0].textContent).toContain('90%');
        expect(badges()[1].classList.contains('b-green')).toBe(true);
    });

    it('the three dots copy a share into the following years', async () => {
        const el = await render(props());
        const row = rowOf(panel(el), 'Retail');
        const first = row.querySelector('input');
        first.value = '60';
        first.dispatchEvent(new Event('input'));
        await nextTick();
        await click(row.querySelector('.cin-dots'));
        expect([...rowOf(panel(el), 'Retail').querySelectorAll('input')].map((i) => i.value)).toEqual(['60', '60', '60']);
    });

    it('a channel can be given its own collection terms in a pop-up', async () => {
        const el = await render(props());
        const box = panel(el);
        expect(box.textContent).toContain('Product terms');
        expect(button(box, 'Edit terms')).toBeUndefined();

        const checkbox = box.querySelector('.termslist li input[type=checkbox]');
        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change'));
        await nextTick();
        expect(box.textContent).toContain('Own terms');

        await click(button(panel(el), 'Edit terms'));
        const dialog = document.body.querySelector('[role=dialog]');
        expect(dialog).toBeTruthy();
        expect(dialog.textContent).toContain('Collection terms — Retail');
        expect(dialog.querySelectorAll('.terms').length).toBe(2);
    });

    it('has a local and an export monthly pattern', async () => {
        const el = await render(props());
        const titles = [...el.querySelectorAll('section.card h3')].map((h) => h.textContent.trim());
        expect(titles).toContain('Monthly pattern — local sales');
        expect(titles).toContain('Monthly pattern — export sales');
    });

    it('sends the channels, the markets and the export pattern when saving', async () => {
        const el = await render(props());
        el.querySelector('form').dispatchEvent(new Event('submit'));
        await nextTick();

        const sent = globalThis.__put.data;
        expect(sent.segments.local.map((r) => r.sales_channel_id)).toEqual([1, 2, 3]);
        expect(sent.segments.export.map((r) => r.export_market_id)).toEqual([1, 2, 3]);
        expect(sent.segments.export[2].first_foreign_price).toBe(190);
        expect(sent.export_seasonality.type).toBe('flat');
    });

    it('lists each channel and market under the year in the results', async () => {
        const line = (name, value, quantity) => ({ name, value, quantity, price: value / quantity });
        const el = await render(props({ results: [{ year: 2026, sales_value: 129240000, local_value: 62400000, export_value: 66840000, sales_quantity: 12000, local_quantity: 6000, export_quantity: 6000, made_quantity: 12000, closing_inventory: 0,
            local_segments: [line('Retail', 33000000, 3000)], export_segments: [line('Italy', 12540000, 1200)] }] }));
        await click(el.querySelector('.rowtoggle'));
        const rows = [...el.querySelectorAll('.subrow')].map((r) => r.textContent);
        expect(rows.some((t) => t.includes('Retail') && t.includes('33,000,000'))).toBe(true);
        expect(rows.some((t) => t.includes('Italy') && t.includes('12,540,000'))).toBe(true);
    });

    it('shows server messages next to the shares', async () => {
        globalThis.__errors = { 'segments.local': 'The sales channel shares of 2028 must add up to 100% (now 90%).' };
        const el = await render(props());
        expect(panel(el).textContent).toContain('shares of 2028 must add up to 100%');
    });
});

describe('product screen: with export switched off, with no channels, read only and Arabic', () => {
    it('with export off there is only the local tab and the local pattern', async () => {
        const el = await render(props({ salesSettings: settings({ hasExport: false }), segments: { local: segments().local, export: [] }, exportSeasonality: null }));
        const box = panel(el);
        expect(box.querySelector('.seg')).toBeNull();
        expect(el.innerHTML).toContain('The local share must be 100%'.replace('The local', 'so the local'));
        expect([...el.querySelectorAll('section.card h3')].map((h) => h.textContent.trim())).not.toContain('Monthly pattern — export sales');
        expect(rowOf(el.querySelector('table.stm'), 'Target sales value').textContent).toContain('62,400,000');
    });

    it('a study with no channels keeps the old screen (product-level export price, no panel)', async () => {
        const el = await render(props({ salesSettings: null, segments: null, exportSeasonality: null }));
        expect(panel(el)).toBeUndefined();
        expect(el.innerHTML).toContain('Export price per unit');
        expect([...el.querySelectorAll('section.card h3')].map((h) => h.textContent.trim())).toContain('Monthly pattern');
        el.querySelector('form').dispatchEvent(new Event('submit'));
        await nextTick();
        expect(globalThis.__put.data.segments).toBeUndefined();
        expect(globalThis.__put.data.export_seasonality).toBeUndefined();
    });

    it('a study with channels but no markets yet says so on the Export tab', async () => {
        const el = await render(props({ segments: { local: segments().local, export: [] } }));
        await click(button(panel(el), 'Export'));
        expect(panel(el).textContent).toContain('no export markets yet');
    });

    it('is read only for a person who may only look', async () => {
        const el = await render(props({ readOnly: true }));
        expect(el.querySelector('fieldset[disabled]').contains(panel(el))).toBe(true);
        expect(el.querySelector('button[type=submit]')).toBeNull();
    });

    it('comes out in Arabic', async () => {
        setLocale('ar');
        const el = await render(props());
        const box = [...el.querySelectorAll('section.card')].find((s) => s.querySelector('h3')?.textContent.trim() === 'قنوات البيع وأسواق التصدير');
        expect(box).toBeTruthy();
        expect([...box.querySelectorAll('.seg button')].map((b) => b.textContent.trim())).toEqual(['محلي', 'تصدير']);
        expect(el.innerHTML).toContain('النمط الشهري — مبيعات التصدير');
        expect(box.textContent).toContain('33,000,000');
    });
});
