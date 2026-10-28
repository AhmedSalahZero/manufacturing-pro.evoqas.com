// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Main sections (the tabs of the top bar)
//  Location: resources/js/Layouts/navigation.js
//
//  Each step that adds a module adds its tab here:
//    { key, route (a Laravel route name), label (a key in lang/en.js and lang/ar.js), accent, roles? }
//  roles (optional) = who sees the tab: 'super_admin', 'company_admin', 'user'. Without it, everyone does.
//  accent = the section colour: blue | violet | green | orange | cyan
// ══════════════════════════════════════════════════════════════════

export const sections = [
    { key: 'home', route: 'home', label: 'nav.home', accent: 'blue' },
    { key: 'companies', route: 'admin.companies.index', label: 'nav.companies', accent: 'green', roles: ['super_admin'] },
    { key: 'projects', route: 'projects.index', label: 'nav.projects', accent: 'violet', roles: ['company_admin', 'user'] },
    { key: 'users', route: 'users.index', label: 'nav.users', accent: 'orange', roles: ['company_admin'] },
];

export const accents = ['blue', 'violet', 'green', 'orange', 'cyan'];

/** The tabs a person of this role sees. */
export const sectionsFor = (role) => sections.filter((s) => !s.roles || s.roles.includes(role));
