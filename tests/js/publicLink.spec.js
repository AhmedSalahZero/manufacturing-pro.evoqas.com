// ZAVERO Manufacturing Pro — public sharing link screen tests (run: npm test)
// Location: tests/js/publicLink.spec.js
// Checks: the page names lead to /shared/{code}/... for a visitor; the visitor's top bar is slim (no sign-in menu); the study bar
// has no report and no links page for a visitor; the study form and dashboard hide the way back to the list of studies and the report.
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApp, h, nextTick, reactive } from 'vue';

const state = reactive({ props: {}, url: '/shared/CODE/projects/5/edit' });
const calls = [];

vi.mock('@inertiajs/vue3', async () => {
    const vue = await import('vue');
    const passthrough = (tag) => vue.defineComponent({ props: ['href', 'as'], setup: (p, { slots, attrs }) => () => vue.h(tag, { href: p.href, ...attrs }, slots.default?.()) });
    return {
        Head: vue.defineComponent({ setup: () => () => null }),
        Link: passthrough('a'),
        router: { post: (...a) => calls.push(['post', ...a]), put: (...a) => calls.push(['put', ...a]), delete: (...a) => calls.push(['delete', ...a]), on() {} },
        useForm: (d) => vue.reactive({ ...JSON.parse(JSON.stringify(d)), errors: {}, processing: false, hasErrors: false, put() {}, post() {}, delete() {}, reset() {}, clearErrors() {}, transform() { return this; } }),
        usePage: () => state,
    };
});
vi.mock('@/pwa/register', () => ({ applyUpdate() {}, updateReady: { value: false }, registerServiceWorker() {} }));
globalThis.route = (name, p) => `/${name}${p ? `/${[].concat(p).join('/')}` : ''}`;

const { applyPublicRoutes, PUBLIC_ROUTE_NAMES, FILL_ROUTE_NAMES } = await import('@/Utils/publicRoutes');
const { default: AppLayout } = await import('@/Layouts/AppLayout.vue');
const { default: StudyNav } = await import('@/Components/StudyNav.vue');
const { default: Edit } = await import('@/Pages/Projects/Edit.vue');
const { default: Index } = await import('@/Pages/Projects/Index.vue');
const { default: Closed } = await import('@/Pages/Shared/Closed.vue');
const { default: Thanks } = await import('@/Pages/Shared/Thanks.vue');

const study = { id: 5, name: 'Plant A', new_company: false, has_raw: true, products: [{ id: 1, name: 'Valve' }] };

const apps = [];
// every test starts with an empty page (the windows are put at the end of the page, so they would pile up)
afterEach(() => {
    apps.splice(0).forEach(({ app, el }) => { app.unmount(); el.remove(); });
    document.body.innerHTML = '';
});

async function mount(component, props = {}, slots = undefined) {
    const el = document.createElement('div');
    document.body.appendChild(el);
    const app = createApp({ render: () => h(component, props, slots) });
    app.config.globalProperties.route = globalThis.route;
    app.mount(el);
    apps.push({ app, el });
    await nextTick();

    return el;
}

describe('the addresses of a public link', () => {
    const ziggy = () => ({
        routes: {
            'projects.edit': { uri: 'projects/{project}/edit', methods: ['GET', 'HEAD'], parameters: ['project'] },
            'projects.report': { uri: 'projects/{project}/report', methods: ['GET', 'HEAD'], parameters: ['project'] },
            'shared.projects.edit': { uri: 'shared/{code}/projects/{project}/edit', methods: ['GET', 'HEAD'], parameters: ['code', 'project'], bindings: { project: 'id' } },
            'shared.projects.products.edit': { uri: 'shared/{code}/projects/{project}/products/{product}/edit', methods: ['GET', 'HEAD'], parameters: ['code', 'project', 'product'] },
        },
    });

    it('puts the code of the link into every study address and leaves the others alone', () => {
        const config = applyPublicRoutes(ziggy(), 'ABC123');

        expect(config.routes['projects.edit'].uri).toBe('shared/ABC123/projects/{project}/edit');
        expect(config.routes['projects.edit'].parameters).toEqual(['project']);
        expect(config.routes['projects.products.edit'].uri).toBe('shared/ABC123/projects/{project}/products/{product}/edit');
        expect(config.routes['projects.products.edit'].parameters).toEqual(['project', 'product']);
        expect(config.routes['projects.report'].uri).toBe('projects/{project}/report');
    });

    it('does nothing without a code or without a configuration', () => {
        expect(applyPublicRoutes(null, 'X')).toBeNull();
        const config = ziggy();
        expect(applyPublicRoutes(config, '').routes['projects.edit'].uri).toBe('projects/{project}/edit');
    });

    it('covers every page of the study from start to end, but not the report or the links', () => {
        expect(PUBLIC_ROUTE_NAMES).toEqual(expect.arrayContaining([
            'projects.edit', 'projects.products.edit', 'projects.raw-materials.edit', 'projects.manpower.edit', 'projects.expenses.edit',
            'projects.assets.edit', 'projects.opening.edit', 'projects.statements', 'projects.dashboard', 'projects.sensitivity',
        ]));
        expect(PUBLIC_ROUTE_NAMES).not.toContain('projects.report');
        expect(PUBLIC_ROUTE_NAMES).not.toContain('projects.links');
    });
});

describe('what a visitor of a public link sees around the study', () => {
    it('gets a slim top bar with the read only badge and no sign-in menu', async () => {
        state.props = { auth: { user: null }, public: { code: 'CODE', name: 'Bank' }, study, flash: {}, locale: 'en' };
        const el = await mount(AppLayout, { title: 'x' }, { default: () => h('p', { id: 'inside' }, 'page') });

        expect(el.querySelector('[data-public-bar]')).not.toBeNull();
        expect(el.querySelector('.tabbar')).toBeNull();
        expect(el.textContent).toContain('Shared view');
        expect(el.textContent).not.toContain('Sign out');
        expect(el.querySelector('[data-study-nav]')).not.toBeNull();
        expect(el.querySelector('#inside')).not.toBeNull();
    });

    it('keeps the full top bar for a signed-in person', async () => {
        state.props = { auth: { user: { role: 'company_admin', name: 'Mona', email: 'm@x.test', company: null } }, public: null, study, flash: {}, locale: 'en' };
        const el = await mount(AppLayout, { title: 'x' }, { default: () => h('p', 'page') });

        expect(el.querySelector('[data-public-bar]')).toBeNull();
        expect(el.querySelector('.tabbar')).not.toBeNull();
        expect(el.textContent).toContain('Mona');
    });

    it('shows every page of the study in the study bar, but no report and no links', async () => {
        state.props = { auth: { user: null }, public: { code: 'CODE', name: 'Bank' }, study, flash: {}, locale: 'en' };
        const el = await mount(StudyNav, { study });
        const ids = [...el.querySelectorAll('[data-nav]')].map((n) => n.dataset.nav);

        expect(ids).toEqual(expect.arrayContaining(['study', 'products', 'raw', 'staff', 'expenses', 'assets', 'opening', 'statements', 'dashboard', 'sensitivity']));
        expect(ids).not.toContain('report');
        expect(ids).not.toContain('links');
    });

    it('shows the report and the links to a signed-in person', async () => {
        state.props = { auth: { user: { role: 'company_admin' } }, public: null, study, flash: {}, locale: 'en' };
        const el = await mount(StudyNav, { study });
        const ids = [...el.querySelectorAll('[data-nav]')].map((n) => n.dataset.nav);

        expect(ids).toEqual(expect.arrayContaining(['report', 'links']));
    });
});

describe('the fill link: the customer fills in the input pages', () => {
    const ziggy = () => ({
        routes: {
            'projects.edit': { uri: 'projects/{project}/edit', methods: ['GET', 'HEAD'], parameters: ['project'] },
            'projects.update': { uri: 'projects/{project}', methods: ['PUT'], parameters: ['project'] },
            'projects.statements': { uri: 'projects/{project}/statements', methods: ['GET', 'HEAD'], parameters: ['project'] },
            'fill.projects.edit': { uri: 'fill/{code}/projects/{project}/edit', methods: ['GET', 'HEAD'], parameters: ['code', 'project'] },
            'fill.projects.update': { uri: 'fill/{code}/projects/{project}', methods: ['PUT'], parameters: ['code', 'project'] },
            'fill.projects.expenses.update': { uri: 'fill/{code}/projects/{project}/expenses', methods: ['PUT'], parameters: ['code', 'project'] },
            'projects.expenses.update': { uri: 'projects/{project}/expenses', methods: ['PUT'], parameters: ['project'] },
        },
    });

    it('sends the pages and the saves to /fill/{code}/... and leaves the results alone', () => {
        const config = applyPublicRoutes(ziggy(), 'XYZ', 'fill');

        expect(config.routes['projects.edit'].uri).toBe('fill/XYZ/projects/{project}/edit');
        expect(config.routes['projects.update'].uri).toBe('fill/XYZ/projects/{project}');
        expect(config.routes['projects.update'].methods).toEqual(['PUT']);
        expect(config.routes['projects.expenses.update'].uri).toBe('fill/XYZ/projects/{project}/expenses');
        expect(config.routes['projects.expenses.update'].parameters).toEqual(['project']);
        expect(config.routes['projects.statements'].uri).toBe('projects/{project}/statements');
    });

    it('covers the input pages and their saves, never the results, the report or the links', () => {
        expect(FILL_ROUTE_NAMES).toEqual(expect.arrayContaining([
            'projects.edit', 'projects.products.edit', 'projects.raw-materials.edit', 'projects.manpower.edit', 'projects.expenses.edit', 'projects.assets.edit', 'projects.opening.edit',
            'projects.update', 'projects.products.update', 'projects.raw-materials.update', 'projects.manpower.update', 'projects.expenses.update', 'projects.assets.update', 'projects.opening.update',
        ]));
        for (const name of ['projects.statements', 'projects.dashboard', 'projects.sensitivity', 'projects.report', 'projects.links']) {
            expect(FILL_ROUTE_NAMES).not.toContain(name);
        }
    });

    it('shows a slim bar with its own badge and a study bar with the input pages only', async () => {
        state.props = { auth: { user: null }, public: { code: 'CODE', name: 'Template', kind: 'fill' }, study, flash: {}, locale: 'en' };
        const el = await mount(AppLayout, { title: 'x' }, { default: () => h('p', 'page') });
        const ids = [...el.querySelectorAll('[data-nav]')].map((n) => n.dataset.nav);

        expect(el.textContent).toContain('Please fill in your data');
        expect(el.querySelector('.tabbar')).toBeNull();
        expect(ids).toEqual(expect.arrayContaining(['study', 'products', 'raw', 'staff', 'expenses', 'assets', 'opening']));
        for (const gone of ['statements', 'dashboard', 'sensitivity', 'report', 'links']) {
            expect(ids).not.toContain(gone);
        }
    });

    const editProps = {
        project: { id: 5, name: 'Plant A', completed: true, new_company: true, main_functional_currency: 'egp', intro_paragraph: '', business_sector_id: 1,
            start_date: '2026-01', duration: 5, operation_start_date: '2026-04', tax_rate: '22.50', return_rate: '30.00', perpetual_growth_rate: '4.00' },
        products: [{ id: 1, name: 'Valve', measurement_unit: 'piece', selling_start_date: '2026-06', vat_rate: '14', withhold_tax_rate: '1' }],
        rawMaterials: [{ id: 3, name: 'Brass', rm_inventory_coverage_days: 30, measurement_unit: 'kg', vat_rate: '14', withhold_tax_rate: '0' }],
        sectors: [{ id: 1, name_en: 'Textiles', name_ar: 'x' }],
        options: { currencies: ['egp'], units: ['ton', 'kg', 'piece'], coverageDays: [0, 15, 30], minYears: 1, maxYears: 7 },
        readOnly: false,
    };

    it('shows the three rates to the customer but does not let him change them, and hides the results and the links', async () => {
        state.props = { auth: { user: null }, public: { code: 'CODE', name: 'Template', kind: 'fill' }, study, flash: {}, locale: 'en' };
        const el = await mount(Edit, editProps);

        for (const id of ['#f-tax', '#f-return', '#f-perpetual']) {
            expect(el.querySelector(id).disabled).toBe(true);
        }
        expect(el.querySelector('#f-tax').value).toBe('22.50');
        expect(el.querySelector('#p-0-name').disabled).toBe(false);
        const hrefs = [...el.querySelectorAll('a')].map((a) => a.getAttribute('href') ?? '');
        for (const gone of ['statements', 'dashboard', 'sensitivity', 'report', 'links', 'projects.index']) {
            expect(hrefs.some((h) => h.includes(gone))).toBe(false);
        }
    });

    it('keeps the rates editable for the owner inside the app', async () => {
        state.props = { auth: { user: { role: 'company_admin', name: 'Mona', email: 'm@x.test' } }, public: null, study, flash: {}, locale: 'en' };
        const el = await mount(Edit, editProps);

        expect(el.querySelector('#f-tax').disabled).toBe(false);
        expect(el.querySelector('#f-return').disabled).toBe(false);
    });

    it('tells a customer that a paused or an expired link cannot be used, and thanks him at the end', async () => {
        state.props = { auth: { user: null }, public: null, flash: {}, locale: 'en' };
        let el = await mount(Closed, { reason: 'expired' });
        expect(el.querySelector('[data-reason="expired"]')).not.toBeNull();
        expect(el.textContent).toContain('expired');
        el = await mount(Closed, { reason: 'paused' });
        expect(el.querySelector('[data-reason="paused"]').textContent).toContain('switched off');

        el = await mount(Thanks, { project: { id: 5, name: 'Plant A' } });
        expect(el.querySelector('[data-thanks]').textContent).toContain('Plant A');
        expect(el.textContent).toContain('Thank you');
        expect(el.querySelector('a').getAttribute('href')).toContain('projects.edit');
    });
});

describe('the list of studies: Share template and two rows of buttons', () => {
    const study1 = (over = {}) => ({ id: 1, name: 'Plant A', completed: true, new_company: true, duration: 3, start: '2026-01', end: '2028-12', updated_at: '2026-11-09',
        owner: 'Mona', access: 'owner', can_share: true, can_delete: true, can_excel: true, shared_with: 0, shares: [], fill_link: null, ...over });
    const modal = () => document.body.querySelector('[data-template="window"]');

    it('puts the six buttons in a grid of three columns (two rows) and has a Share template button', async () => {
        state.props = { auth: { user: { role: 'company_admin', name: 'Mona', email: 'm@x.test' } }, public: null, flash: {}, locale: 'en' };
        const el = await mount(Index, { studies: [study1()], colleagues: [] });
        const grid = el.querySelector('[data-actions]');

        expect(grid.classList.contains('actgrid')).toBe(true);
        expect([...grid.children].map((b) => b.textContent.trim())).toEqual(['Open', 'Excel Model', 'Copy', 'Share', 'Share template', 'Delete']);
    });

    it('creates the link from the window, with an optional expiry date', async () => {
        calls.length = 0;
        state.props = { auth: { user: { role: 'company_admin', name: 'Mona', email: 'm@x.test' } }, public: null, flash: {}, locale: 'en' };
        const el = await mount(Index, { studies: [study1()], colleagues: [] });
        el.querySelector('[data-action="template"]').click();
        await nextTick();

        expect(modal()).not.toBeNull();
        expect(modal().textContent).toContain('no template link');
        const date = modal().querySelector('#tpl-new-exp');
        date.value = '2026-12-31';
        date.dispatchEvent(new Event('input'));
        modal().querySelector('[data-action="tpl-create"]').click();

        expect(calls.at(-1)[0]).toBe('post');
        expect(calls.at(-1)[1]).toContain('projects.links.store');
        expect(calls.at(-1)[2]).toEqual({ type: 'fill', name: '', expires_at: '2026-12-31' });
    });

    it('shows the link of a study with its status, and pauses, resumes, sets the date and deletes it', async () => {
        calls.length = 0;
        const link = { id: 9, name: 'Template link', type: 'fill', url: 'http://x.test/fill/ABC', closed: false, status: 'active', expires_at: '2026-12-31', visits: 3, last_visit: null, created: '2026-11-09' };
        state.props = { auth: { user: { role: 'company_admin', name: 'Mona', email: 'm@x.test' } }, public: null, flash: {}, locale: 'en' };
        const el = await mount(Index, { studies: [study1({ fill_link: link })], colleagues: [] });
        el.querySelector('[data-action="template"]').click();
        await nextTick();

        expect(modal().querySelector('#tpl-url').value).toBe('http://x.test/fill/ABC');
        expect(modal().querySelector('[data-tpl-status]').textContent).toContain('Active');
        expect(modal().querySelector('#tpl-exp').value).toBe('2026-12-31');

        modal().querySelector('[data-action="tpl-toggle"]').click();
        expect(calls.at(-1)[0]).toBe('put');
        expect(calls.at(-1)[1]).toContain('projects.links.toggle');

        modal().querySelector('[data-action="tpl-expiry"]').click();
        expect(calls.at(-1)[1]).toContain('projects.links.expiry');
        expect(calls.at(-1)[2]).toEqual({ expires_at: '2026-12-31' });

        modal().querySelector('[data-action="tpl-noexpiry"]').click();
        expect(calls.at(-1)[2]).toEqual({ expires_at: null });

        const confirmed = vi.spyOn(window, 'confirm').mockReturnValue(true);
        modal().querySelector('[data-action="tpl-delete"]').click();
        expect(calls.at(-1)[0]).toBe('delete');
        expect(calls.at(-1)[1]).toContain('projects.links.destroy');
        confirmed.mockRestore();
    });

    const baseLink = { id: 9, name: 'T', type: 'fill', url: 'http://x.test/fill/ABC', expires_at: null, visits: 0, last_visit: null, created: '2026-11-09' };
    const open = async (link) => {
        state.props = { auth: { user: { role: 'company_admin', name: 'Mona', email: 'm@x.test' } }, public: null, flash: {}, locale: 'en' };
        const el = await mount(Index, { studies: [study1({ fill_link: link })], colleagues: [] });
        el.querySelector('[data-action="template"]').click();
        await nextTick();
    };

    it('says Paused and offers Resume for a paused link', async () => {
        await open({ ...baseLink, closed: true, status: 'paused' });

        expect(modal().querySelector('[data-tpl-status]').textContent).toContain('Paused');
        expect(modal().querySelector('[data-action="tpl-toggle"]').textContent).toContain('Resume');
    });

    it('says Expired for a link whose date has passed', async () => {
        await open({ ...baseLink, closed: false, status: 'expired', expires_at: '2026-01-01' });

        expect(modal().querySelector('[data-tpl-status]').textContent).toContain('Expired');
        expect(modal().querySelector('#tpl-exp').value).toBe('2026-01-01');
    });
});
