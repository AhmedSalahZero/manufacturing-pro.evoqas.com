<!--
  ZAVERO Manufacturing Pro — Manpower plan ( /projects/{id}/manpower )
  Location: resources/js/Pages/Manpower/Edit.vue
  Salary settings, then the positions of each manpower type in a compact table (position, net monthly salary, people
  today) with a "Hirings" button that opens a pop-up to type the people hired in each month of operation. This keeps the
  page short even with 50 positions. The direct labour and manufacturing overheads cost is shared between the products.
  Saved by App\Http\Controllers\ManpowerController@update (App\Services\CostPlanService), which recalculates the study.
  Hirings are saved by month position (0 = the study's first month); here they are shown as calendar months.
-->
<script setup>
import { computed, nextTick, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import CopyInput from '@/Components/CopyInput.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import { useI18n } from '@/lang/i18n';
import { formatNumber } from '@/Utils/format';

const props = defineProps({
    project: { type: Object, required: true },
    types: { type: Array, required: true },
    allocated: { type: Array, required: true },
    months: { type: Array, required: true },
    rows: { type: Array, required: true },
    products: { type: Array, required: true },
    allocations: { type: Object, required: true },
    byRevenue: { type: Object, required: true },
    results: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
});

const { t } = useI18n();
const ACCENTS = ['--k1', '--k3', '--k2', '--k4', '--k5', '--k6'];

const form = useForm({
    salaries_annual_increase: props.project.increase,
    salaries_tax_rate: props.project.taxRate,
    social_insurance_rate: props.project.insurance,
    rows: props.rows.map((r) => ({ ...r, hirings: { ...r.hirings }, key: `old-${r.id}` })),
    allocations: JSON.parse(JSON.stringify(props.allocations)),
    by_revenue: { ...props.byRevenue },
    after: 'stay',
});

// ── The calendar years of the operation, 12 month cells each ──
const years = computed(() => {
    const byYear = new Map();
    props.months.forEach((m) => {
        if (!byYear.has(m.year)) byYear.set(m.year, Array(12).fill(null));
        byYear.get(m.year)[m.month - 1] = m.index;
    });
    return [...byYear.entries()].map(([year, cells]) => ({ year, cells, extended: year > props.project.endYear }));
});
const order = computed(() => props.months.map((m) => m.index));

const invalid = (key) => !!form.errors[key];
const typeLabel = (type) => t(`manpower.types.${type}`);
const accent = (i) => `var(${ACCENTS[i % ACCENTS.length]})`;
const rowsOf = (type) => form.rows.map((r, i) => ({ r, i })).filter((x) => x.r.type === type);
const num = (v) => Number(v) || 0;
const hired = (row) => Object.values(row.hirings).reduce((s, v) => s + num(v), 0);

let counter = 0;
function addRow(type) {
    form.rows.push({ id: null, key: `new-${counter++}`, type, position: '', avg_salary: '', existing_count: 0, hirings: {} });
    nextTick(() => document.querySelector(`[data-type="${type}"] tbody tr:last-child input`)?.focus());
}

// ── Hirings pop-up ──
const editing = ref(null);
const editRow = computed(() => (editing.value === null ? null : form.rows[editing.value]));
function copyRight(row, index) {
    const from = order.value.indexOf(index);
    order.value.slice(from + 1).forEach((i) => { row.hirings[i] = row.hirings[index]; });
}
const hiringsError = (i) => Object.keys(form.errors).some((k) => k === `rows.${i}.hirings` || k.startsWith(`rows.${i}.hirings.`));

const removing = ref(null);
const doRemove = () => { form.rows.splice(removing.value, 1); removing.value = null; };

// Product shares for the types that are shared between products.
const shareTotal = (type) => props.products.reduce((s, p) => s + num(form.allocations[type]?.[p.id]), 0);
const usesShares = (type) => props.allocated.includes(type) && rowsOf(type).length > 0 && props.products.length > 0;

// ── Summary tiles ──
const peopleToday = computed(() => form.rows.reduce((s, r) => s + (props.project.newCompany ? 0 : num(r.existing_count)), 0));
const peopleHired = computed(() => form.rows.reduce((s, r) => s + hired(r), 0));

// ── Calculated results ──
const measure = ref('expense');
const openTypes = ref(new Set());
const toggle = (type) => { const n = new Set(openTypes.value); n.has(type) ? n.delete(type) : n.add(type); openTypes.value = n; };
const usedTypes = computed(() => props.types.filter((type) => form.rows.some((r) => r.type === type && r.id)));
const cellOf = (id, year, m = measure.value) => props.results.rows[id]?.[year]?.[m] ?? 0;
const typeTotal = (type, year) => form.rows.filter((r) => r.type === type && r.id).reduce((s, r) => s + cellOf(r.id, year), 0);
const grandTotal = (year) => usedTypes.value.reduce((s, type) => s + typeTotal(type, year), 0);
const show = (n) => formatNumber(n, measure.value === 'headcount' ? 1 : 0);
const firstYearCost = computed(() => {
    const y = props.results.years[0];
    return y === undefined ? 0 : form.rows.filter((r) => r.id).reduce((s, r) => s + cellOf(r.id, y, 'expense'), 0);
});

function save(after) {
    form.after = after;
    form.put(route('projects.manpower.update', props.project.id), {
        preserveScroll: true,
        onError: async () => {
            await nextTick();
            document.querySelector('[aria-invalid="true"], [role="alert"].dng')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },
    });
}
</script>

<template>
    <AppLayout :title="t('manpower.title')" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ t('manpower.title') }}</h2>
                <p class="lead mb0"><Link :href="route('projects.edit', project.id)">← {{ project.name }}</Link></p>
            </div>
        </div>

        <div v-if="form.hasErrors" class="note bad" role="alert">{{ t('products.fixErrors') }}</div>
        <div v-if="readOnly" class="note" role="status">{{ t('products.readOnly') }}</div>

        <div class="tiles">
            <div class="tile"><span>{{ t('manpower.tiles.positions') }}</span><b>{{ form.rows.length }}</b></div>
            <div class="tile"><span>{{ t('manpower.tiles.today') }}</span><b>{{ formatNumber(peopleToday, 0) }}</b></div>
            <div class="tile"><span>{{ t('manpower.tiles.hired') }}</span><b>{{ formatNumber(peopleHired, 0) }}</b></div>
            <div class="tile"><span>{{ t('manpower.tiles.cost', { year: results.years[0] ?? '—' }) }}</span><b>{{ formatNumber(firstYearCost, 0) }}</b></div>
        </div>

        <form novalidate @submit.prevent="save('stay')">
            <fieldset :disabled="readOnly" style="border:0;margin:0;padding:0;min-width:0">

                <section class="card" style="--ca: var(--k6)">
                    <h3>{{ t('manpower.settings.title') }}</h3>
                    <p class="mute small">{{ t('manpower.settings.hint') }}</p>
                    <div class="grid3 fgrid">
                        <div class="fld">
                            <label for="mp-increase">{{ t('manpower.settings.increase') }}</label>
                            <input id="mp-increase" v-model="form.salaries_annual_increase" class="inp" dir="ltr" type="number" step="any" min="0" max="1000" :aria-invalid="invalid('salaries_annual_increase')">
                            <span v-if="form.errors.salaries_annual_increase" class="dng small" role="alert">{{ form.errors.salaries_annual_increase }}</span>
                        </div>
                        <div class="fld">
                            <label for="mp-tax">{{ t('manpower.settings.tax') }}</label>
                            <input id="mp-tax" v-model="form.salaries_tax_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="invalid('salaries_tax_rate')">
                            <span v-if="form.errors.salaries_tax_rate" class="dng small" role="alert">{{ form.errors.salaries_tax_rate }}</span>
                        </div>
                        <div class="fld">
                            <label for="mp-ins">{{ t('manpower.settings.insurance') }}</label>
                            <input id="mp-ins" v-model="form.social_insurance_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="invalid('social_insurance_rate')">
                            <span v-if="form.errors.social_insurance_rate" class="dng small" role="alert">{{ form.errors.social_insurance_rate }}</span>
                        </div>
                    </div>
                </section>

                <section v-for="(type, n) in types" :key="type" class="card typecard" :data-type="type" :style="{ '--ca': accent(n) }">
                    <div class="row between">
                        <h3>{{ typeLabel(type) }}</h3>
                        <span class="badge">{{ t('manpower.positionsCount', { n: rowsOf(type).length }) }}</span>
                    </div>
                    <p class="mute small">{{ t(`manpower.typeHint.${type}`) }}</p>

                    <div v-if="rowsOf(type).length" class="tw nomax">
                        <table class="stm plan">
                            <thead>
                                <tr>
                                    <th>{{ t('manpower.position') }}</th>
                                    <th>{{ t('manpower.salary') }}</th>
                                    <th>{{ t('manpower.existing') }}</th>
                                    <th>{{ t('manpower.hiringsButton') }}</th>
                                    <th><span class="sr">{{ t('projects.form.remove') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="{ r, i } in rowsOf(type)" :key="r.key">
                                    <td class="c-pos">
                                        <input v-model="r.position" class="inp" type="text" maxlength="255" :aria-label="t('manpower.position')" :aria-invalid="invalid(`rows.${i}.position`)">
                                        <span v-if="form.errors[`rows.${i}.position`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.position`] }}</span>
                                    </td>
                                    <td class="c-num">
                                        <input v-model="r.avg_salary" class="inp" dir="ltr" type="number" step="any" min="0" :aria-label="t('manpower.salary')" :aria-invalid="invalid(`rows.${i}.avg_salary`)">
                                        <span v-if="form.errors[`rows.${i}.avg_salary`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.avg_salary`] }}</span>
                                    </td>
                                    <td class="c-cnt">
                                        <input v-model="r.existing_count" class="inp" dir="ltr" type="number" step="any" min="0" :disabled="project.newCompany" :title="project.newCompany ? t('manpower.existingNew') : ''" :aria-label="t('manpower.existing')" :aria-invalid="invalid(`rows.${i}.existing_count`)">
                                    </td>
                                    <td class="c-hire">
                                        <button type="button" class="hirebtn" :class="{ has: hired(r) > 0, bad: hiringsError(i) }" :aria-invalid="hiringsError(i)" @click="editing = i">
                                            {{ hired(r) > 0 ? t('manpower.hiringsCount', { n: formatNumber(hired(r), 0) }) : t('manpower.hiringsNone') }}
                                            <span aria-hidden="true">✎</span>
                                        </button>
                                        <span v-if="form.errors[`rows.${i}.hirings`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.hirings`] }}</span>
                                    </td>
                                    <td class="c-del">
                                        <button type="button" class="xbtn" :title="t('projects.form.remove')" :aria-label="t('projects.form.remove')" @click="removing = i">✕</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <button type="button" class="btn btn-line sm mt2" @click="addRow(type)">+ {{ t('manpower.add') }}</button>

                    <!-- How this cost is shared between the products -->
                    <div v-if="usesShares(type)" class="share">
                        <h4>{{ t('manpower.share.title') }}</h4>
                        <label class="chk">
                            <input v-model="form.by_revenue[type]" type="checkbox">
                            {{ t('manpower.share.byRevenue') }}
                        </label>
                        <div v-if="!form.by_revenue[type]" class="grid3 fgrid">
                            <div v-for="p in products" :key="p.id" class="fld">
                                <label :for="`mp-${type}-${p.id}`">{{ p.name }}</label>
                                <input :id="`mp-${type}-${p.id}`" v-model="form.allocations[type][p.id]" class="inp" dir="ltr" type="number" step="any" min="0" max="100">
                            </div>
                            <p class="small" :class="Math.abs(shareTotal(type) - 100) > 0.01 ? 'dng' : 'mute'">{{ t('manpower.share.total', { n: formatNumber(shareTotal(type), 2) }) }}</p>
                        </div>
                        <span v-if="form.errors[`allocations.${type}`]" class="dng small" role="alert">{{ form.errors[`allocations.${type}`] }}</span>
                    </div>
                </section>

                <!-- Calculated results -->
                <section class="card" style="--ca: var(--k3)">
                    <h3>{{ t('manpower.results.title') }}</h3>
                    <p class="mute small">{{ t('manpower.results.hint') }}</p>
                    <div v-if="results.years.length && usedTypes.length" class="tw nomax">
                        <p class="mb2">
                            <span class="seg" role="group">
                                <button v-for="m in ['expense', 'payments', 'headcount']" :key="m" type="button" class="btn" :class="{ 'btn-line': measure !== m }" :aria-pressed="measure === m" @click="measure = m">{{ t(`manpower.results.${m}`) }}</button>
                            </span>
                        </p>
                        <table class="stm">
                            <thead>
                                <tr>
                                    <th>{{ t('manpower.results.type') }}</th>
                                    <th v-for="y in results.years" :key="y" class="num" dir="ltr">{{ y }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="type in usedTypes" :key="type">
                                    <tr>
                                        <td>
                                            <button type="button" class="rowtoggle" :aria-expanded="openTypes.has(type)" :aria-label="openTypes.has(type) ? t('products.results.collapse') : t('products.results.expand')" @click="toggle(type)">{{ openTypes.has(type) ? '▾' : '▸' }}</button>
                                            {{ typeLabel(type) }}
                                        </td>
                                        <td v-for="y in results.years" :key="y" class="num" dir="ltr">{{ show(typeTotal(type, y)) }}</td>
                                    </tr>
                                    <template v-if="openTypes.has(type)">
                                        <tr v-for="r in form.rows.filter((x) => x.type === type && x.id)" :key="r.id" class="subrow">
                                            <td>{{ r.position }}</td>
                                            <td v-for="y in results.years" :key="y" class="num" dir="ltr">{{ show(cellOf(r.id, y)) }}</td>
                                        </tr>
                                    </template>
                                </template>
                                <tr class="totalrow">
                                    <td><strong>{{ t('manpower.results.total') }}</strong></td>
                                    <td v-for="y in results.years" :key="y" class="num" dir="ltr"><strong>{{ show(grandTotal(y)) }}</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="empty">{{ t('manpower.results.none') }}</p>
                </section>
            </fieldset>

            <div class="toolbar">
                <button v-if="!readOnly" type="submit" class="btn" :disabled="form.processing">{{ form.processing ? t('products.actions.saving') : t('products.actions.save') }}</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" @click="save('next')">{{ t('manpower.saveNext') }} →</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" @click="save('list')">{{ t('products.actions.saveClose') }}</button>
                <Link :href="route('projects.raw-materials.edit', project.id)" class="btn btn-line">← {{ t('manpower.back') }}</Link>
                <Link :href="route('projects.edit', project.id)" class="btn btn-line">{{ t('common.cancel') }}</Link>
            </div>
        </form>

        <!-- Hirings of one position, month by month -->
        <ModalDialog :open="editRow !== null" wide :title="t('manpower.hiringsTitle', { name: editRow?.position || t('manpower.position') })" :subtitle="t('manpower.hiringsHint')" @close="editing = null">
            <div v-if="editRow" class="tw nomax">
                <table class="stm hire">
                    <thead>
                        <tr>
                            <th>{{ t('products.results.year') }}</th>
                            <th v-for="m in 12" :key="m" class="num">{{ t(`projects.months.${m}`) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="y in years" :key="y.year">
                            <td dir="ltr">{{ y.year }}<span v-if="y.extended" class="mute small"> · {{ t('manpower.extendedYear') }}</span></td>
                            <td v-for="(index, m) in y.cells" :key="m">
                                <CopyInput
                                    v-if="index !== null" v-model="editRow.hirings[index]"
                                    :aria-label="`${editRow.position} ${t(`projects.months.${m + 1}`)} ${y.year}`"
                                    :copy="index !== order[order.length - 1]" @copy="copyRight(editRow, index)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <template #footer>
                <span v-if="editRow" class="mute small grow">{{ t('manpower.hiringsTotal', { n: formatNumber(hired(editRow), 0) }) }}</span>
            </template>
        </ModalDialog>

        <ConfirmDialog :open="removing !== null" :title="t('manpower.removeTitle')" :confirm-label="t('projects.form.removeAction')" danger @confirm="doRemove" @cancel="removing = null">
            <p>{{ t('manpower.removeText') }}</p>
        </ConfirmDialog>
    </AppLayout>
</template>

<style scoped>
.typecard { background: linear-gradient(180deg, color-mix(in srgb, var(--ca) 7%, var(--card)), var(--card) 90px); }
.row.between { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.row.between h3 { margin-bottom: 0; }
.plan th, .plan td { vertical-align: middle; }
.plan td { padding: 6px 8px; }
.c-pos { min-width: 220px; }
.c-num { min-width: 140px; width: 18%; }
.c-cnt { min-width: 110px; width: 12%; }
.c-hire { min-width: 150px; width: 18%; }
.c-del { width: 44px; text-align: center; }
.hirebtn {
    display: inline-flex; align-items: center; gap: 8px; width: 100%; justify-content: space-between;
    border: 1.5px dashed var(--line-2); background: var(--card); color: var(--mute); border-radius: var(--r-sm);
    padding: 8px 12px; cursor: pointer; font: inherit; font-weight: 600;
}
.hirebtn:hover { border-color: var(--ca); color: var(--ink); }
.hirebtn.has { border-style: solid; border-color: var(--ca); background: color-mix(in srgb, var(--ca) 12%, var(--card)); color: var(--ink); }
.hirebtn.bad { border-color: var(--red); }
.xbtn { border: 0; background: transparent; color: var(--faint); cursor: pointer; border-radius: 8px; padding: 6px 9px; }
.xbtn:hover { background: var(--red-soft); color: var(--red); }
.sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
.hire td { min-width: 104px; }
.grow { margin-inline-end: auto; }
.share { margin-top: 14px; padding-top: 10px; border-top: 1px dashed var(--line-2); }
.chk { display: flex; gap: 8px; align-items: center; margin: 6px 0 10px; }
.rowtoggle { border: 0; background: transparent; color: var(--blue); cursor: pointer; padding: 0 6px; }
.subrow td { color: var(--mute, #6b7280); font-size: 0.92em; }
.subrow td:first-child { padding-inline-start: 28px; }
.totalrow td { border-top: 2px solid var(--line); }
</style>
