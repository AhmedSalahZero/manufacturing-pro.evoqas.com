// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Study bar tests (run: npm test)
//  Location: tests/js/studyNav.spec.js
//  Checks: every word of the bar exists in both languages; the bar lists the pages in order; products sit in a drop-down that opens
//  and closes; opening balances only for an existing company; the page you are on is marked.
// ══════════════════════════════════════════════════════════════════
import { describe, expect, it, vi } from 'vitest';
import { createApp, h, nextTick } from 'vue';

let url = '/projects/7/expenses';
vi.mock('@inertiajs/vue3', async () => {
    const vue = await import('vue');
    return {
        Link: vue.defineComponent({ props: ['href'], setup: (p, { slots, attrs }) => () => vue.h('a', { href: p.href, ...attrs }, slots.default?.()) }),
        usePage: () => ({ get url() { return url; }, props: {} }),
    };
});
globalThis.route = (name, p) => {
    const id = Array.isArray(p) ? p : [p];
    return ({
        'projects.edit': `/projects/${id[0]}/edit`, 'projects.products.edit': `/projects/${id[0]}/products/${id[1]}/edit`,
        'projects.raw-materials.edit': `/projects/${id[0]}/raw-materials`, 'projects.manpower.edit': `/projects/${id[0]}/manpower`,
        'projects.expenses.edit': `/projects/${id[0]}/expenses`, 'projects.assets.edit': `/projects/${id[0]}/fixed-assets`,
        'projects.opening.edit': `/projects/${id[0]}/opening-balances`, 'projects.statements': `/projects/${id[0]}/statements`,
        'projects.dashboard': `/projects/${id[0]}/dashboard`, 'projects.sensitivity': `/projects/${id[0]}/sensitivity`,
        'projects.report': `/projects/${id[0]}/report`, 'projects.links': `/projects/${id[0]}/links`,
    })[name];
};

const { default: StudyNav } = await import('@/Components/StudyNav.vue');
const { default: en } = await import('@/lang/en.js');
const { default: ar } = await import('@/lang/ar.js');

const study = (over = {}) => ({ id: 7, name: 'Nile Foods', new_company: false, has_raw: true, products: Array.from({ length: 10 }, (_, i) => ({ id: i + 1, name: `Product ${i + 1}` })), ...over });
const mount = async (props) => {
    const el = document.createElement('div');
    document.body.appendChild(el);
    createApp({ render: () => h(StudyNav, props) }).mount(el);
    await nextTick();

    return el;
};

describe('the study bar', () => {
    it('has its words in both languages', () => {
        for (const key of ['label', 'study', 'products', 'raw', 'staff', 'expenses', 'assets', 'opening', 'statements', 'dashboard', 'sensitivity', 'report', 'links']) {
            expect(typeof en.studyNav[key], `en ${key}`).toBe('string');
            expect(typeof ar.studyNav[key], `ar ${key}`).toBe('string');
        }
    });

    it('lists the pages in order and marks the current one', async () => {
        url = '/projects/7/expenses';
        const el = await mount({ study: study() });
        const order = [...el.querySelectorAll('[data-nav]')].map((n) => n.getAttribute('data-nav'));

        expect(order).toEqual(['study', 'products', 'raw', 'staff', 'expenses', 'assets', 'opening', 'statements', 'dashboard', 'sensitivity', 'report', 'links']);
        expect(el.querySelector('[aria-current="page"]').getAttribute('data-nav')).toBe('expenses');
        expect(el.querySelector('[data-nav="expenses"] .snav-no').textContent).toBe('4');
    });

    it('leaves out opening balances for a new company and raw materials when there are none', async () => {
        const el = await mount({ study: study({ new_company: true, has_raw: false }) });
        const order = [...el.querySelectorAll('[data-nav]')].map((n) => n.getAttribute('data-nav'));

        expect(order).not.toContain('opening');
        expect(order).not.toContain('raw');
    });

    it('keeps ten products in one drop-down that opens and closes', async () => {
        url = '/projects/7/dashboard';
        const el = await mount({ study: study() });

        expect(el.querySelectorAll('[data-nav="products"]').length).toBe(1);
        expect(document.querySelector('[data-products-menu]')).toBeNull();
        el.querySelector('[data-products-trigger]').click();
        await nextTick();
        expect(document.querySelectorAll('[data-product-link]').length).toBe(10);
        expect(document.querySelector('[data-products-trigger]').textContent).toContain('10');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
        await nextTick();
        expect(document.querySelector('[data-products-menu]')).toBeNull();
    });

    it('shows the product you are on in the drop-down button', async () => {
        url = '/projects/7/products/3/edit';
        const el = await mount({ study: study() });

        expect(el.querySelector('[data-products-trigger]').textContent).toContain('Product 3');
        expect(el.querySelector('[data-products-trigger]').getAttribute('aria-current')).toBe('page');
    });
});
