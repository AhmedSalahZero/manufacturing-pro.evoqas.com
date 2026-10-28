<!--
  ZAVERO Manufacturing Pro — DashboardView (the dashboard of a study, read-only)
  Location: resources/js/Components/DashboardView.vue
  Four KPI tiles (IRR, NPV, payback, total investment), then five tabs: Overview (revenue, growth, required investment),
  Profitability, Costs, Value (discounted cash flow) and Ratios (liquidity and activity).
  Used by the signed-in dashboard, the public sharing-link page and the printable report (`all` shows every tab one after the other).
  Data: App\Services\DashboardService::present().
-->
<script setup>
import { computed, ref } from 'vue';
import ZChart from '@/Components/ZChart.vue';
import { useI18n } from '@/lang/i18n';
import { formatNumber } from '@/Utils/format';

const props = defineProps({
    dashboard: { type: Object, required: true },
    currency: { type: String, default: '' },
    all: { type: Boolean, default: false },
});

const { t } = useI18n();
const tabs = ['overview', 'profit', 'costs', 'ratios', 'value'];
const tab = ref('overview');
const shows = (name) => props.all || tab.value === name;

const d = computed(() => props.dashboard);
// A one-year study is shown month by month (charts and tables of the overview, profitability and costs); the valuation and ratios stay yearly.
const src = computed(() => d.value);
const labels = computed(() => d.value.years.map((y) => String(y.year)));
const periods = computed(() => d.value.periods);
const n0 = (v) => formatNumber(v, 0);
const n1 = (v) => formatNumber(v, 1);
const n2 = (v) => formatNumber(v, 2);

const kpi = computed(() => d.value.kpi);
const tiles = computed(() => [
    { key: 'irr', value: kpi.value.irr === null ? t('dashboard.noIrr') : `${n1(kpi.value.irr)}%`, accent: '--k1' },
    { key: 'npv', value: n0(kpi.value.npv), accent: kpi.value.npv < 0 ? '--k4' : '--k3' },
    { key: 'payback', value: kpi.value.payback.months === null ? t('dashboard.never') : t('dashboard.paybackValue', { years: n1(kpi.value.payback.years), date: kpi.value.payback.date }), accent: '--k2' },
    { key: 'investment', value: n0(kpi.value.investment), accent: '--k6' },
]);

const investment = computed(() => [
    { key: 'fixed_assets', value: d.value.investment.fixed_assets, accent: '--k1' },
    { key: 'working_capital', value: d.value.investment.working_capital, accent: '--k2' },
    { key: 'equity', value: d.value.investment.equity, accent: '--k3' },
    { key: 'loans', value: d.value.investment.loans, accent: '--k4' },
]);

// The two blocks of the overview: each chart with a table of its numbers underneath (one row per period, so it fits half the width).
const overviewBlocks = computed(() => {
    const r = src.value.revenue;
    const c = src.value.cash;
    const ch = (k) => t(`dashboard.charts.${k}`);

    return [
        { key: 'revenue', items: [
            { chart: 'revenue', title: ch('revenue'), series: [{ name: ch('revenue'), kind: 'bar', values: r.value, color: 'var(--c1)' }, { name: ch('growth'), kind: 'line', secondary: true, values: r.growth, color: 'var(--c2)' }],
              rows: [{ label: ch('revenue'), values: r.value }, { label: ch('growth'), values: r.growth, percent: true }] },
            { chart: 'accumulated', title: ch('accumulated'), series: [{ name: ch('accumulated'), kind: 'line', values: r.accumulated, color: 'var(--c3)' }],
              rows: [{ label: ch('accumulated'), values: r.accumulated }] },
        ] },
        { key: 'cash', items: [
            { chart: 'cash', title: ch('cashInOut'), series: [{ name: ch('cashIn'), kind: 'line', values: c.in, color: 'var(--c3)' }, { name: ch('cashOut'), kind: 'line', values: c.out, color: 'var(--c5)' }],
              rows: [{ label: ch('cashIn'), values: c.in }, { label: ch('cashOut'), values: c.out }] },
            { chart: 'cash-accumulated', title: ch('cashAccumulated'), series: [{ name: ch('cashAccumulated'), kind: 'line', values: c.accumulated, color: 'var(--c4)' }],
              rows: [{ label: ch('cashAccumulated'), values: c.accumulated }] },
        ] },
    ];
});

const profitCharts = [
    { key: 'gross_profit', color: 'var(--c1)' }, { key: 'ebitda', color: 'var(--c3)' },
    { key: 'ebit', color: 'var(--c4)' }, { key: 'net_profit', color: 'var(--c2)' },
];
const costKeys = ['raw_material', 'direct_labor', 'manufacturing_overheads', 'marketing', 'sales', 'general'];

const dcfRows = ['ebit', 'taxes', 'depreciation', 'working_capital', 'capex', 'free_cash_flow', 'wacc', 'terminal_value', 'with_terminal', 'discount_factor', 'discounted'];
const dcfCell = (key, i) => {
    const v = d.value.dcf[key][i];

    return key === 'wacc' ? `${n2(v)}%` : key === 'discount_factor' ? formatNumber(v, 3) : n0(v);
};
const dcfClass = (key) => ({ totalrow: key === 'free_cash_flow' || key === 'with_terminal' || key === 'discounted' });

// The result of the valuation, under the table: enterprise value (= the sum of the discounted cash flows = NPV), IRR, payback, terminal share.
const summary = computed(() => {
    const k = d.value.kpi;
    const share = k.npv > 0 ? (d.value.dcf.terminal_pv / k.npv) * 100 : null;

    return [
        { key: 'enterprise', value: n0(k.npv), accent: k.npv < 0 ? '--k4' : '--k3' },
        { key: 'irr', value: k.irr === null ? t('dashboard.noIrr') : `${n1(k.irr)}%`, accent: '--k1' },
        { key: 'payback', value: k.payback.months === null ? t('dashboard.never') : t('dashboard.paybackValue', { years: n1(k.payback.years), date: k.payback.date }), accent: '--k2' },
        { key: 'terminalShare', value: share === null ? '-' : `${n1(share)}%`, accent: '--k6' },
    ];
});

const ratioGroups = [
    { key: 'liquidity', rows: [['current_ratio', 2, ':1'], ['quick_ratio', 2, ':1'], ['cash_ratio', 2, ':1'], ['working_capital', 0, '']] },
    { key: 'activity', rows: [['dso', 1, ''], ['dio', 1, ''], ['dpo', 1, ''], ['ccc', 1, '']] },
];
const ratioCell = (key, digits, mark, i) => `${formatNumber(d.value.ratios[key][i], digits)}${mark}`;
</script>

<template>
    <div class="dash">
        <div class="tiles">
            <div v-for="tile in tiles" :key="tile.key" class="tile" :style="{ '--ca': `var(${tile.accent})` }" :data-kpi="tile.key">
                <span>{{ t(`dashboard.tiles.${tile.key}`) }}</span><b dir="ltr">{{ tile.value }}</b>
            </div>
        </div>
        <p v-if="currency" class="mute small">{{ t('dashboard.currencyNote', { currency }) }}</p>

        <div v-if="!all" class="toolbar">
            <span class="seg" role="group">
                <button v-for="name in tabs" :key="name" type="button" class="btn" :class="{ 'btn-line': tab !== name }" :aria-pressed="tab === name" :data-tab="name" @click="tab = name">{{ t(`dashboard.tabs.${name}`) }}</button>
            </span>
        </div>

        <section v-if="shows('overview')" class="card" style="--ca: var(--k1)" data-section="overview">
            <h3 v-if="all">{{ t('dashboard.tabs.overview') }}</h3>
            <div v-for="block in overviewBlocks" :key="block.key" class="sub" :data-block="block.key">
                <h3>{{ t(`dashboard.sections.${block.key}`) }}</h3>
                <div class="grid2">
                    <div v-for="item in block.items" :key="item.chart" class="col">
                        <ZChart :title="item.title" :labels="periods" :data-chart="item.chart" :series="item.series" />
                        <div class="tw nomax">
                            <table class="stm" :data-table="item.chart">
                                <thead><tr><th>{{ item.title }}</th><th v-for="row in item.rows" :key="row.label" class="num">{{ row.label }}</th></tr></thead>
                                <tbody>
                                    <tr v-for="(l, i) in periods" :key="i">
                                        <td dir="ltr">{{ l }}</td>
                                        <td v-for="row in item.rows" :key="row.label" class="num" dir="ltr">{{ row.percent ? `${n1(row.values[i])}%` : n0(row.values[i]) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <h3>{{ t('dashboard.investment.title') }}</h3>
            <div class="tiles">
                <div v-for="item in investment" :key="item.key" class="tile" :style="{ '--ca': `var(${item.accent})` }">
                    <span>{{ t(`dashboard.investment.${item.key}`) }}</span><b dir="ltr">{{ n0(item.value) }}</b>
                </div>
            </div>
        </section>

        <section v-if="shows('profit')" class="card" style="--ca: var(--k3)" data-section="profit">
            <h3 v-if="all">{{ t('dashboard.tabs.profit') }}</h3>
            <div v-for="item in profitCharts" :key="item.key" class="pgrid" :data-profit="item.key">
                <ZChart
                    :title="t(`dashboard.charts.${item.key}`)" :labels="periods"
                    :series="[{ name: t(`dashboard.charts.${item.key}`), kind: 'bar', values: src.profit[item.key].value, color: item.color }, { name: t('dashboard.charts.margin'), kind: 'line', secondary: true, values: src.profit[item.key].percent, color: 'var(--c6)' }]"
                />
                <div class="tw nomax">
                    <table class="stm">
                        <thead><tr><th>{{ t(`dashboard.charts.${item.key}`) }}</th><th v-for="(l, i) in periods" :key="i" class="num" dir="ltr">{{ l }}</th></tr></thead>
                        <tbody>
                            <tr><td>{{ t('dashboard.table.value') }}</td><td v-for="(v, i) in src.profit[item.key].value" :key="i" class="num" dir="ltr">{{ n0(v) }}</td></tr>
                            <tr><td>{{ t('dashboard.table.percent') }}</td><td v-for="(v, i) in src.profit[item.key].percent" :key="i" class="num" dir="ltr">{{ n1(v) }}%</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section v-if="shows('costs')" class="card" style="--ca: var(--k2)" data-section="costs">
            <h3 v-if="all">{{ t('dashboard.tabs.costs') }}</h3>
            <ZChart
                :title="t('dashboard.charts.costsPercent')" :labels="periods" :percent-primary="true"
                :series="costKeys.map((key, k) => ({ name: t(`dashboard.costs.${key}`), kind: 'bar', values: src.costs[key].percent, color: `var(--c${k + 1})` }))"
            />
            <div class="tw nomax">
                <table class="stm">
                    <thead><tr><th>{{ t('dashboard.charts.costsPercent') }}</th><th v-for="(l, i) in periods" :key="i" class="num" dir="ltr">{{ l }}</th></tr></thead>
                    <tbody>
                        <tr v-for="key in costKeys" :key="key">
                            <td>{{ t(`dashboard.costs.${key}`) }}</td>
                            <td v-for="(v, i) in src.costs[key].percent" :key="i" class="num" dir="ltr">{{ n1(v) }}%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="shows('ratios')" class="card" style="--ca: var(--k6)" data-section="ratios">
            <div v-for="group in ratioGroups" :key="group.key" class="tw nomax">
                <table class="stm">
                    <thead><tr><th>{{ t(`dashboard.ratios.${group.key}`) }}</th><th v-for="(l, i) in labels" :key="i" class="num" dir="ltr">{{ l }}</th></tr></thead>
                    <tbody>
                        <tr v-for="[key, digits, mark] in group.rows" :key="key" :data-row="key">
                            <td>{{ t(`dashboard.ratios.${key}`) }}</td>
                            <td v-for="(v, i) in d.ratios[key]" :key="i" class="num" dir="ltr">{{ ratioCell(key, digits, mark, i) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mute small">{{ t('dashboard.ratios.hint') }}</p>
        </section>

        <section v-if="shows('value')" class="card" style="--ca: var(--k4)" data-section="value">
            <h3>{{ t('dashboard.dcf.title') }}</h3>
            <p class="mute small">{{ t('dashboard.dcf.hint') }}</p>
            <div v-if="!d.dcf.terminal_ok" class="note dng" role="status" data-note="terminal">{{ t('dashboard.dcf.terminalMissing') }}</div>
            <div class="tw nomax">
                <table class="stm">
                    <thead><tr><th>{{ t('dashboard.dcf.title') }}</th><th v-for="(l, i) in labels" :key="i" class="num" dir="ltr">{{ l }}</th></tr></thead>
                    <tbody>
                        <tr v-for="key in dcfRows" :key="key" :class="dcfClass(key)" :data-row="key">
                            <td>{{ t(`dashboard.dcf.rows.${key}`) }}</td>
                            <td v-for="(v, i) in d.dcf[key]" :key="i" class="num" dir="ltr">{{ dcfCell(key, i) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <h3 class="sumhead">{{ t('dashboard.dcf.summaryTitle') }}</h3>
            <div class="tiles" data-valuation="summary">
                <div v-for="tile in summary" :key="tile.key" class="tile" :style="{ '--tc': `var(${tile.accent})` }" :data-summary="tile.key">
                    <span>{{ t(`dashboard.dcf.summary.${tile.key}`) }}</span><b dir="ltr" :class="{ 't-sm': tile.key === 'payback' }">{{ tile.value }}</b>
                </div>
            </div>
            <p class="mute small">{{ t('dashboard.dcf.summaryNote') }}</p>
            <ZChart :title="t('dashboard.dcf.rows.free_cash_flow')" :labels="labels" :series="[{ name: t('dashboard.dcf.rows.free_cash_flow'), kind: 'bar', values: d.dcf.free_cash_flow, color: 'var(--c4)' }]" />
        </section>
    </div>
</template>

<style scoped>
.pgrid { display: grid; grid-template-columns: minmax(300px, 1fr) minmax(300px, 1.4fr); gap: 0 16px; align-items: center; margin-bottom: 8px; }
@media (max-width: 860px) { .pgrid { grid-template-columns: 1fr; } }
.sumhead { margin-top: 18px; }
.grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; align-items: start; }
@media (max-width: 860px) { .grid2 { grid-template-columns: minmax(0, 1fr); } }
.col { min-width: 0; }
.sub { border: 1px solid var(--line); border-radius: 14px; padding: 14px 16px 16px; margin-bottom: 16px; background: var(--card); }
.sub > h3 { margin-top: 0; }
.totalrow td { border-top: 2px solid var(--line); background: color-mix(in srgb, var(--ca) 7%, var(--card)); }
.totalrow td:first-child { font-weight: 700; }
</style>
