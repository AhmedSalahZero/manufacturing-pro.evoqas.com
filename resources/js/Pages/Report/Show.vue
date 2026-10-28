<!--
  ZAVERO Manufacturing Pro — Printable report of a study ( /projects/{id}/report )
  Location: resources/js/Pages/Report/Show.vue
  One page that reads top to bottom, each part starting on a new printed page: the study summary and a contents list, every input page
  (products, raw materials, manpower plan, expenses, fixed assets and, for an existing company, opening balances), the yearly
  financial statements, then the results (KPIs, charts, notes).
  "Print / Save as PDF" opens the browser's print window (the approved print layout hides the menus and buttons and always prints light).
  Server side: App\Http\Controllers\ReportController.
-->
<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DashboardView from '@/Components/DashboardView.vue';
import ReportBlock from '@/Components/ReportBlock.vue';
import { useI18n } from '@/lang/i18n';
import { formatNumber } from '@/Utils/format';

const props = defineProps({
    project: { type: Object, required: true },
    ready: { type: Boolean, default: false },
    comments: { type: Object, default: () => ({}) },
    date: { type: String, default: '' },
    dashboard: { type: Object, default: null },
    statements: { type: Object, default: null },
    inputs: { type: Array, default: () => [] },
});

const { t } = useI18n();
const years = computed(() => props.statements?.years ?? []);
const label = (row) => t(`statements.rows.${row.key.replace(/[:-]/g, '_')}`);
const cell = (row, year) => {
    const n = Number(row.yearly?.[year.index] ?? 0);

    return row.style === 'percent' ? `${formatNumber(n, 1)}%` : formatNumber(n, 0);
};
const summary = computed(() => [
    ['start', props.project.start],
    ['duration', props.project.duration],
    ['currency', props.project.currency],
    ['tax', `${formatNumber(props.project.tax, 1)}%`],
    ['returnRate', `${formatNumber(props.project.return, 1)}%`],
    ['growth', `${formatNumber(props.project.growth, 1)}%`],
]);
const contents = computed(() => [
    'summary', ...props.inputs.map((s) => s.id), 'statements', 'results',
]);
const print = () => window.print();
</script>

<template>
    <AppLayout :title="t('report.title')" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ t('report.title') }}</h2>
                <p class="lead mb0"><Link :href="route('projects.edit', project.id)">← {{ project.name }}</Link></p>
            </div>
            <div v-if="ready" class="toolbar">
                <button type="button" class="btn" data-action="print" @click="print">{{ t('report.print') }}</button>
            </div>
        </div>

        <section v-if="!ready" class="card" style="--ca: var(--k5)">
            <p class="empty">{{ t('dashboard.notReady') }}</p>
            <p><Link :href="route('projects.edit', project.id)" class="btn btn-line sm">{{ t('dashboard.goToStudy') }}</Link></p>
        </section>

        <div v-else class="report">
            <section id="rep-summary" class="card" style="--ca: var(--k1)" data-section="summary">
                <h2>{{ project.name }}</h2>
                <p class="mute small">{{ t('report.preparedOn', { date }) }}</p>
                <p v-if="project.intro" style="white-space:pre-line">{{ project.intro }}</p>
                <div class="tiles">
                    <div v-for="[key, value] in summary" :key="key" class="tile" style="--ca: var(--k6)">
                        <span>{{ t(`report.summary.${key}`) }}</span><b dir="ltr">{{ value }}</b>
                    </div>
                </div>
            </section>

            <nav class="card rtoc" style="--ca: var(--k3)" data-report-contents>
                <h3>{{ t('report.contents') }}</h3>
                <ol>
                    <li v-for="id in contents" :key="id"><a :href="`#rep-${id}`">{{ t(`report.sections.${id}`) }}</a></li>
                </ol>
            </nav>

            <section v-for="sec in inputs" :id="`rep-${sec.id}`" :key="sec.id" class="card rsec" style="--ca: var(--k2)" :data-report-section="sec.id">
                <h3>{{ t(`report.sections.${sec.id}`) }}</h3>
                <ReportBlock v-for="(block, i) in sec.blocks" :key="i" :block="block" />
            </section>

            <div id="rep-statements" class="rsec" data-report-section="statements">
                <h3 class="rhead">{{ t('report.sections.statements') }}</h3>
            <section v-for="name in ['income', 'cash', 'balance']" :key="name" class="card" style="--ca: var(--k2)" :data-statement="name">
                <h3>{{ t(`statements.tabs.${name}`) }}</h3>
                <div class="tw nomax">
                    <table class="stm">
                        <thead>
                            <tr><th>{{ t(`statements.tabs.${name}`) }}</th><th v-for="y in years" :key="y.index" class="num" dir="ltr">{{ y.year }}</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in statements.tabs[name]" :key="row.key" :class="{ totalrow: row.style === 'total', pct: row.style === 'percent', checkrow: row.style === 'check' }">
                                <td>{{ label(row) }}</td>
                                <td v-for="y in years" :key="y.index" class="num" dir="ltr">
                                    <strong v-if="row.style === 'total'">{{ cell(row, y) }}</strong>
                                    <template v-else>{{ cell(row, y) }}</template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                </section>
            </div>

            <div id="rep-results" class="rsec" data-report-section="results">
                <h3 class="rhead">{{ t('report.sections.results') }}</h3>
            <DashboardView :dashboard="dashboard" :currency="project.currency" :all="true" />

            <section v-if="comments.one || comments.two" class="card" style="--ca: var(--k5)">
                <h3>{{ t('dashboard.notes.title') }}</h3>
                <p v-if="comments.one" style="white-space:pre-line">{{ comments.one }}</p>
                <p v-if="comments.two" style="white-space:pre-line">{{ comments.two }}</p>
            </section>

            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.totalrow td { border-top: 2px solid var(--line); background: color-mix(in srgb, var(--ca) 7%, var(--card)); }
.totalrow td:first-child { font-weight: 700; }
.pct td { color: var(--mute, #6b7280); font-size: 0.92em; font-style: italic; }
.checkrow td { border-top: 2px dashed var(--line-2); font-weight: 600; }

.rtoc ol { margin: 0.4rem 0 0; padding-inline-start: 1.3rem; columns: 2; }
.rtoc a { color: inherit; }
.rhead { margin: 1rem 0 0.6rem; }
@media print {
    .rsec { break-before: page; }
    .rtoc a { text-decoration: none; }
}
</style>

<!-- The paper layout (not scoped: it has to win over the screen styles of the cards, tables and charts). Landscape A4, no card frames,
     compact tables that keep their header row on every page, a part never cut in the middle of a table where it fits on a page. -->
<style>
@media print {
    main.page.wrap { max-width: none; padding: 0; margin: 0; }
    .report { font-size: 11px; }
    .report .card { border: 0; border-radius: 0; box-shadow: none; background: none; padding: 0; margin: 0 0 5mm; }
    .report .card > h3:first-child::before { display: none; }
    .report h2 { font-size: 20px; }
    .report h3 { font-size: 14px; margin: 0 0 3mm; break-after: avoid; }
    .report .rtitle { font-size: 12px; break-after: avoid; }
    .report .rhead { margin-top: 0; }

    .report .rsec { break-before: page; }
    .report #rep-summary { break-after: avoid; }
    .report .rtoc { break-inside: avoid; }
    .report .rblock { break-inside: avoid; margin-bottom: 4mm; }

    .report .tw { border-radius: 0; box-shadow: none; overflow: visible; max-height: none; }
    .report table { font-size: 10px; }
    .report .tw th, .report .tw td { padding: 3px 7px; font-size: 10px; line-height: 1.3; position: static; box-shadow: none; max-width: none; white-space: normal; }
    .report .tw td.num, .report .tw th.num { white-space: nowrap; }
    .report .tw tr { break-inside: avoid; }
    .report .tw thead { display: table-header-group; }
    .report .tw td:first-child { font-weight: 600; }

    .report [data-statement] { break-inside: auto; }
    .report [data-statement] + [data-statement] { break-before: page; }
    .report [data-statement] .tw td { white-space: nowrap; padding: 1px 7px; font-size: 9px; line-height: 1.25; }
    .report [data-statement] .tw th { padding: 2px 7px; font-size: 9px; }

    .report .tiles { grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 3mm; margin-bottom: 4mm; break-inside: avoid; }
    .report .tile { padding: 7px 10px; border-radius: 8px; box-shadow: none; }
    .report .tile > b { font-size: 16px; }
    .report .tile > span { font-size: 10px; }

    .report .sub { border-radius: 6px; padding: 8px 10px; margin-bottom: 5mm; break-inside: avoid; }
    .report div.grid2 { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6mm; }
    .report .col svg { max-height: 62mm; }
    .report [data-section="profit"], .report [data-section="costs"], .report [data-section="ratios"], .report [data-section="value"] { break-before: page; }
    .report [data-section] { break-inside: auto; }
}
</style>
