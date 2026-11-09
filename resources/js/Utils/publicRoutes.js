// ZAVERO Manufacturing Pro — the addresses of a study opened from a public sharing link.
// Location: resources/js/Utils/publicRoutes.js
// Inside the app a page links to the next one with route('projects.expenses.edit', id) → /projects/5/expenses.
// For a visitor of a public link the same names must lead to /shared/{code}/projects/5/expenses (a "view" link: looking only) or
// /fill/{code}/projects/5/expenses (a "fill" link: the customer fills in the input pages and saves them), with no sign-in.
// The server knows those addresses as "shared.<name>" and "fill.<name>"; this puts them under the plain names (with the code of the link
// inside), so every page, menu and button of the study works unchanged for the visitor.

const VIEW_PAGES = [
    'projects.edit', 'projects.products.edit', 'projects.raw-materials.edit', 'projects.manpower.edit', 'projects.expenses.edit',
    'projects.assets.edit', 'projects.opening.edit',
];

export const PUBLIC_ROUTE_NAMES = [...VIEW_PAGES, 'projects.statements', 'projects.dashboard', 'projects.sensitivity'];

// A customer fills in the input pages only (no statements, dashboard, what-if) and saves them.
export const FILL_ROUTE_NAMES = [
    ...VIEW_PAGES,
    'projects.update', 'projects.products.update', 'projects.raw-materials.update', 'projects.manpower.update', 'projects.expenses.update',
    'projects.assets.update', 'projects.opening.update',
];

/** @param {{routes?: Object}} config the Ziggy configuration (changed in place and returned) */
export function applyPublicRoutes(config, code, kind = 'view') {
    if (!config || !config.routes || !code) return config;

    const prefix = kind === 'fill' ? 'fill' : 'shared';
    const names = kind === 'fill' ? FILL_ROUTE_NAMES : PUBLIC_ROUTE_NAMES;

    for (const name of names) {
        const shared = config.routes[`${prefix}.${name}`];
        if (!shared) continue;

        config.routes[name] = {
            ...shared,
            uri: shared.uri.replace('{code}', code),
            parameters: (shared.parameters ?? []).filter((p) => p !== 'code'),
        };
    }

    return config;
}
