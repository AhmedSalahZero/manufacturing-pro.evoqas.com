<!--
  ZAVERO Manufacturing Pro — StudyNav (the second top bar, on every page of a study)
  Location: resources/js/Components/StudyNav.vue
  One tap from any page of the study to any other: the study form, Products (a drop-down, so ten products take no more room than one),
  the other data pages (numbered, in the order to fill them in), then the results (statements, dashboard, what-if, report) and links.
  The page you are on is highlighted. When the tabs do not all fit, the bar scrolls sideways (arrow buttons, a thin scroll bar, the mouse wheel or a swipe)
  and keeps the current page in view.
  The data comes from the shared prop "study" (App\Http\Middleware\HandleInertiaRequests).
-->
<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/lang/i18n';

const props = defineProps({ study: { type: Object, required: true } });

const { t } = useI18n();
const page = usePage();
const bar = ref(null);
const trigger = ref(null);
const open = ref(false);
const menuStyle = ref({});
const canLeft = ref(false);
const canRight = ref(false);

const path = (href) => new URL(href, 'http://zavero.local').pathname;
const here = computed(() => path(page.url ?? ''));
// A visitor of a public sharing link only looks: no report, no links page.
const isPublic = computed(() => !!page.props.public);
// A customer of a "fill" link only fills in the input pages: no statements, dashboard or what-if either.
const isFill = computed(() => page.props.public?.kind === 'fill');
const to = (name, extra) => route(name, extra ?? props.study.id);

const data = computed(() => {
    const s = props.study;
    const items = [];
    if (s.has_raw) items.push({ id: 'raw', href: to('projects.raw-materials.edit'), label: t('studyNav.raw') });
    items.push(
        { id: 'staff', href: to('projects.manpower.edit'), label: t('studyNav.staff') },
        { id: 'expenses', href: to('projects.expenses.edit'), label: t('studyNav.expenses') },
        { id: 'assets', href: to('projects.assets.edit'), label: t('studyNav.assets') },
    );
    if (!s.new_company) items.push({ id: 'opening', href: to('projects.opening.edit'), label: t('studyNav.opening') });
    // numbers: Products is 1, then the pages in order
    items.forEach((item, i) => { item.no = i + 2; });

    return items;
});
const results = computed(() => [
    { id: 'statements', href: to('projects.statements'), label: t('studyNav.statements') },
    { id: 'dashboard', href: to('projects.dashboard'), label: t('studyNav.dashboard') },
    { id: 'sensitivity', href: to('projects.sensitivity'), label: t('studyNav.sensitivity') },
    { id: 'report', href: to('projects.report'), label: t('studyNav.report') },
].filter((item) => !(isPublic.value && item.id === 'report') && !isFill.value));
const links = computed(() => ({ id: 'links', href: to('projects.links'), label: t('studyNav.links') }));
const studyHref = computed(() => to('projects.edit'));
const products = computed(() => props.study.products.map((p) => ({ ...p, href: route('projects.products.edit', [props.study.id, p.id]) })));

const on = (href) => here.value === path(href);
const onProducts = computed(() => here.value.includes(`/projects/${props.study.id}/products/`));
const currentProduct = computed(() => products.value.find((p) => on(p.href)));

const place = () => {
    if (!open.value) return;
    const r = trigger.value?.getBoundingClientRect();
    if (!r) return;
    const rtl = document.documentElement.dir === 'rtl';
    menuStyle.value = { top: `${r.bottom + 6}px`, [rtl ? 'right' : 'left']: `${rtl ? Math.max(8, window.innerWidth - r.right) : Math.max(8, r.left)}px` };
};
const toggle = async () => {
    open.value = !open.value;
    if (open.value) { await nextTick(); place(); }
};
const close = () => { open.value = false; };

// Which way can the tabs still be scrolled? (shows or hides the two arrow buttons; works left-to-right and right-to-left)
const measure = () => {
    const el = bar.value;
    if (!el) return;
    const max = el.scrollWidth - el.clientWidth;
    const pos = el.scrollLeft;
    if (max <= 2) { canLeft.value = false; canRight.value = false; return; }
    const rtl = document.documentElement.dir === 'rtl';
    canLeft.value = rtl ? pos > -max + 2 : pos > 2;
    canRight.value = rtl ? pos < -2 : pos < max - 2;
};
const onScroll = () => { place(); measure(); };
const nudge = (direction) => bar.value?.scrollBy?.({ left: direction * Math.max(160, bar.value.clientWidth * 0.6), behavior: 'smooth' });
// the mouse wheel moves the tabs sideways (a mouse has no sideways wheel)
const onWheel = (e) => {
    const el = bar.value;
    if (!el || el.scrollWidth <= el.clientWidth + 2 || Math.abs(e.deltaY) <= Math.abs(e.deltaX)) return;
    e.preventDefault();
    el.scrollLeft += e.deltaY;
};
let watcher = null;
const outside = (e) => { if (open.value && !e.target.closest?.('[data-products-menu], [data-products-trigger]')) close(); };
const keys = (e) => { if (e.key === 'Escape') close(); };

onMounted(() => {
    document.addEventListener('click', outside);
    document.addEventListener('keydown', keys);
    window.addEventListener('resize', close);
    bar.value?.querySelector('[aria-current="page"]')?.scrollIntoView?.({ inline: 'center', block: 'nearest' });
    measure();
    if (typeof ResizeObserver !== 'undefined' && bar.value) {
        watcher = new ResizeObserver(measure);
        watcher.observe(bar.value);
    }
    window.addEventListener('resize', measure);
});
onBeforeUnmount(() => {
    document.removeEventListener('click', outside);
    document.removeEventListener('keydown', keys);
    window.removeEventListener('resize', close);
    window.removeEventListener('resize', measure);
    watcher?.disconnect();
});
</script>

<template>
    <nav class="snav" :aria-label="t('studyNav.label')" data-study-nav>
        <div class="wrap snav-in">
            <span class="snav-name" :title="study.name">{{ study.name }}</span>
            <div class="snav-scroll">
                <button v-show="canLeft" type="button" class="snav-arrow snav-arrow-l" :aria-label="t('studyNav.scrollLeft')" data-nav-arrow="left" @click="nudge(-1)">‹</button>
                <div ref="bar" class="snav-bar" @scroll.passive="onScroll" @wheel="onWheel">
                <Link :href="studyHref" class="snav-i s-study" :class="{ on: on(studyHref) }" :aria-current="on(studyHref) ? 'page' : undefined" data-nav="study">{{ t('studyNav.study') }}</Link>

                <button v-if="products.length" ref="trigger" type="button" class="snav-i s-data" :class="{ on: onProducts }" :aria-current="onProducts ? 'page' : undefined"
                        aria-haspopup="true" :aria-expanded="open" data-products-trigger data-nav="products" @click="toggle">
                    <span class="snav-no">1</span>{{ currentProduct ? currentProduct.name : t('studyNav.products') }}
                    <span class="snav-count">{{ products.length }}</span><span class="snav-caret" aria-hidden="true">▾</span>
                </button>

                <Link v-for="item in data" :key="item.id" :href="item.href" class="snav-i s-data" :class="{ on: on(item.href) }" :aria-current="on(item.href) ? 'page' : undefined" :data-nav="item.id">
                    <span class="snav-no">{{ item.no }}</span>{{ item.label }}
                </Link>

                <span v-if="!isFill" class="snav-sep" aria-hidden="true" />
                <Link v-for="item in results" :key="item.id" :href="item.href" class="snav-i s-res" :class="{ on: on(item.href) }" :aria-current="on(item.href) ? 'page' : undefined" :data-nav="item.id">{{ item.label }}</Link>
                <span v-if="!isPublic" class="snav-sep" aria-hidden="true" />
                <Link v-if="!isPublic" :href="links.href" class="snav-i s-share" :class="{ on: on(links.href) }" :aria-current="on(links.href) ? 'page' : undefined" data-nav="links">{{ links.label }}</Link>
                </div>
                <button v-show="canRight" type="button" class="snav-arrow snav-arrow-r" :aria-label="t('studyNav.scrollRight')" data-nav-arrow="right" @click="nudge(1)">›</button>
            </div>
        </div>

        <Teleport to="body">
            <ul v-if="open" class="snav-menu" :style="menuStyle" role="menu" data-products-menu>
                <li v-for="p in products" :key="p.id" role="none">
                    <Link :href="p.href" role="menuitem" :class="{ on: on(p.href) }" data-product-link @click="close">{{ p.name }}</Link>
                </li>
            </ul>
        </Teleport>
    </nav>
</template>
