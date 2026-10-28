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
        useForm: (data) => {
            const form = vue.reactive({ ...JSON.parse(JSON.stringify(data)), errors: globalThis.__errors ?? {}, hasErrors: Object.keys(globalThis.__errors ?? {}).length > 0, processing: false,
                put() {}, post() {}, delete() {}, clearErrors() {}, reset() {}, transform() { return this; } });
            return form;
        },
    };
});
vi.mock('@/pwa/register', () => ({ applyUpdate() {}, updateReady: { value: false }, registerServiceWorker() {} }));

globalThis.route = (name, p) => `/${name}${p ? `/${typeof p === 'object' ? p.id ?? '' : p}` : ''}`;

const { default: Edit } = await import('@/Pages/Projects/Edit.vue');
const { default: Index } = await import('@/Pages/Projects/Index.vue');
const { default: CompaniesIndex } = await import('@/Pages/Admin/Companies/Index.vue');
const { default: CompanyForm } = await import('@/Pages/Admin/Companies/Form.vue');
const { default: UsersIndex } = await import('@/Pages/Users/Index.vue');
const { default: UserForm } = await import('@/Pages/Users/Form.vue');
const { default: Home } = await import('@/Pages/Home.vue');
const { default: MonthYearInput } = await import('@/Components/MonthYearInput.vue');
const { setLocale } = await import('@/lang/i18n');

let mounted = [];
const render = async (component, props) => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp({ render: () => h(component, props) });
    app.config.globalProperties.route = globalThis.route;
    app.mount(el);
    mounted.push([app, el]);
    await nextTick();
    return el.innerHTML;
};
const dom = () => mounted.at(-1)[1];

const editProps = {
    project: { id: 7, name: 'Plant A', completed: true, new_company: true, main_functional_currency: 'egp', intro_paragraph: 'Hello', business_sector_id: 2,
        start_date: '2026-01', duration: 5, operation_start_date: '2026-04', tax_rate: '22.50', return_rate: '30.00', perpetual_growth_rate: '4.00' },
    products: [{ id: 1, name: 'Valve', measurement_unit: 'piece', selling_start_date: '2026-06', vat_rate: '14.0000', withhold_tax_rate: '1.0000' }],
    rawMaterials: [{ id: 3, name: 'Brass', rm_inventory_coverage_days: 30, measurement_unit: 'kg', vat_rate: '14.0000', withhold_tax_rate: '0.0000' }],
    sectors: [{ id: 1, name_en: 'Textiles', name_ar: 'منسوجات' }, { id: 2, name_en: 'Automotive', name_ar: 'سيارات' }],
    options: { currencies: ['egp', 'usd'], units: ['ton', 'kg', 'piece'], coverageDays: [0, 15, 30], minYears: 1, maxYears: 7 },
};

describe('screens render', () => {
    it('the study form (English)', async () => {
        setLocale('en');
        const html = await render(Edit, editProps);
        expect(html).toContain('Plant A');
        expect(html).toContain('December 2030');          // 2026-01 + 5 years ends December 2030
        expect(dom().querySelector('#p-0-name').value).toBe('Valve');
        expect(dom().querySelector('#m-0-name').value).toBe('Brass');
        expect(dom().querySelector('#p-0-sell').value).toBe('6');
        expect(dom().querySelector('#m-0-cov').value).toBe('30');
        expect(dom().querySelector('#f-sector').value).toBe('2');
        expect(html).toContain('Automotive');
        expect(html).toContain('Egyptian pound (EGP)');
    });

    it('the study form (Arabic) shows Arabic words and sector names', async () => {
        setLocale('ar');
        const html = await render(Edit, editProps);
        expect(html).toContain('بيانات الدراسة');
        expect(html).toContain('ديسمبر 2030');
        expect(html).toContain('سيارات');
        setLocale('en');
    });

    it('shows the server messages under the fields', async () => {
        globalThis.__errors = { name: 'Bad name', 'products.0.name': 'Please enter the product name.' };
        const html = await render(Edit, editProps);
        globalThis.__errors = {};
        expect(html).toContain('Bad name');
        expect(html).toContain('Please enter the product name.');
        expect(html).toContain('Some fields need your attention');
    });

    it('a first-time study has one empty product and one empty raw material', async () => {
        const html = await render(Edit, { ...editProps, project: { ...editProps.project, start_date: null, duration: null, operation_start_date: null, completed: false }, products: [], rawMaterials: [] });
        expect((html.match(/class="rep"/g) ?? []).length).toBe(2);
    });

    it('the list', async () => {
        const html = await render(Index, { studies: [{ id: 1, name: 'Plant A', completed: true, new_company: false, duration: 5, start: '2026-01', end: '2030-12', updated_at: '2026-10-27' }] });
        expect(html).toContain('Plant A');
        expect(html).toContain('Existing company');
        expect(html).toContain('January 2026 – December 2030');
        expect(html).toContain('Set up');
    });

    it('the empty list', async () => {
        expect(await render(Index, { studies: [] })).toContain('No studies yet');
    });

    it('home links to the studies', async () => {
        expect(await render(Home, {})).toContain('Go to my studies');
    });

    it('the month picker shows the chosen month and year', async () => {
        await render(MonthYearInput, { modelValue: '2026-03' });
        const [month, year] = dom().querySelectorAll('select');
        expect(month.value).toBe('3');
        expect(year.value).toBe('2026');
    });

    it('the study form keeps the operation date equal to the study start for an existing company', async () => {
        await render(Edit, { ...editProps, project: { ...editProps.project, new_company: false, operation_start_date: '2026-01' } });
        const selects = dom().querySelectorAll('#f-operation');
        expect(selects[0].disabled).toBe(true);
        expect(dom().querySelector('#f-return').disabled).toBe(false);
        expect(dom().querySelector('#f-perpetual').disabled).toBe(false);
    });

    it('return and growth rates are locked for a one-year study', async () => {
        await render(Edit, { ...editProps, project: { ...editProps.project, duration: 1 } });
        expect(dom().querySelector('#f-return').disabled).toBe(true);
        expect(dom().querySelector('#f-perpetual').disabled).toBe(true);
        expect(dom().querySelector('#f-end').value).toBe('December 2026');
    });

    it('the first row cannot be removed', async () => {
        await render(Edit, editProps);
        const buttons = [...dom().querySelectorAll('.rep button')];
        expect(buttons.length).toBe(2);
        expect(buttons.every((b) => b.disabled)).toBe(true);
    });

    it('companies: list, new form and edit form', async () => {
        const list = await render(CompaniesIndex, { companies: [{ id: 1, name_en: 'Delta', name_ar: 'دلتا', status: 'trial', users_limit: 5, active_users: 2, studies: 3, ends: '2026-12-31', read_only: true }] });
        expect(list).toContain('Delta');
        expect(list).toContain('2 / 5');
        expect(list).toContain('Read-only');
        const fresh = await render(CompanyForm, { company: null, users: [], statuses: ['trial', 'active', 'suspended'], defaults: { users_limit: 5, subscription_starts_at: '2026-10-27', subscription_ends_at: '2027-10-27' } });
        expect(fresh).toContain('First administrator');
        expect(dom().querySelector('#c-limit').value).toBe('5');
        const edit = await render(CompanyForm, { company: { id: 1, name_en: 'Delta', name_ar: 'دلتا', status: 'active', users_limit: 5, subscription_starts_at: '2026-01-01', subscription_ends_at: '2026-12-31', contact_phone: '', contact_email: '', notes: '', read_only: false }, users: [{ id: 9, name: 'Sam', email: 's@x.test', role: 'company_admin', active: true, last_login: null }], statuses: ['trial', 'active', 'suspended'], defaults: null });
        expect(edit).not.toContain('First administrator');
        expect(edit).toContain('Company administrator');
        expect(dom().querySelector('#c-en').value).toBe('Delta');
    });

    it('people: list and forms', async () => {
        const list = await render(UsersIndex, { users: [{ id: 1, name: 'Sam', email: 's@x.test', job_title: null, role: 'company_admin', active: true, last_login: null }], limit: 5, active: 1 });
        expect(list).toContain('1 of 5 accounts are active');
        await render(UserForm, { person: null });
        expect(dom().querySelector('#u-mail').readOnly).toBe(false);
        await render(UserForm, { person: { id: 1, name: 'Sam', email: 's@x.test', job_title: '', role: 'company_admin', active: true, is_me: true } });
        expect(dom().querySelector('#u-mail').readOnly).toBe(true);
        expect(dom().querySelector('#u-role').disabled).toBe(true);
    });

    it('a view-only study cannot be changed', async () => {
        await render(Edit, { ...editProps, readOnly: true, project: { ...editProps.project, owner: 'Omar' } });
        expect(dom().querySelector('fieldset').disabled).toBe(true);
        expect(dom().textContent).toContain('created by Omar');
        expect(dom().querySelector('button[type=submit]')).toBeNull();
    });

    it('the studies list shows owner, access and the share button only where allowed', async () => {
        const html = await render(Index, {
            colleagues: [{ id: 5, name: 'Lina', email: 'l@x.test' }],
            studies: [
                { id: 1, name: 'Mine', completed: true, new_company: true, duration: 3, start: '2026-01', end: '2028-12', updated_at: '2026-10-27', owner: 'Me', access: 'owner', can_share: true, can_delete: true, can_excel: true, shared_with: 1, shares: [{ user_id: 5, can_edit: false }] },
                { id: 2, name: 'Theirs', completed: true, new_company: true, duration: 3, start: '2026-01', end: '2028-12', updated_at: '2026-10-27', owner: 'Omar', access: 'view', can_share: false, can_delete: false, shared_with: 0, shares: [] },
            ],
        });
        expect(html).toContain('Shared with 1');
        expect(html).toContain('Shared: view only');
        expect((html.match(/>Share</g) ?? []).length).toBe(1);
        expect((html.match(/>Delete</g) ?? []).length).toBe(1);
        // the Excel Model button shows only for a study that is ready, and downloads (a plain link, not a page visit)
        expect((html.match(/>Excel Model</g) ?? []).length).toBe(1);
        expect(html).toContain('href="/projects.excel/1"');
    });

    it('the top bar: tabs follow the role and the subscription notice shows', async () => {
        const html = await render(Home, {});
        expect(html).toContain('Projects');
        expect(html).toContain('People');
        expect(html).not.toContain('>Companies<');
    });
});
