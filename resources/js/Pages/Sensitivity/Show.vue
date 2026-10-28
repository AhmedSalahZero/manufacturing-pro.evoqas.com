<!--
  ZAVERO Manufacturing Pro — What if …? ( /projects/{id}/sensitivity )
  Location: resources/js/Pages/Sensitivity/Show.vue
  Per product: change of the sales target (%), of the raw material cost (%) and of the collection time (days). The study itself is
  never changed: the server runs the whole calculation again with the changes and shows it next to the real figures.
  Server side: App\Http\Controllers\SensitivityController (App\Services\SensitivityService).
-->
<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ZChart from '@/Components/ZChart.vue';
import { useI18n } from '@/lang/i18n';
import { formatNumber } from '@/Utils/format';

const props = defineProps({
    project: { type: Object, required: true },
    ready: { type: Boolean, default: false },
    canEdit: { type: Boolean, default: false },
    rows: { type: Array, default: () => [] },
    compared: { type: Boolean, default: false },
    base: { type: Object, default: null },
    scenario: { type: Object, default: null },
});

const { t } = useI18n();
const form = useForm({ rows: props.rows.map((r) => ({ id: r.id, target: r.target, rm: r.rm, days: r.days })) });
const nameOf = (id) => props.rows.find((r) => r.id === id)?.name ?? '';
const save = () => form.put(route('projects.sensitivity.update', props.project.id), { preserveScroll: true });
const same = () => {
    const first = form.rows[0];
    form.rows = form.rows.map((r) => ({ ...r, target: first.target, rm: first.rm, days: first.days }));
};
const clear = () => { form.rows = form.rows.map((r) => ({ ...r, target: 0, rm: 0, days: 0 })); };

const labels = computed(() => props.base?.periods ?? []);
const total = (list) => list.reduce((a, b) => a + Number(b), 0);
const paybackText = (d) => (d.kpi.payback.months === null ? t('dashboard.never') : t('dashboard.paybackValue', { years: formatNumber(d.kpi.payback.years, 1), date: d.kpi.payback.date }));

const lines = computed(() => {
    if (!props.compared) return [];
    const b = props.base;
    const s = props.scenario;
    const money = (key, bv, sv) => ({ key, base: formatNumber(bv, 0), scenario: formatNumber(sv, 0), delta: bv ? ((sv - bv) / Math.abs(bv)) * 100 : null, good: sv >= bv });
    const revenue = money('revenue', total(b.revenue.value), total(s.revenue.value));
    const profit = money('netProfit', total(b.profit.net_profit.value), total(s.profit.net_profit.value));
    const npv = money('npv', b.kpi.npv, s.kpi.npv);
    const irrB = b.kpi.irr;
    const irrS = s.kpi.irr;
    const irr = { key: 'irr', base: irrB === null ? t('dashboard.noIrr') : `${formatNumber(irrB, 1)}%`, scenario: irrS === null ? t('dashboard.noIrr') : `${formatNumber(irrS, 1)}%`, delta: null, diff: irrB !== null && irrS !== null ? irrS - irrB : null, good: (irrS ?? -1e9) >= (irrB ?? -1e9) };
    const payback = { key: 'payback', base: paybackText(b), scenario: paybackText(s), delta: null, good: (s.kpi.payback.months ?? 1e9) <= (b.kpi.payback.months ?? 1e9) };

    return [revenue, profit, npv, irr, payback];
});
const change = (line) => (line.delta !== null ? `${line.delta > 0 ? '+' : ''}${formatNumber(line.delta, 1)}%` : line.diff !== null && line.diff !== undefined ? `${line.diff > 0 ? '+' : ''}${formatNumber(line.diff, 1)} ${t('sensitivity.points')}` : '');

const pair = (name, pick) => [
    { name: t('sensitivity.study'), kind: 'bar', values: pick(props.base), color: 'var(--c1)' },
    { name: t('sensitivity.scenario'), kind: 'bar', values: pick(props.scenario), color: 'var(--c2)' },
];
</script>

<template>
    <AppLayout :title="t('sensitivity.title')" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ t('sensitivity.title') }}</h2>
                <p class="lead mb0"><Link :href="route('projects.edit', project.id)">← {{ project.name }}</Link></p>
            </div>
            <div v-if="ready" class="toolbar">
                <Link :href="route('projects.dashboard', project.id)" class="btn btn-line sm">{{ t('sensitivity.toDashboard') }}</Link>
            </div>
        </div>

        <section v-if="!ready" class="card" style="--ca: var(--k5)">
            <p class="empty">{{ t('sensitivity.notReady') }}</p>
            <p><Link :href="route('projects.edit', project.id)" class="btn btn-line sm">{{ t('dashboard.goToStudy') }}</Link></p>
        </section>

        <template v-else>
            <div class="note" role="note">{{ t('sensitivity.lead') }}</div>

            <section class="card" style="--ca: var(--k2)">
                <form @submit.prevent="save">
                    <div class="tw nomax">
                        <table>
                            <thead>
                                <tr>
                                    <th>{{ t('sensitivity.product') }}</th>
                                    <th class="num">{{ t('sensitivity.target') }}</th>
                                    <th class="num">{{ t('sensitivity.rm') }}</th>
                                    <th class="num">{{ t('sensitivity.days') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in form.rows" :key="row.id" :data-product="row.id">
                                    <td>{{ nameOf(row.id) }}</td>
                                    <td class="num"><input v-model.number="row.target" class="inp" type="number" step="0.5" min="-100" max="1000" :readonly="!canEdit" :aria-label="`${nameOf(row.id)} — ${t('sensitivity.target')}`" dir="ltr"></td>
                                    <td class="num"><input v-model.number="row.rm" class="inp" type="number" step="0.5" min="-100" max="1000" :readonly="!canEdit" :aria-label="`${nameOf(row.id)} — ${t('sensitivity.rm')}`" dir="ltr"></td>
                                    <td class="num"><input v-model.number="row.days" class="inp" type="number" step="1" min="-365" max="365" :readonly="!canEdit" :aria-label="`${nameOf(row.id)} — ${t('sensitivity.days')}`" dir="ltr"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-for="(message, key) in form.errors" :key="key" class="dng small" role="alert">{{ message }}</p>
                    <p class="mute small">{{ t('sensitivity.hint') }}</p>
                    <div v-if="canEdit" class="toolbar">
                        <button type="submit" class="btn" :disabled="form.processing">{{ t('sensitivity.save') }}</button>
                        <button v-if="form.rows.length > 1" type="button" class="btn btn-line sm" data-action="same" @click="same">{{ t('sensitivity.same') }}</button>
                        <button type="button" class="btn btn-line sm" data-action="clear" @click="clear">{{ t('sensitivity.clear') }}</button>
                    </div>
                </form>
            </section>

            <section v-if="!compared" class="card" style="--ca: var(--k5)">
                <p class="empty">{{ t('sensitivity.noChanges') }}</p>
            </section>

            <template v-else>
                <section class="card" style="--ca: var(--k4)" data-section="compare">
                    <h3>{{ t('sensitivity.compare') }}</h3>
                    <div class="tw nomax">
                        <table>
                            <thead>
                                <tr><th></th><th class="num">{{ t('sensitivity.study') }}</th><th class="num">{{ t('sensitivity.scenario') }}</th><th class="num">{{ t('sensitivity.change') }}</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="line in lines" :key="line.key" :data-line="line.key">
                                    <td>{{ t(`sensitivity.lines.${line.key}`) }}</td>
                                    <td class="num" dir="ltr">{{ line.base }}</td>
                                    <td class="num" dir="ltr"><strong>{{ line.scenario }}</strong></td>
                                    <td class="num" dir="ltr" :style="{ color: line.good ? 'var(--green-ink)' : 'var(--red-ink)' }">{{ change(line) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="!scenario.dcf.terminal_ok || !base.dcf.terminal_ok" class="note dng" role="status">{{ t('dashboard.dcf.terminalMissing') }}</div>
                </section>

                <section class="card" style="--ca: var(--k1)">
                    <div class="grid2">
                        <ZChart :title="t('dashboard.charts.revenue')" :labels="labels" :series="pair('revenue', (d) => d.revenue.value)" />
                        <ZChart :title="t('dashboard.charts.net_profit')" :labels="labels" :series="pair('profit', (d) => d.profit.net_profit.value)" />
                        <ZChart :title="t('dashboard.dcf.rows.free_cash_flow')" :labels="labels" :series="pair('fcf', (d) => d.dcf.free_cash_flow)" />
                    </div>
                </section>
            </template>
        </template>
    </AppLayout>
</template>

<style scoped>
.grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 0 16px; }
</style>
