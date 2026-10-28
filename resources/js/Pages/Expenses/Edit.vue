<!--
  ZAVERO Manufacturing Pro — Expenses of a study ( /projects/{id}/expenses )
  Location: resources/js/Pages/Expenses/Edit.vue
  Four kinds of expense: fixed monthly amount, one-time amount (amortised over months), % of sales, cost per unit sold.
  Each belongs to a category, has its months, its payment terms (cash, quarterly, semi-annually, annually or customised in
  a pop-up) and - for the manufacturing and other operation categories - how it is shared between the products.
  Saved by App\Http\Controllers\ExpenseController@update (App\Services\CostPlanService), which recalculates the study.
  Months are saved by position in the study (0 = the study's first month).
-->
<script setup>
import { computed, nextTick, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import MonthYearInput from '@/Components/MonthYearInput.vue';
import { useI18n } from '@/lang/i18n';
import { formatNumber } from '@/Utils/format';

const props = defineProps({
    project: { type: Object, required: true },
    categories: { type: Array, required: true },
    kinds: { type: Array, required: true },
    products: { type: Array, required: true },
    rows: { type: Array, required: true },
    results: { type: Object, required: true },
    options: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
});

const { t } = useI18n();
const TERMS = ['cash', 'quarterly', 'semi-annually', 'annually', 'customize'];
const ACCENTS = { fixed_monthly_repeating_amount: '--k1', one_time_expense: '--k2', expense_as_percentage: '--k4', cost_per_unit: '--k3' };

const form = useForm({
    rows: props.rows.map((r) => ({ ...r, products: [...r.products], custom: r.custom.map((c) => ({ ...c })), allocations: { ...r.allocations }, key: `old-${r.id}` })),
    after: 'stay',
});

const invalid = (key) => !!form.errors[key];
const kindLabel = (k) => t(`expenses.kinds.${k}`);
const categoryLabel = (c) => t(`expenses.categories.${c}`);
const accent = (kind) => `var(${ACCENTS[kind]})`;
const isAllocated = (row) => props.categories.find((c) => c.id === row.category_id)?.allocated ?? false;
const isShared = (row) => isAllocated(row) && ['fixed_monthly_repeating_amount', 'one_time_expense'].includes(row.relation_name) && props.products.length > 0;
const rowsOf = (kind) => form.rows.map((r, i) => ({ r, i })).filter((x) => x.r.relation_name === kind);

let counter = 0;
function addRow(kind) {
    form.rows.push({
        id: null, key: `new-${counter++}`, relation_name: kind, category_id: props.categories[0]?.id ?? '', name: '',
        amount: '', start: `${props.project.startYear}-01`, end: `${props.project.endYear}-12`,
        increase_rate: 0, monthly_percentage: '', monthly_cost_of_unit: '', amortization_months: 12,
        products: props.products.map((p) => p.id), payment_terms: 'cash',
        custom: [{ due_days: '', rate: '' }],
        is_as_revenue_percentages: true, allocations: Object.fromEntries(props.products.map((p) => [p.id, 0])),
    });
    nextTick(() => document.querySelector(`[data-kind="${kind}"] .rep:last-of-type input`)?.focus());
}

const removing = ref(null);
const doRemove = () => { form.rows.splice(removing.value, 1); removing.value = null; };

// ── Customised payment terms pop-up ──
const editing = ref(null);
const editRow = computed(() => (editing.value === null ? null : form.rows[editing.value]));
const customTotal = (row) => row.custom.reduce((s, c) => s + (Number(c.rate) || 0), 0);
const customCount = (row) => row.custom.filter((c) => c.due_days !== '' && c.due_days !== null && c.rate !== '' && c.rate !== null).length;
const canAddLine = computed(() => editRow.value && editRow.value.custom.length < props.options.slots);
const addLine = () => editRow.value.custom.push({ due_days: '', rate: '' });
const removeLine = (n) => { editRow.value.custom.splice(n, 1); if (!editRow.value.custom.length) addLine(); };
const customError = (i) => Object.keys(form.errors).some((k) => k === `rows.${i}.custom` || k.startsWith(`rows.${i}.custom.`));
function chooseTerms(row, i) {
    if (row.payment_terms === 'customize') editing.value = i;
}

const shareTotal = (row) => props.products.reduce((s, p) => s + (Number(row.allocations[p.id]) || 0), 0);
function toggleProduct(row, id) {
    const at = row.products.indexOf(id);
    at === -1 ? row.products.push(id) : row.products.splice(at, 1);
}
const termLabel = (term) => t(`expenses.terms.${term}`);
const daysLabel = (d) => (d === 0 ? t('expenses.sameMonth') : t('expenses.afterDays', { n: d }));

// ── Calculated results: categories open to show their expenses ──
const measure = ref('expense');
const openCats = ref(new Set());
const toggle = (c) => { const n = new Set(openCats.value); n.has(c) ? n.delete(c) : n.add(c); openCats.value = n; };
const saved = computed(() => form.rows.filter((r) => r.id));
const usedCategories = computed(() => props.categories.map((c) => c.id).filter((c) => saved.value.some((r) => r.category_id === c)));
const cell = (id, year) => props.results.rows[id]?.[year]?.[measure.value] ?? 0;
const catTotal = (c, year) => saved.value.filter((r) => r.category_id === c).reduce((s, r) => s + cell(r.id, year), 0);
const grandTotal = (year) => usedCategories.value.reduce((s, c) => s + catTotal(c, year), 0);
const show = (n) => formatNumber(n, 0);

function save(after) {
    form.after = after;
    form.put(route('projects.expenses.update', props.project.id), {
        preserveScroll: true,
        onError: async () => {
            await nextTick();
            document.querySelector('[aria-invalid="true"], [role="alert"].dng')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },
    });
}
</script>

<template>
    <AppLayout :title="t('expenses.title')" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ t('expenses.title') }}</h2>
                <p class="lead mb0"><Link :href="route('projects.edit', project.id)">← {{ project.name }}</Link></p>
            </div>
        </div>

        <div v-if="form.hasErrors" class="note bad" role="alert">{{ t('products.fixErrors') }}</div>
        <div v-if="readOnly" class="note" role="status">{{ t('products.readOnly') }}</div>

        <div class="tiles">
            <div v-for="k in kinds" :key="k" class="tile"><span>{{ kindLabel(k) }}</span><b>{{ rowsOf(k).length }}</b></div>
        </div>

        <form novalidate @submit.prevent="save('stay')">
            <fieldset :disabled="readOnly" style="border:0;margin:0;padding:0;min-width:0">

                <section v-for="kind in kinds" :key="kind" class="card kindcard" :data-kind="kind" :style="{ '--ca': accent(kind) }">
                    <h3>{{ kindLabel(kind) }}</h3>
                    <p class="mute small">{{ t(`expenses.kindHint.${kind}`) }}</p>

                    <div v-for="{ r, i } in rowsOf(kind)" :key="r.key" class="rep">
                        <div class="repTop">
                            <div class="fld w2">
                                <label :for="`ex-${i}-name`">{{ t('expenses.name') }}</label>
                                <input :id="`ex-${i}-name`" v-model="r.name" class="inp" type="text" maxlength="255" :aria-invalid="invalid(`rows.${i}.name`)">
                                <span v-if="form.errors[`rows.${i}.name`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.name`] }}</span>
                            </div>
                            <div class="fld w2">
                                <label :for="`ex-${i}-cat`">{{ t('expenses.category') }}</label>
                                <select :id="`ex-${i}-cat`" v-model="r.category_id" class="sel" :aria-invalid="invalid(`rows.${i}.category_id`)">
                                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ categoryLabel(c.id) }}</option>
                                </select>
                                <span v-if="form.errors[`rows.${i}.category_id`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.category_id`] }}</span>
                            </div>

                            <div v-if="kind === 'fixed_monthly_repeating_amount' || kind === 'one_time_expense'" class="fld">
                                <label :for="`ex-${i}-amt`">{{ kind === 'one_time_expense' ? t('expenses.amountOnce') : t('expenses.amountMonthly') }}</label>
                                <input :id="`ex-${i}-amt`" v-model="r.amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="invalid(`rows.${i}.amount`)">
                                <span v-if="form.errors[`rows.${i}.amount`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.amount`] }}</span>
                            </div>
                            <div v-if="kind === 'fixed_monthly_repeating_amount'" class="fld">
                                <label :for="`ex-${i}-inc`">{{ t('expenses.increase') }}</label>
                                <input :id="`ex-${i}-inc`" v-model="r.increase_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="1000" :aria-invalid="invalid(`rows.${i}.increase_rate`)">
                                <span v-if="form.errors[`rows.${i}.increase_rate`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.increase_rate`] }}</span>
                            </div>
                            <div v-if="kind === 'one_time_expense'" class="fld">
                                <label :for="`ex-${i}-am`">{{ t('expenses.amortization') }}</label>
                                <input :id="`ex-${i}-am`" v-model="r.amortization_months" class="inp" dir="ltr" type="number" step="1" min="1" max="600" :aria-invalid="invalid(`rows.${i}.amortization_months`)">
                                <span v-if="form.errors[`rows.${i}.amortization_months`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.amortization_months`] }}</span>
                            </div>
                            <div v-if="kind === 'expense_as_percentage'" class="fld">
                                <label :for="`ex-${i}-pct`">{{ t('expenses.percentage') }}</label>
                                <input :id="`ex-${i}-pct`" v-model="r.monthly_percentage" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="invalid(`rows.${i}.monthly_percentage`)">
                                <span v-if="form.errors[`rows.${i}.monthly_percentage`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.monthly_percentage`] }}</span>
                            </div>
                            <div v-if="kind === 'cost_per_unit'" class="fld">
                                <label :for="`ex-${i}-unit`">{{ t('expenses.perUnit') }}</label>
                                <input :id="`ex-${i}-unit`" v-model="r.monthly_cost_of_unit" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="invalid(`rows.${i}.monthly_cost_of_unit`)">
                                <span v-if="form.errors[`rows.${i}.monthly_cost_of_unit`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.monthly_cost_of_unit`] }}</span>
                            </div>

                            <div class="fld w2">
                                <label>{{ kind === 'one_time_expense' ? t('expenses.paidIn') : t('expenses.from') }}</label>
                                <MonthYearInput v-model="r.start" :min-year="project.startYear" :max-year="project.endYear" :invalid="invalid(`rows.${i}.start`)" />
                                <span v-if="form.errors[`rows.${i}.start`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.start`] }}</span>
                            </div>
                            <div v-if="kind !== 'one_time_expense'" class="fld w2">
                                <label>{{ t('expenses.to') }}</label>
                                <MonthYearInput v-model="r.end" :min-year="project.startYear" :max-year="project.endYear" :invalid="invalid(`rows.${i}.end`)" />
                                <span v-if="form.errors[`rows.${i}.end`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.end`] }}</span>
                            </div>

                            <div class="fld w2">
                                <label :for="`ex-${i}-terms`">{{ t('expenses.paymentTerms') }}</label>
                                <div class="termsRow">
                                    <select :id="`ex-${i}-terms`" v-model="r.payment_terms" class="sel" :aria-invalid="invalid(`rows.${i}.payment_terms`)" @change="chooseTerms(r, i)">
                                        <option v-for="term in TERMS" :key="term" :value="term">{{ termLabel(term) }}</option>
                                    </select>
                                    <button v-if="r.payment_terms === 'customize'" type="button" class="hirebtn" :class="{ has: customCount(r) > 0 && Math.abs(customTotal(r) - 100) <= 0.01, bad: customError(i) }" :aria-invalid="customError(i)" @click="editing = i">
                                        {{ customCount(r) > 0 ? t('expenses.paymentsCount', { n: customCount(r) }) : t('expenses.paymentsSet') }} <span aria-hidden="true">✎</span>
                                    </button>
                                </div>
                                <span v-if="form.errors[`rows.${i}.custom`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.custom`] }}</span>
                                <span v-if="form.errors[`rows.${i}.payment_terms`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.payment_terms`] }}</span>
                            </div>

                            <button type="button" class="btn btn-line sm delbtn" @click="removing = i">{{ t('projects.form.remove') }}</button>
                        </div>

                        <!-- Products the percentage / unit cost is worked on -->
                        <div v-if="kind === 'expense_as_percentage' || kind === 'cost_per_unit'" class="blk">
                            <strong class="small">{{ t('expenses.productsTitle') }}</strong>
                            <div class="chips">
                                <label v-for="p in products" :key="p.id" class="pick" :class="{ on: r.products.includes(p.id) }">
                                    <input type="checkbox" :checked="r.products.includes(p.id)" @change="toggleProduct(r, p.id)"> {{ p.name }}
                                </label>
                            </div>
                            <span v-if="form.errors[`rows.${i}.products`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.products`] }}</span>
                        </div>

                        <!-- Sharing between products -->
                        <div v-if="isShared(r)" class="blk">
                            <strong class="small">{{ t('expenses.share.title') }}</strong>
                            <label class="chk"><input v-model="r.is_as_revenue_percentages" type="checkbox"> {{ t('expenses.share.byRevenue') }}</label>
                            <div v-if="!r.is_as_revenue_percentages" class="grid3 fgrid">
                                <div v-for="p in products" :key="p.id" class="fld">
                                    <label :for="`ex-${i}-sh-${p.id}`">{{ p.name }}</label>
                                    <input :id="`ex-${i}-sh-${p.id}`" v-model="r.allocations[p.id]" class="inp" dir="ltr" type="number" step="any" min="0" max="100">
                                </div>
                                <p class="small" :class="Math.abs(shareTotal(r) - 100) > 0.01 ? 'dng' : 'mute'">{{ t('manpower.share.total', { n: formatNumber(shareTotal(r), 2) }) }}</p>
                            </div>
                            <span v-if="form.errors[`rows.${i}.allocations`]" class="dng small" role="alert">{{ form.errors[`rows.${i}.allocations`] }}</span>
                        </div>
                    </div>

                    <button type="button" class="btn btn-line sm" @click="addRow(kind)">+ {{ t('expenses.add') }}</button>
                </section>

                <!-- Calculated results -->
                <section class="card" style="--ca: var(--k3)">
                    <h3>{{ t('expenses.results.title') }}</h3>
                    <p class="mute small">{{ t('expenses.results.hint') }}</p>
                    <div v-if="results.years.length && usedCategories.length" class="tw nomax">
                        <p class="mb2">
                            <span class="seg" role="group">
                                <button v-for="m in ['expense', 'payments']" :key="m" type="button" class="btn" :class="{ 'btn-line': measure !== m }" :aria-pressed="measure === m" @click="measure = m">{{ t(`expenses.results.${m}`) }}</button>
                            </span>
                        </p>
                        <table class="stm">
                            <thead>
                                <tr>
                                    <th>{{ t('expenses.category') }}</th>
                                    <th v-for="y in results.years" :key="y" class="num" dir="ltr">{{ y }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="c in usedCategories" :key="c">
                                    <tr>
                                        <td>
                                            <button type="button" class="rowtoggle" :aria-expanded="openCats.has(c)" :aria-label="openCats.has(c) ? t('products.results.collapse') : t('products.results.expand')" @click="toggle(c)">{{ openCats.has(c) ? '▾' : '▸' }}</button>
                                            {{ categoryLabel(c) }}
                                        </td>
                                        <td v-for="y in results.years" :key="y" class="num" dir="ltr">{{ show(catTotal(c, y)) }}</td>
                                    </tr>
                                    <template v-if="openCats.has(c)">
                                        <tr v-for="r in saved.filter((x) => x.category_id === c)" :key="r.id" class="subrow">
                                            <td>{{ r.name }}</td>
                                            <td v-for="y in results.years" :key="y" class="num" dir="ltr">{{ show(cell(r.id, y)) }}</td>
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
                    <p v-else class="empty">{{ t('expenses.results.none') }}</p>
                </section>
            </fieldset>

            <div class="toolbar">
                <button v-if="!readOnly" type="submit" class="btn" :disabled="form.processing">{{ form.processing ? t('products.actions.saving') : t('products.actions.save') }}</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" @click="save('list')">{{ t('products.actions.saveClose') }}</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" @click="save('next')">{{ t('expenses.saveNext') }}</button>
                <Link :href="route('projects.manpower.edit', project.id)" class="btn btn-line">← {{ t('expenses.back') }}</Link>
                <Link :href="route('projects.edit', project.id)" class="btn btn-line">{{ t('common.cancel') }}</Link>
            </div>
        </form>

        <!-- Customised payment terms of one expense -->
        <ModalDialog :open="editRow !== null" :title="t('expenses.paymentsTitle', { name: editRow?.name || t('expenses.name') })" :subtitle="t('expenses.paymentsHint')" @close="editing = null">
            <div v-if="editRow" class="custom">
                <div class="customHead"><span>{{ t('expenses.dueDays') }}</span><span>{{ t('expenses.rate') }}</span><span /></div>
                <div v-for="(c, n) in editRow.custom" :key="n" class="customRow">
                    <select v-model="c.due_days" class="sel" :aria-label="t('expenses.dueDays')">
                        <option value="">—</option>
                        <option v-for="d in options.dueDays" :key="d" :value="d">{{ daysLabel(d) }}</option>
                    </select>
                    <input v-model="c.rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-label="t('expenses.rate')" placeholder="%">
                    <button type="button" class="xbtn" :title="t('expenses.removePayment')" :aria-label="t('expenses.removePayment')" @click="removeLine(n)">✕</button>
                </div>
                <button v-if="canAddLine" type="button" class="btn btn-line sm" @click="addLine">+ {{ t('expenses.addPayment') }}</button>
                <p class="small total" :class="Math.abs(customTotal(editRow) - 100) > 0.01 ? 'dng' : 'okc'">{{ t('expenses.customTotal', { n: formatNumber(customTotal(editRow), 2) }) }}</p>
            </div>
        </ModalDialog>

        <ConfirmDialog :open="removing !== null" :title="t('expenses.removeTitle')" :confirm-label="t('projects.form.removeAction')" danger @confirm="doRemove" @cancel="removing = null">
            <p>{{ t('expenses.removeText') }}</p>
        </ConfirmDialog>
    </AppLayout>
</template>

<style scoped>
.kindcard { background: linear-gradient(180deg, color-mix(in srgb, var(--ca) 7%, var(--card)), var(--card) 90px); }
.rep { padding: 14px; margin-bottom: 12px; border: 1px solid color-mix(in srgb, var(--ca) 30%, var(--line)); border-inline-start: 5px solid var(--ca); border-radius: var(--r-md); background: color-mix(in srgb, var(--ca) 4%, var(--card)); }
.repTop { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; align-items: end; }
@media (min-width: 900px) { .repTop .w2 { grid-column: span 2; } }
.termsRow { display: flex; gap: 8px; align-items: stretch; }
.termsRow .sel { flex: 1 1 0; min-width: 0; }
.hirebtn {
    display: inline-flex; align-items: center; gap: 8px; justify-content: space-between; white-space: nowrap;
    border: 1.5px dashed var(--line-2); background: var(--card); color: var(--mute); border-radius: var(--r-sm);
    padding: 8px 12px; cursor: pointer; font: inherit; font-weight: 600;
}
.hirebtn:hover { border-color: var(--ca); color: var(--ink); }
.hirebtn.has { border-style: solid; border-color: var(--ca); background: color-mix(in srgb, var(--ca) 12%, var(--card)); color: var(--ink); }
.hirebtn.bad { border-color: var(--red); }
.blk { margin-top: 12px; padding-top: 10px; border-top: 1px dashed var(--line-2); }
.chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.pick { display: inline-flex; gap: 8px; align-items: center; padding: 6px 12px; border-radius: 999px; border: 1.5px solid var(--line-2); background: var(--card); cursor: pointer; font-weight: 600; }
.pick.on { border-color: var(--ca); background: color-mix(in srgb, var(--ca) 14%, var(--card)); }
.chk { display: flex; gap: 8px; align-items: center; }
.custom { padding-bottom: 8px; }
.customHead, .customRow { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) 40px; gap: 10px; align-items: center; margin-bottom: 8px; }
.customHead { font-size: 12.5px; font-weight: 600; color: var(--mute); }
.total { margin-top: 10px; font-weight: 700; }
.okc { color: var(--green-ink); }
.xbtn { border: 0; background: transparent; color: var(--faint); cursor: pointer; border-radius: 8px; padding: 6px 9px; }
.xbtn:hover { background: var(--red-soft); color: var(--red); }
.rowtoggle { border: 0; background: transparent; color: var(--blue); cursor: pointer; padding: 0 6px; }
.subrow td { color: var(--mute, #6b7280); font-size: 0.92em; }
.subrow td:first-child { padding-inline-start: 28px; }
.totalrow td { border-top: 2px solid var(--line); }
</style>
