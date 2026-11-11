// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — screen tests of the "Sales channels and export" card (run: npm test)
//  Location: tests/js/salesCard.spec.js
//  The card edits the study form it is given; here the form is a plain reactive object.
// ══════════════════════════════════════════════════════════════════
import { afterEach, describe, expect, it } from 'vitest';
import { createApp, h, nextTick, reactive } from 'vue';
import SalesChannelsCard from '@/Components/SalesChannelsCard.vue';
import { setLocale } from '@/lang/i18n';

const options = { currencies: ['egp', 'usd', 'euro', 'aed', 'sar', 'qar'], maxChannels: 3, maxMarkets: 2, maxCurrencies: 2 };

const makeForm = (over = {}) => reactive({
    main_functional_currency: 'egp', start_date: '2026-01', duration: 3,
    has_export_sales: false, clear_export: false,
    sales_channels: [], fx_rates: [], export_markets: [], errors: {},
    ...over,
});

const mounted = [];
async function render(form, extra = {}) {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp({ render: () => h(SalesChannelsCard, { form, options, ...extra }) });
    app.mount(el);
    mounted.push([app, el]);
    await nextTick();

    return el;
}
const click = async (el) => { el.click(); await nextTick(); };

afterEach(() => {
    mounted.splice(0).forEach(([app, el]) => { app.unmount(); el.remove(); });
    document.body.innerHTML = '';
    setLocale('en');
});

describe('Sales channels and export card', () => {
    it('shows the channels list and no export parts while export is off', async () => {
        const form = makeForm({ sales_channels: [{ id: 1, name: 'Retail' }, { id: 2, name: 'Wholesale' }] });
        const el = await render(form);

        expect(el.querySelectorAll('[data-channel-name]')).toHaveLength(2);
        expect(el.querySelector('[data-channel-name]').value).toBe('Retail');
        expect(el.querySelector('[data-fx]')).toBeNull();
        expect(el.querySelector('[data-markets]')).toBeNull();
        expect(el.querySelector('[data-export-no]').checked).toBe(true);
    });

    it('adds and removes channels and stops at the limit', async () => {
        const form = makeForm();
        const el = await render(form);

        for (let i = 0; i < 3; i += 1) await click(el.querySelector('[data-channel-add]'));
        expect(form.sales_channels).toHaveLength(3);
        expect(el.querySelector('[data-channel-add]').disabled).toBe(true);

        await click(el.querySelector('[data-channel-remove]'));
        expect(form.sales_channels).toHaveLength(2);
        expect(el.querySelector('[data-channel-add]').disabled).toBe(false);
    });

    it('warns about a repeated channel name before saving', async () => {
        const form = makeForm({ sales_channels: [{ id: null, name: 'Retail' }, { id: null, name: 'retail' }] });
        const el = await render(form);

        expect(el.textContent).toContain('This name is used twice.');
    });

    it('shows currencies, the FX forecast and markets when export is switched on', async () => {
        const form = makeForm();
        const el = await render(form);

        await click(el.querySelector('[data-export-yes]'));
        expect(form.has_export_sales).toBe(true);
        expect(el.querySelector('[data-fx]')).not.toBeNull();
        expect(el.querySelector('[data-markets]')).not.toBeNull();
    });

    it('never offers the study currency as a foreign currency', async () => {
        const form = makeForm({ has_export_sales: true });
        const el = await render(form);
        const offered = [...el.querySelectorAll('[data-currency]')].map((box) => box.dataset.currency);

        expect(offered).toEqual(['usd', 'euro', 'aed', 'sar', 'qar']);
    });

    it('gives each chosen currency one rate box for every study year', async () => {
        const form = makeForm({ has_export_sales: true });
        const el = await render(form);

        await click(el.querySelector('[data-currency="usd"]'));
        const row = el.querySelector('[data-fx-row="usd"]');

        expect(form.fx_rates.map((r) => r.currency)).toEqual(['usd']);
        expect(row.querySelectorAll('input')).toHaveLength(3);   // 2026, 2027, 2028
        expect([...el.querySelectorAll('.fx thead th')].map((th) => th.textContent.trim())).toEqual(['Currency', '2026', '2027', '2028']);
    });

    it('follows the study duration: a longer study gets more year boxes and typed rates are kept', async () => {
        const form = makeForm({ has_export_sales: true, fx_rates: [{ currency: 'usd', rates: { 0: '50', 1: '54', 2: '58' } }] });
        const el = await render(form);

        form.duration = 5;
        await nextTick();
        await nextTick();

        expect(el.querySelector('[data-fx-row="usd"]').querySelectorAll('input')).toHaveLength(5);
        expect(form.fx_rates[0].rates[0]).toBe('50');
        expect(form.fx_rates[0].rates[3]).toBe('');
    });

    it('stops at the currency limit', async () => {
        const form = makeForm({ has_export_sales: true, fx_rates: [{ currency: 'usd', rates: { 0: '', 1: '', 2: '' } }, { currency: 'euro', rates: { 0: '', 1: '', 2: '' } }] });
        const el = await render(form);

        expect(el.querySelector('[data-currency="aed"]').disabled).toBe(true);
        expect(el.querySelector('[data-currency="usd"]').disabled).toBe(false);
    });

    it('lets a market use only the chosen currencies, and needs a currency first', async () => {
        const form = makeForm({ has_export_sales: true });
        let el = await render(form);
        expect(el.querySelector('[data-market-add]').disabled).toBe(true);

        const form2 = makeForm({ has_export_sales: true, fx_rates: [{ currency: 'usd', rates: { 0: 50, 1: 54, 2: 58 } }], export_markets: [{ id: null, name: 'Saudi Arabia', currency: 'usd' }] });
        el = await render(form2);
        const options2 = [...el.querySelectorAll('[data-market-currency] option')].map((o) => o.value);

        expect(options2).toEqual(['', 'usd']);
        expect(el.querySelector('[data-market-add]').disabled).toBe(false);
    });

    it('clears the currency of a market when that currency is unticked', async () => {
        const form = makeForm({ has_export_sales: true, fx_rates: [{ currency: 'usd', rates: { 0: 50, 1: 54, 2: 58 } }], export_markets: [{ id: null, name: 'Saudi Arabia', currency: 'usd' }] });
        const el = await render(form);

        await click(el.querySelector('[data-currency="usd"]'));

        expect(form.fx_rates).toHaveLength(0);
        expect(form.export_markets[0].currency).toBe('');
    });

    it('drops the study currency from the foreign ones when the study currency changes', async () => {
        const form = makeForm({ has_export_sales: true, fx_rates: [{ currency: 'usd', rates: { 0: 50, 1: 54, 2: 58 } }] });
        await render(form);

        form.main_functional_currency = 'usd';
        await nextTick();
        await nextTick();

        expect(form.fx_rates).toHaveLength(0);
    });

    it('asks before switching export off while a product still exports, and sets the export to be cleared', async () => {
        const form = makeForm({ has_export_sales: true });
        const el = await render(form, { exportProducts: ['Valve', 'Pump'] });

        await click(el.querySelector('[data-export-no]'));
        expect(form.has_export_sales).toBe(true);   // not yet
        expect(document.body.textContent).toContain('Valve, Pump');

        // Confirm: the dialog's red button.
        await click(document.querySelector('.modal button.btn-red'));
        expect(form.has_export_sales).toBe(false);
        expect(form.clear_export).toBe(true);
    });

    it('switches export off straight away when no product exports', async () => {
        const form = makeForm({ has_export_sales: true });
        const el = await render(form, { exportProducts: [] });

        await click(el.querySelector('[data-export-no]'));

        expect(form.has_export_sales).toBe(false);
        expect(form.clear_export).toBe(false);
        expect(document.querySelector('.modal')).toBeNull();
    });

    it('shows the messages the server sent next to the fields', async () => {
        const form = makeForm({
            has_export_sales: true, fx_rates: [{ currency: 'usd', rates: { 0: '', 1: 54, 2: 58 } }], export_markets: [{ id: null, name: 'Italy', currency: '' }],
            errors: { 'fx_rates.0.rates.0': 'Enter the exchange rate of this year.', 'export_markets.0.currency': 'Please choose the market currency.' },
        });
        const el = await render(form);

        expect(el.textContent).toContain('Enter the exchange rate of this year.');
        expect(el.textContent).toContain('Please choose the market currency.');
    });

    it('speaks Arabic', async () => {
        setLocale('ar');
        const form = makeForm({ has_export_sales: true });
        const el = await render(form);

        expect(el.textContent).toContain('قنوات البيع والتصدير');
        expect(el.textContent).toContain('العملات الأجنبية');
        expect(el.textContent).not.toContain('projects.sales');
    });
});
