<!--
  ZAVERO Manufacturing Pro — StatementsTable (income statement, cash flow and balance sheet of a study)
  Location: resources/js/Components/StatementsTable.vue
  Four coloured tiles, the "does the balance sheet balance?" check and the three statements with one column per year
  (click a year to open its months). Used by the signed-in statements screen and by the public sharing-link page.
-->
<script setup>
import { computed, ref } from 'vue';
import { useI18n } from '@/lang/i18n';
import { formatNumber } from '@/Utils/format';

const props = defineProps({
    statements: { type: Object, default: null },
    taxRate: { type: Number, default: 0 },
    showRules: { type: Boolean, default: true },
});

const { t } = useI18n();

const tabs = ['income', 'cash', 'balance'];
const tab = ref('income');
const openYears = ref(new Set());

const years = computed(() => props.statements?.years ?? []);
const allOpen = computed(() => years.value.length > 0 && years.value.every((y) => openYears.value.has(y.index)));
const toggle = (index) => {
    const next = new Set(openYears.value);
    next.has(index) ? next.delete(index) : next.add(index);
    openYears.value = next;
};
const toggleAll = () => {
    openYears.value = allOpen.value ? new Set() : new Set(years.value.map((y) => y.index));
};

// One column per year, followed by its months when the year is open.
const columns = computed(() => years.value.flatMap((y) => [
    { kind: 'year', key: `y${y.index}`, index: y.index, label: String(y.year), open: openYears.value.has(y.index) },
    ...(openYears.value.has(y.index) ? y.months.map((m) => ({ kind: 'month', key: `m${m.index}`, index: m.index, label: `${y.year}-${String(m.month).padStart(2, '0')}` })) : []),
]));

const rows = computed(() => props.statements?.tabs?.[tab.value] ?? []);
const label = (row) => t(`statements.rows.${row.key.replace(/[:-]/g, '_')}`);
const cell = (row, col) => {
    const value = col.kind === 'year' ? row.yearly?.[col.index] : row.monthly?.[col.index];
    const n = Number(value ?? 0);

    return row.style === 'percent' ? `${formatNumber(n, 1)}%` : formatNumber(n, 0);
};
const rowClass = (row) => ({ totalrow: row.style === 'total', pct: row.style === 'percent', checkrow: row.style === 'check' });

const check = computed(() => props.statements?.check ?? { balanced: true, gap: 0 });
const tiles = computed(() => {
    const x = props.statements?.tiles;
    if (!x) return [];

    return [
        { key: 'revenue', value: formatNumber(x.revenue, 0), accent: '--k1' },
        { key: 'netProfit', value: formatNumber(x.net_profit, 0), accent: x.net_profit < 0 ? '--k4' : '--k3' },
        { key: 'netMargin', value: `${formatNumber(x.net_margin, 1)}%`, accent: '--k2' },
        { key: 'cashEnd', value: formatNumber(x.cash_end, 0), accent: '--k5' },
    ];
});
</script>

<template>
  <div>
        <div class="tiles">
            <div v-for="tile in tiles" :key="tile.key" class="tile" :style="{ '--ca': `var(${tile.accent})` }">
                <span>{{ t(`statements.tiles.${tile.key}`) }}</span><b dir="ltr">{{ tile.value }}</b>
            </div>
        </div>

        <div v-if="check.balanced" class="note ok" role="status" data-check="ok">{{ t('statements.check.ok') }}</div>
        <div v-else class="note bad dng" role="alert" data-check="bad">{{ t('statements.check.bad', { gap: formatNumber(check.gap, 0) }) }}</div>

        <section class="card" style="--ca: var(--k2)">
            <div class="toolbar">
                <span class="seg" role="group">
                    <button v-for="name in tabs" :key="name" type="button" class="btn" :class="{ 'btn-line': tab !== name }" :aria-pressed="tab === name" :data-tab="name" @click="tab = name">{{ t(`statements.tabs.${name}`) }}</button>
                </span>
                <button type="button" class="btn btn-line sm" @click="toggleAll">{{ allOpen ? t('statements.collapseAll') : t('statements.expandAll') }}</button>
            </div>
            <p class="mute small">{{ t(`statements.hint.${tab}`) }}</p>

            <div class="tw nomax">
                <table class="stm">
                    <thead>
                        <tr>
                            <th>{{ t(`statements.tabs.${tab}`) }}</th>
                            <th v-for="col in columns" :key="col.key" class="num" :class="col.kind === 'year' ? 'yr' : 'mo'" dir="ltr" @click="col.kind === 'year' && toggle(col.index)">
                                <template v-if="col.kind === 'year'">{{ col.open ? '▾' : '▸' }} </template>{{ col.label }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.key" :class="rowClass(row)" :data-row="row.key">
                            <td>{{ label(row) }}</td>
                            <td v-for="col in columns" :key="col.key" class="num" :class="{ mo: col.kind === 'month' }" dir="ltr">
                                <strong v-if="row.style === 'total'">{{ cell(row, col) }}</strong>
                                <template v-else>{{ cell(row, col) }}</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="showRules" class="card" style="--ca: var(--k6)">
            <h3>{{ t('statements.rules.title') }}</h3>
            <ul class="small">
                <li>{{ t('statements.rules.vat') }}</li>
                <li>{{ t('statements.rules.tax', { rate: formatNumber(taxRate, 1) }) }}</li>
                <li>{{ t('statements.rules.withhold') }}</li>
                <li>{{ t('statements.rules.cash') }}</li>
            </ul>
        </section>
      </div>
</template>

<style scoped>
.totalrow td { border-top: 2px solid var(--line); background: color-mix(in srgb, var(--ca) 7%, var(--card)); }
.totalrow td:first-child { font-weight: 700; }
.pct td { color: var(--mute, #6b7280); font-size: 0.92em; font-style: italic; }
.checkrow td { border-top: 2px dashed var(--line-2); color: var(--green-ink); font-weight: 600; }
.stm th.yr { color: var(--ink); }
</style>
