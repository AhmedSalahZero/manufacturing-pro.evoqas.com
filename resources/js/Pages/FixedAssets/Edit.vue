<!--
  ZAVERO Manufacturing Pro — Fixed assets of a study ( /projects/{id}/fixed-assets )
  Location: resources/js/Pages/FixedAssets/Edit.vue
  Four cards, one for each kind of asset:
    Land          bought on one date, never depreciated.
    Construction  a start and an end date; spent evenly or on an S-curve; each payment line chooses "taken from".
    Machines      a purchase, a delivery and a ready date (after installation and testing); can be paid by milestones.
    Other         bought on one date and depreciated.
  Each asset has a price, its dates, a depreciation period (administration and manufacturing part, replacement cost; not for
  land), payment terms (cash, customised payments, instalments or - for machines - milestones, set in pop-ups), funding (own
  money and a bank loan whose terms are set in a pop-up) and how the manufacturing depreciation is shared between the
  products (a pop-up). The page keeps every asset to three short lines.
  Saved by App\Http\Controllers\FixedAssetController@update (App\Services\FixedAssetService), which recalculates the study.
  Months are saved by position in the study (0 = the study's first month).
-->
<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import MonthYearInput from '@/Components/MonthYearInput.vue';
import { useI18n } from '@/lang/i18n';
import { formatNumber } from '@/Utils/format';

const props = defineProps({
    project: { type: Object, required: true },
    products: { type: Array, required: true },
    rows: { type: Array, required: true },
    summary: { type: Object, required: true },
    results: { type: Object, required: true },
    options: { type: Object, required: true },
    readOnly: { type: Boolean, default: false },
});

const { t } = useI18n();
// A customer of a "fill" link has no financial statements to go to: his last page for a new company ends with "Save and finish".
const isFill = computed(() => usePage().props.public?.kind === 'fill');
const KINDS = ['land', 'construction', 'machine', 'other'];
const ACCENTS = { land: '--k2', construction: '--k1', machine: '--k4', other: '--k5' };
const CURVES = ['flat', 's_curve'];
const MILESTONES = ['purchase', 'delivery', 'ready'];
// The milestone pay-outs hang on these dates of the machine: purchase -> start, delivery -> delivery, ready -> end.
const MILESTONE_DATE = { purchase: 'start', delivery: 'delivery', ready: 'end' };
// The dates each kind asks for (land and other assets are bought on one date).
const DATES = {
    land: [{ field: 'start', label: 'assets.purchaseDate' }],
    other: [{ field: 'start', label: 'assets.purchaseDate' }],
    construction: [{ field: 'start', label: 'assets.startDate' }, { field: 'end', label: 'assets.endDate' }],
    machine: [{ field: 'start', label: 'assets.purchaseDate' }, { field: 'delivery', label: 'assets.deliveryDate' }, { field: 'end', label: 'assets.readyDate' }],
};
const termsFor = (kind) => (kind === 'machine' ? ['cash', 'customize', 'installment', 'milestone'] : ['cash', 'customize', 'installment']);
const kindOf = (r) => r.asset_type || 'construction';
const blankMilestones = () => ({ purchase: { rate: '', days: 0 }, delivery: { rate: '', days: 0 }, ready: { rate: '', days: 0 } });
const milestonesOf = (m) => Object.fromEntries(MILESTONES.map((k) => [k, { rate: m?.[k]?.rate ?? '', days: m?.[k]?.days ?? 0 }]));
const DEPRECIATION_YEARS = [0, ...Array.from({ length: 23 }, (_, i) => i + 3)];

const form = useForm({
    rows: props.rows.map((r) => ({
        ...r,
        asset_type: kindOf(r),
        execution_curve: r.execution_curve || 'flat',
        delivery: r.delivery || r.start,
        milestones: milestonesOf(r.milestones),
        custom: r.custom.map((c) => ({ ...c })),
        allocations: { ...r.allocations },
        key: `old-${r.id}`,
    })),
    after: 'stay',
});

const invalid = (key) => !!form.errors[key];
const err = (i, field) => form.errors[`rows.${i}.${field}`];
const num = (v) => Number(v) || 0;
const total = (r) => num(r.counts) * num(r.amount);
const manufacturingPct = (r) => Math.max(0, 100 - num(r.admin_depreciation_percentage));
const debtPct = (r) => Math.max(0, 100 - num(r.equity_funding_rate));
const hasLoan = (r) => num(r.equity_funding_rate) < 100;
const show = (n) => formatNumber(n, 0);

// ── Summary tiles ──
const tiles = computed(() => {
    const cost = form.rows.reduce((s, r) => s + total(r), 0);
    const loan = form.rows.reduce((s, r) => s + total(r) * debtPct(r) / 100, 0);
    return [
        { key: 'count', value: form.rows.length, accent: '--k1' },
        { key: 'cost', value: show(cost), accent: '--k5' },
        { key: 'equity', value: show(cost - loan), accent: '--k3' },
        { key: 'loan', value: show(loan), accent: '--k2' },
    ];
});

// One card for each kind of asset; every asset keeps its place (i) in the form's list, which is what the checks refer to.
const sections = computed(() => KINDS.map((kind) => ({
    kind,
    accent: ACCENTS[kind],
    items: form.rows.map((r, i) => ({ r, i })).filter(({ r }) => kindOf(r) === kind),
})));

// The dates of a construction or a machine always follow each other: a later start moves the end (and the delivery) with it.
watch(() => form.rows.map((r) => `${r.start}|${r.delivery}|${r.end}`).join(), () => {
    form.rows.forEach((r) => {
        if (kindOf(r) === 'machine') {
            if (r.start && r.delivery && r.delivery < r.start) r.delivery = r.start;
            if (r.delivery && r.end && r.end < r.delivery) r.end = r.delivery;
        } else if (kindOf(r) === 'construction' && r.start && r.end && r.end < r.start) {
            r.end = r.start;
        }
    });
});

let counter = 0;
function addRow(kind) {
    const month = `${props.project.startYear}-01`;
    form.rows.push({
        id: null, key: `new-${counter++}`, asset_type: kind, execution_curve: 'flat', name: '', counts: 1, amount: '',
        depreciation_duration: kind === 'land' ? 0 : 5,
        start: month, delivery: month, end: month,
        admin_depreciation_percentage: 0, replacement_cost_rate: 0, replacement_cost_interval: 1,
        // A construction usually starts with a down payment on the total price; the lines after it follow each month's work.
        payment_terms: 'cash', custom: [{ due_days: kind === 'construction' ? 0 : '', rate: '', from_total: kind === 'construction' }],
        milestones: blankMilestones(),
        reservation_rate: 0, contractual_rate: 0, after_months: 0, installment_grace_period: 0, installment_count: 1, payment_installment_interval: 'monthly',
        equity_funding_rate: 100, interest_rate: 0, grace_period: 0, tenor: 60, installment_interval: 'monthly',
        is_as_revenue_percentages: true, allocations: Object.fromEntries(props.products.map((p) => [p.id, 0])),
    });
    nextTick(() => document.querySelector(`.rep[data-asset="${form.rows.length - 1}"] input`)?.focus());
}

const removing = ref(null);
const doRemove = () => { form.rows.splice(removing.value, 1); removing.value = null; };

// ── Pop-ups: payments, instalments, loan, allocation ──
const popup = ref(null);   // { kind, index }
const editRow = computed(() => (popup.value === null ? null : form.rows[popup.value.index]));
const open = (kind, index) => { popup.value = { kind, index }; };
const close = () => { popup.value = null; };
const isOpen = (kind) => popup.value?.kind === kind && editRow.value !== null;

const customTotal = (row) => row.custom.reduce((s, c) => s + num(c.rate), 0);
const customCount = (row) => row.custom.filter((c) => c.due_days !== '' && c.due_days !== null && c.rate !== '' && c.rate !== null).length;
const canAddLine = computed(() => editRow.value && editRow.value.custom.length < props.options.slots);
// "Taken from" (the total price or each month's work) only exists for a construction, which is spent over several months.
const showBasis = computed(() => !!editRow.value && kindOf(editRow.value) === 'construction');
const milestoneTotal = (row) => MILESTONES.reduce((s, k) => s + num(row.milestones[k].rate), 0);
const milestoneBrief = (row) => (milestoneTotal(row) > 0 ? MILESTONES.map((k) => `${Number(num(row.milestones[k].rate).toFixed(2))}%`).join(' · ') : t('assets.termsSet'));
const milestoneError = (i) => Object.keys(form.errors).some((k) => k === `rows.${i}.milestones` || k === `rows.${i}.payment_terms` || k.startsWith(`rows.${i}.milestones.`));
const addLine = () => editRow.value.custom.push({ due_days: '', rate: '', from_total: false });
const removeLine = (n) => { editRow.value.custom.splice(n, 1); if (!editRow.value.custom.length) addLine(); };
const customError = (i) => Object.keys(form.errors).some((k) => k === `rows.${i}.custom` || k.startsWith(`rows.${i}.custom.`));
const remaining = (r) => Math.max(0, 100 - num(r.reservation_rate) - num(r.contractual_rate));
function chooseTerms(row, i) {
    if (row.payment_terms === 'customize') open('payments', i);
    else if (row.payment_terms === 'installment') open('instalments', i);
    else if (row.payment_terms === 'milestone') open('milestones', i);
}

const shareTotal = (row) => props.products.reduce((s, p) => s + num(row.allocations[p.id]), 0);
const allocationError = (i) => !!err(i, 'allocations');
const daysLabel = (d) => (d === 0 ? t('assets.sameMonth') : t('assets.afterDays', { n: d }));
const intervalLabel = (k) => t(`assets.intervals.${k}`);
const loanBrief = (r) => `${formatNumber(num(r.interest_rate), 2)}% · ${num(r.tenor)}`;

// ── Calculated results ──
const measure = ref('net');
const openRows = ref(new Set());
const toggle = (id) => { const n = new Set(openRows.value); n.has(id) ? n.delete(id) : n.add(id); openRows.value = n; };
const saved = computed(() => form.rows.filter((r) => r.id));
const years = computed(() => props.results.years.filter((y) => y <= props.project.endYear + 1));
const cell = (id, year) => props.results.rows[id]?.[year]?.[measure.value] ?? 0;
const grandTotal = (year) => saved.value.reduce((s, r) => s + cell(r.id, year), 0);
const found = (id) => props.summary[id];

function save(after) {
    form.after = after;
    form.put(route('projects.assets.update', props.project.id), {
        preserveScroll: true,
        onError: async () => {
            await nextTick();
            document.querySelector('[aria-invalid="true"], [role="alert"].dng')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },
    });
}
</script>

<template>
    <AppLayout :title="t('assets.title')" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ t('assets.title') }}</h2>
                <p class="lead mb0"><Link :href="route('projects.edit', project.id)">← {{ project.name }}</Link></p>
            </div>
        </div>

        <div v-if="form.hasErrors" class="note bad" role="alert">{{ t('products.fixErrors') }}</div>
        <div v-if="readOnly" class="note" role="status">{{ t('products.readOnly') }}</div>

        <div class="tiles">
            <div v-for="tile in tiles" :key="tile.key" class="tile" :style="{ '--ca': `var(${tile.accent})` }"><span>{{ t(`assets.tiles.${tile.key}`) }}</span><b>{{ tile.value }}</b></div>
        </div>

        <form novalidate @submit.prevent="save('stay')">
            <fieldset :disabled="readOnly" style="border:0;margin:0;padding:0;min-width:0">

                <p class="mute small">{{ t('assets.hint') }}</p>
                <p v-if="!form.rows.length" class="empty">{{ t('assets.empty') }}</p>

                <!-- One card for each kind of asset -->
                <section v-for="sec in sections" :key="sec.kind" class="card assetcard" :data-kind-card="sec.kind" :style="{ '--ca': `var(${sec.accent})` }">
                    <h3>{{ t(`assets.kinds.${sec.kind}.title`) }}</h3>
                    <p class="mute small">{{ t(`assets.kinds.${sec.kind}.hint`) }}</p>

                    <div v-for="{ r, i } in sec.items" :key="r.key" class="rep" :data-asset="i" :data-kind="sec.kind">
                        <!-- Line 1: what and when -->
                        <div class="repTop">
                            <div class="fld w2">
                                <label :for="`as-${i}-name`">{{ t('assets.name') }}</label>
                                <input :id="`as-${i}-name`" v-model="r.name" class="inp" type="text" maxlength="255" :aria-invalid="invalid(`rows.${i}.name`)">
                                <span v-if="err(i, 'name')" class="dng small" role="alert">{{ err(i, 'name') }}</span>
                            </div>
                            <div class="fld">
                                <label :for="`as-${i}-count`">{{ t('assets.counts') }}</label>
                                <input :id="`as-${i}-count`" v-model="r.counts" class="inp" dir="ltr" type="number" step="1" min="0" :aria-invalid="invalid(`rows.${i}.counts`)">
                                <span v-if="err(i, 'counts')" class="dng small" role="alert">{{ err(i, 'counts') }}</span>
                            </div>
                            <div class="fld">
                                <label :for="`as-${i}-amount`">{{ t('assets.amount') }}</label>
                                <input :id="`as-${i}-amount`" v-model="r.amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="invalid(`rows.${i}.amount`)">
                                <span v-if="err(i, 'amount')" class="dng small" role="alert">{{ err(i, 'amount') }}</span>
                            </div>
                            <div class="fld">
                                <label>{{ t('assets.total') }}</label>
                                <output class="inp ro" dir="ltr">{{ show(total(r)) }}</output>
                            </div>
                            <!-- The dates of this kind of asset -->
                            <div v-for="d in DATES[sec.kind]" :key="d.field" class="fld w2" :data-date="d.field">
                                <label>{{ t(d.label) }}</label>
                                <MonthYearInput v-model="r[d.field]" :min-year="project.startYear" :max-year="project.endYear" :invalid="invalid(`rows.${i}.${d.field}`)" />
                                <span v-if="err(i, d.field)" class="dng small" role="alert">{{ err(i, d.field) }}</span>
                            </div>
                            <div v-if="sec.kind === 'construction'" class="fld w2">
                                <label :for="`as-${i}-curve`">{{ t('assets.spending') }}</label>
                                <select :id="`as-${i}-curve`" v-model="r.execution_curve" class="sel">
                                    <option v-for="c in CURVES" :key="c" :value="c">{{ t(`assets.curves.${c}`) }}</option>
                                </select>
                            </div>
                            <button type="button" class="btn btn-line sm delbtn" @click="removing = i">{{ t('projects.form.remove') }}</button>
                        </div>

                        <!-- Line 2: depreciation (land is never depreciated) -->
                        <div v-if="sec.kind !== 'land'" class="repTop line2">
                            <div class="fld w2">
                                <label :for="`as-${i}-dep`">{{ t('assets.depreciation') }}</label>
                                <select :id="`as-${i}-dep`" v-model.number="r.depreciation_duration" class="sel" :aria-invalid="invalid(`rows.${i}.depreciation_duration`)">
                                    <option v-for="y in DEPRECIATION_YEARS" :key="y" :value="y">{{ y === 0 ? t('assets.noDepreciation') : t('assets.years', { n: y }) }}</option>
                                </select>
                            </div>
                            <div class="fld">
                                <label :for="`as-${i}-admin`">{{ t('assets.adminPct') }}</label>
                                <input :id="`as-${i}-admin`" v-model="r.admin_depreciation_percentage" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="invalid(`rows.${i}.admin_depreciation_percentage`)">
                                <span v-if="err(i, 'admin_depreciation_percentage')" class="dng small" role="alert">{{ err(i, 'admin_depreciation_percentage') }}</span>
                            </div>
                            <div class="fld">
                                <label>{{ t('assets.manufacturingPct') }}</label>
                                <output class="inp ro" dir="ltr">{{ formatNumber(manufacturingPct(r), 2) }}</output>
                            </div>
                            <div class="fld">
                                <label :for="`as-${i}-repl`">{{ t('assets.replacementRate') }}</label>
                                <input :id="`as-${i}-repl`" v-model="r.replacement_cost_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="invalid(`rows.${i}.replacement_cost_rate`)">
                                <span v-if="err(i, 'replacement_cost_rate')" class="dng small" role="alert">{{ err(i, 'replacement_cost_rate') }}</span>
                            </div>
                            <div class="fld">
                                <label :for="`as-${i}-replint`">{{ t('assets.replacementInterval') }}</label>
                                <select :id="`as-${i}-replint`" v-model.number="r.replacement_cost_interval" class="sel">
                                    <option v-for="y in 5" :key="y" :value="y">{{ t('assets.replacementYears', { n: y }) }}</option>
                                </select>
                            </div>
                            <div v-if="manufacturingPct(r) > 0 && products.length && r.depreciation_duration > 0" class="fld w2">
                                <label>{{ t('assets.allocation') }}</label>
                                <button type="button" class="hirebtn" :class="{ has: !r.is_as_revenue_percentages && Math.abs(shareTotal(r) - 100) <= 0.01, bad: allocationError(i) }" :aria-invalid="allocationError(i)" @click="open('allocation', i)">
                                    {{ r.is_as_revenue_percentages ? t('assets.byRevenue') : t('assets.custom') }} <span aria-hidden="true">✎</span>
                                </button>
                                <span v-if="err(i, 'allocations')" class="dng small" role="alert">{{ err(i, 'allocations') }}</span>
                            </div>
                        </div>

                        <!-- Line 3: payment and funding -->
                        <div class="repTop line3">
                            <div class="fld w2">
                                <label :for="`as-${i}-terms`">{{ t('assets.paymentTerms') }}</label>
                                <div class="termsRow">
                                    <select :id="`as-${i}-terms`" v-model="r.payment_terms" class="sel" :aria-invalid="invalid(`rows.${i}.payment_terms`)" @change="chooseTerms(r, i)">
                                        <option v-for="term in termsFor(sec.kind)" :key="term" :value="term">{{ t(`assets.terms.${term}`) }}</option>
                                    </select>
                                    <button v-if="r.payment_terms === 'customize'" type="button" class="hirebtn" :class="{ has: customCount(r) > 0 && Math.abs(customTotal(r) - 100) <= 0.01, bad: customError(i) }" :aria-invalid="customError(i)" @click="open('payments', i)">
                                        {{ customCount(r) > 0 ? t('assets.termsCount', { n: customCount(r) }) : t('assets.termsSet') }} <span aria-hidden="true">✎</span>
                                    </button>
                                    <button v-if="r.payment_terms === 'installment'" type="button" class="hirebtn has" :class="{ bad: invalid(`rows.${i}.reservation_rate`) }" @click="open('instalments', i)">
                                        {{ t('assets.termsInstalments') }} <span aria-hidden="true">✎</span>
                                    </button>
                                    <button v-if="r.payment_terms === 'milestone'" type="button" class="hirebtn" :class="{ has: Math.abs(milestoneTotal(r) - 100) <= 0.01, bad: milestoneError(i) }" :aria-invalid="milestoneError(i)" data-milestones @click="open('milestones', i)">
                                        {{ milestoneBrief(r) }} <span aria-hidden="true">✎</span>
                                    </button>
                                </div>
                                <span v-if="err(i, 'custom')" class="dng small" role="alert">{{ err(i, 'custom') }}</span>
                                <span v-if="err(i, 'milestones')" class="dng small" role="alert">{{ err(i, 'milestones') }}</span>
                                <span v-if="err(i, 'payment_terms')" class="dng small" role="alert">{{ err(i, 'payment_terms') }}</span>
                                <span v-if="err(i, 'reservation_rate')" class="dng small" role="alert">{{ err(i, 'reservation_rate') }}</span>
                            </div>
                            <div class="fld">
                                <label :for="`as-${i}-eq`">{{ t('assets.equity') }}</label>
                                <input :id="`as-${i}-eq`" v-model="r.equity_funding_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="invalid(`rows.${i}.equity_funding_rate`)">
                                <span v-if="err(i, 'equity_funding_rate')" class="dng small" role="alert">{{ err(i, 'equity_funding_rate') }}</span>
                            </div>
                            <div class="fld">
                                <label>{{ t('assets.debt') }}</label>
                                <output class="inp ro" dir="ltr">{{ formatNumber(debtPct(r), 2) }}</output>
                            </div>
                            <div v-if="hasLoan(r)" class="fld w2">
                                <label>{{ t('assets.funding') }}</label>
                                <button type="button" class="hirebtn has" :class="{ bad: invalid(`rows.${i}.grace_period`) || invalid(`rows.${i}.tenor`) }" @click="open('loan', i)">
                                    {{ loanBrief(r) }} <span aria-hidden="true">✎</span>
                                </button>
                                <span v-if="err(i, 'grace_period')" class="dng small" role="alert">{{ err(i, 'grace_period') }}</span>
                                <span v-if="err(i, 'tenor')" class="dng small" role="alert">{{ err(i, 'tenor') }}</span>
                            </div>
                        </div>

                        <!-- What the calculation found -->
                        <div v-if="r.id && found(r.id)" class="found">
                            <span><em>{{ t('assets.found.cost') }}</em> <b dir="ltr">{{ show(found(r.id).cost) }}</b></span>
                            <span v-if="found(r.id).loan > 0"><em>{{ t('assets.found.loan') }}</em> <b dir="ltr">{{ show(found(r.id).loan) }}</b></span>
                            <span v-if="found(r.id).capitalized > 0"><em>{{ t('assets.found.capitalized') }}</em> <b dir="ltr">{{ show(found(r.id).capitalized) }}</b></span>
                            <span v-if="found(r.id).transferred"><em>{{ t('assets.found.transferred') }}</em> <b dir="ltr">{{ found(r.id).transferred }}</b></span>
                            <span v-if="found(r.id).transferred"><em>{{ t('assets.found.value') }}</em> <b dir="ltr">{{ show(found(r.id).value) }}</b></span>
                        </div>
                    </div>

                    <button type="button" class="btn btn-line sm" :data-add="sec.kind" @click="addRow(sec.kind)">+ {{ t(`assets.kinds.${sec.kind}.add`) }}</button>
                </section>

                <!-- Calculated results -->
                <section class="card" style="--ca: var(--k3)">
                    <h3>{{ t('assets.results.title') }}</h3>
                    <p class="mute small">{{ t('assets.results.hint') }}</p>
                    <div v-if="years.length && saved.length" class="tw nomax">
                        <p class="mb2">
                            <span class="seg" role="group">
                                <button v-for="m in ['net', 'depreciation', 'payments', 'loan']" :key="m" type="button" class="btn" :class="{ 'btn-line': measure !== m }" :aria-pressed="measure === m" @click="measure = m">{{ t(`assets.results.${m}`) }}</button>
                            </span>
                        </p>
                        <table class="stm">
                            <thead>
                                <tr>
                                    <th>{{ t('assets.results.asset') }}</th>
                                    <th v-for="y in years" :key="y" class="num" dir="ltr">{{ y }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in saved" :key="r.id">
                                    <td>{{ r.name }}</td>
                                    <td v-for="y in years" :key="y" class="num" dir="ltr">{{ show(cell(r.id, y)) }}</td>
                                </tr>
                                <tr class="totalrow">
                                    <td><strong>{{ t('assets.results.total') }}</strong></td>
                                    <td v-for="y in years" :key="y" class="num" dir="ltr"><strong>{{ show(grandTotal(y)) }}</strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="empty">{{ t('assets.results.none') }}</p>
                </section>
            </fieldset>

            <div class="toolbar">
                <button v-if="!readOnly" type="submit" class="btn" :disabled="form.processing">{{ form.processing ? t('products.actions.saving') : t('products.actions.save') }}</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" @click="save('list')">{{ t('products.actions.saveClose') }}</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" data-save-next @click="save('next')">{{ project.newCompany ? (isFill ? t('fill.saveFinish') : t('assets.saveNextStatements')) : t('assets.saveNext') }}</button>
                <Link :href="route('projects.expenses.edit', project.id)" class="btn btn-line">← {{ t('assets.back') }}</Link>
                <Link :href="route('projects.edit', project.id)" class="btn btn-line">{{ t('common.cancel') }}</Link>
            </div>
        </form>

        <!-- Customised payments of one asset -->
        <ModalDialog :open="isOpen('payments')" :title="t('assets.paymentsTitle', { name: editRow?.name || t('assets.name') })" :subtitle="showBasis ? t('assets.paymentsHint') : t('assets.paymentsHintOneDate')" wide @close="close">
            <div v-if="editRow" class="custom">
                <div class="customHead" :class="{ nb: !showBasis }"><span>{{ t('assets.dueDays') }}</span><span>{{ t('assets.rate') }}</span><span v-if="showBasis">{{ t('assets.basis') }}</span><span /></div>
                <div v-for="(c, n) in editRow.custom" :key="n" class="customRow" :class="{ nb: !showBasis }">
                    <select v-model="c.due_days" class="sel" :aria-label="t('assets.dueDays')">
                        <option value="">—</option>
                        <option v-for="d in options.dueDays" :key="d" :value="d">{{ daysLabel(d) }}</option>
                    </select>
                    <input v-model="c.rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-label="t('assets.rate')" placeholder="%">
                    <select v-if="showBasis" v-model="c.from_total" class="sel" :aria-label="t('assets.basis')">
                        <option :value="false">{{ t('assets.fromExecution') }}</option>
                        <option :value="true">{{ t('assets.fromTotal') }}</option>
                    </select>
                    <button type="button" class="xbtn" :title="t('assets.removePayment')" :aria-label="t('assets.removePayment')" @click="removeLine(n)">✕</button>
                </div>
                <button v-if="canAddLine" type="button" class="btn btn-line sm" @click="addLine">+ {{ t('assets.addPayment') }}</button>
                <p class="small total" :class="Math.abs(customTotal(editRow) - 100) > 0.01 ? 'dng' : 'okc'">{{ t('assets.customTotal', { n: formatNumber(customTotal(editRow), 2) }) }}</p>
            </div>
        </ModalDialog>

        <!-- The milestones of a machine: at the purchase, at the delivery and when it is ready -->
        <ModalDialog :open="isOpen('milestones')" :title="t('assets.milestonesTitle', { name: editRow?.name || t('assets.name') })" :subtitle="t('assets.milestonesHint')" wide @close="close">
            <div v-if="editRow" class="custom">
                <div class="customHead ms"><span>{{ t('assets.milestone') }}</span><span>{{ t('assets.rate') }}</span><span>{{ t('assets.dueDays') }}</span></div>
                <div v-for="key in MILESTONES" :key="key" class="customRow ms" :data-milestone="key">
                    <span class="msName">{{ t(`assets.milestones.${key}`) }} <small dir="ltr">{{ editRow[MILESTONE_DATE[key]] }}</small></span>
                    <input v-model="editRow.milestones[key].rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-label="t(`assets.milestones.${key}`)" placeholder="%">
                    <select v-model.number="editRow.milestones[key].days" class="sel" :aria-label="t('assets.dueDays')">
                        <option v-for="d in options.dueDays" :key="d" :value="d">{{ daysLabel(d) }}</option>
                    </select>
                </div>
                <p class="small total" :class="Math.abs(milestoneTotal(editRow) - 100) > 0.01 ? 'dng' : 'okc'">{{ t('assets.customTotal', { n: formatNumber(milestoneTotal(editRow), 2) }) }}</p>
            </div>
        </ModalDialog>

        <!-- Instalments of one asset -->
        <ModalDialog :open="isOpen('instalments')" :title="t('assets.installmentsTitle', { name: editRow?.name || t('assets.name') })" :subtitle="t('assets.installmentsHint')" @close="close">
            <div v-if="editRow" class="grid3 fgrid">
                <div class="fld">
                    <label for="in-res">{{ t('assets.reservation') }}</label>
                    <input id="in-res" v-model="editRow.reservation_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100">
                </div>
                <div class="fld">
                    <label for="in-con">{{ t('assets.contractual') }}</label>
                    <input id="in-con" v-model="editRow.contractual_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100">
                </div>
                <div class="fld">
                    <label for="in-after">{{ t('assets.afterMonths') }}</label>
                    <input id="in-after" v-model="editRow.after_months" class="inp" dir="ltr" type="number" step="1" min="0" max="120">
                </div>
                <div class="fld">
                    <label>{{ t('assets.remaining') }}</label>
                    <output class="inp ro" dir="ltr">{{ formatNumber(remaining(editRow), 2) }}</output>
                </div>
                <div class="fld">
                    <label for="in-grace">{{ t('assets.installmentGrace') }}</label>
                    <input id="in-grace" v-model="editRow.installment_grace_period" class="inp" dir="ltr" type="number" step="1" min="0" max="120">
                </div>
                <div class="fld">
                    <label for="in-count">{{ t('assets.installmentCount') }}</label>
                    <input id="in-count" v-model="editRow.installment_count" class="inp" dir="ltr" type="number" step="1" min="1" max="120">
                </div>
                <div class="fld">
                    <label for="in-int">{{ t('assets.installmentInterval') }}</label>
                    <select id="in-int" v-model="editRow.payment_installment_interval" class="sel">
                        <option v-for="k in options.paymentIntervals" :key="k" :value="k">{{ intervalLabel(k) }}</option>
                    </select>
                </div>
            </div>
        </ModalDialog>

        <!-- The bank loan of one asset -->
        <ModalDialog :open="isOpen('loan')" :title="t('assets.funding') + ': ' + (editRow?.name || t('assets.name'))" :subtitle="t('assets.debt') + ': ' + (editRow ? formatNumber(debtPct(editRow), 2) : 0) + '%'" @close="close">
            <div v-if="editRow" class="grid3 fgrid">
                <div class="fld">
                    <label for="ln-int">{{ t('assets.interest') }}</label>
                    <input id="ln-int" v-model="editRow.interest_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100">
                </div>
                <div class="fld">
                    <label for="ln-grace">{{ t('assets.graceMonths') }}</label>
                    <input id="ln-grace" v-model="editRow.grace_period" class="inp" dir="ltr" type="number" step="1" min="0" max="600">
                </div>
                <div class="fld">
                    <label for="ln-tenor">{{ t('assets.tenor') }}</label>
                    <input id="ln-tenor" v-model="editRow.tenor" class="inp" dir="ltr" type="number" step="1" min="1" max="600">
                </div>
                <div class="fld">
                    <label for="ln-int2">{{ t('assets.loanInterval') }}</label>
                    <select id="ln-int2" v-model="editRow.installment_interval" class="sel">
                        <option v-for="k in options.loanIntervals" :key="k" :value="k">{{ intervalLabel(k) }}</option>
                    </select>
                </div>
            </div>
        </ModalDialog>

        <!-- Sharing the manufacturing depreciation between the products -->
        <ModalDialog :open="isOpen('allocation')" :title="t('assets.allocateTitle', { name: editRow?.name || t('assets.name') })" :subtitle="t('assets.allocateHint')" @close="close">
            <div v-if="editRow">
                <label class="chk"><input v-model="editRow.is_as_revenue_percentages" type="checkbox"> {{ t('assets.byRevenueLabel') }}</label>
                <div v-if="!editRow.is_as_revenue_percentages" class="grid3 fgrid">
                    <div v-for="p in products" :key="p.id" class="fld">
                        <label :for="`al-${p.id}`">{{ p.name }}</label>
                        <input :id="`al-${p.id}`" v-model="editRow.allocations[p.id]" class="inp" dir="ltr" type="number" step="any" min="0" max="100">
                    </div>
                    <p class="small total" :class="Math.abs(shareTotal(editRow) - 100) > 0.01 ? 'dng' : 'okc'">{{ t('assets.shareTotal', { n: formatNumber(shareTotal(editRow), 2) }) }}</p>
                </div>
            </div>
        </ModalDialog>

        <ConfirmDialog :open="removing !== null" :title="t('assets.removeTitle')" :confirm-label="t('projects.form.removeAction')" danger @confirm="doRemove" @cancel="removing = null">
            <p>{{ t('assets.removeText') }}</p>
        </ConfirmDialog>
    </AppLayout>
</template>

<style scoped>
.assetcard { background: linear-gradient(180deg, color-mix(in srgb, var(--ca) 7%, var(--card)), var(--card) 90px); }
.rep { padding: 14px; margin-bottom: 12px; border: 1px solid color-mix(in srgb, var(--ca) 30%, var(--line)); border-inline-start: 5px solid var(--ca); border-radius: var(--r-md); background: color-mix(in srgb, var(--ca) 4%, var(--card)); }
.repTop { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; align-items: end; }
.repTop + .repTop { margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--line-2); }
@media (min-width: 900px) { .repTop .w2 { grid-column: span 2; } }
.ro { display: block; line-height: 22px; background: color-mix(in srgb, var(--ca) 8%, var(--card)); color: var(--ink); font-weight: 700; }
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
.found { display: flex; flex-wrap: wrap; gap: 8px 22px; margin-top: 12px; padding: 8px 12px; border-radius: var(--r-sm); background: color-mix(in srgb, var(--k3) 10%, var(--card)); font-size: 13.5px; }
.found em { font-style: normal; color: var(--mute); margin-inline-end: 4px; }
.chk { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; }
.custom { padding-bottom: 8px; }
.customHead, .customRow { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) minmax(0, 2fr) 40px; gap: 10px; align-items: center; margin-bottom: 8px; }
.customHead.nb, .customRow.nb { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr) 40px; }
.customHead.ms, .customRow.ms { grid-template-columns: minmax(0, 2.4fr) minmax(0, 1fr) minmax(0, 1.6fr); }
.msName { font-weight: 600; }
.msName small { margin-inline-start: 6px; color: var(--mute); font-weight: 500; }
.customHead { font-size: 12.5px; font-weight: 600; color: var(--mute); }
.total { margin-top: 10px; font-weight: 700; }
.okc { color: var(--green-ink); }
.xbtn { border: 0; background: transparent; color: var(--faint); cursor: pointer; border-radius: 8px; padding: 6px 9px; }
.xbtn:hover { background: var(--red-soft); color: var(--red); }
.totalrow td { border-top: 2px solid var(--line); }
</style>
