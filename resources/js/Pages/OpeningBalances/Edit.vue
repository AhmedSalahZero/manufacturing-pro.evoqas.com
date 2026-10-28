<!--
  ZAVERO Manufacturing Pro — Opening balances of a study ( /projects/{id}/opening-balances )
  Location: resources/js/Pages/OpeningBalances/Edit.vue
  Only for an existing company: what it already owns and owes on the first day. Three coloured cards (assets, liabilities,
  equity), one short line per item. Balances that are collected or paid over time get a pop-up where the months are typed
  (with a "spread evenly" shortcut). The top tiles show, as you type, whether assets equal liabilities plus equity.
  Saved by App\Http\Controllers\OpeningBalanceController@update (App\Services\OpeningBalanceService), which recalculates the study.
  Months are saved by position in the study (0 = the study's first month).
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
    months: { type: Array, required: true },
    products: { type: Array, required: true },
    data: { type: Object, required: true },
    inventory: { type: Number, default: 0 },
    readOnly: { type: Boolean, default: false },
});

const { t } = useI18n();
const clone = (v) => JSON.parse(JSON.stringify(v));

const form = useForm({
    fixed_assets: props.data.fixed_assets.map((r) => ({ ...clone(r), key: `old-${r.id}` })),
    cash: clone(props.data.cash),
    other_debtors: props.data.other_debtors.map((r) => ({ ...clone(r), key: `old-${r.id}` })),
    suppliers: clone(props.data.suppliers),
    other_creditors: props.data.other_creditors.map((r) => ({ ...clone(r), key: `old-${r.id}` })),
    taxes: clone(props.data.taxes),
    loans: props.data.loans.map((r) => ({ ...clone(r), key: `old-${r.id}` })),
    other_long_term: props.data.other_long_term.map((r) => ({ ...clone(r), key: `old-${r.id}` })),
    equity: clone(props.data.equity),
    after: 'stay',
});

const num = (v) => Number(v) || 0;
const show = (n) => formatNumber(n, 0);
const err = (path) => form.errors[path];
const bad = (path) => !!form.errors[path];

// ── Does it balance? ──
const net = (r) => num(r.gross_amount) - num(r.accumulated_depreciation);
const sum = (rows, key) => rows.reduce((s, r) => s + num(r[key]), 0);
const assets = computed(() => form.fixed_assets.reduce((s, r) => s + net(r), 0) + num(form.cash.cash_and_bank_amount) + num(form.cash.customer_receivable_amount)
    + props.inventory + sum(form.other_debtors, 'amount'));
const equityTotal = computed(() => num(form.equity.paid_up_capital_amount) + num(form.equity.legal_reserve) + num(form.equity.retained_earnings));
const liabilities = computed(() => num(form.suppliers.amount) + sum(form.other_creditors, 'amount') + num(form.taxes.vat_amount) + num(form.taxes.credit_withhold_taxes)
    + num(form.taxes.corporate_taxes_payable) + sum(form.loans, 'amount') + sum(form.other_long_term, 'amount') + equityTotal.value);
const difference = computed(() => liabilities.value - assets.value);
const balanced = computed(() => Math.abs(difference.value) < 0.01);
const tiles = computed(() => [
    { key: 'assets', value: show(assets.value), accent: '--k1' },
    { key: 'liabilities', value: show(liabilities.value), accent: '--k2' },
    { key: 'difference', value: balanced.value ? t('opening.balanced') : show(difference.value), accent: balanced.value ? '--k3' : '--k4', ok: balanced.value },
]);

// ── Adding and removing lines ──
let counter = 0;
const blank = {
    fixed_assets: () => ({ id: null, name: '', gross_amount: '', accumulated_depreciation: 0, monthly_depreciation: 0, monthly_counts: 60, admin_depreciation_percentage: 0, is_as_revenue_percentages: true, allocations: Object.fromEntries(props.products.map((p) => [p.id, 0])) }),
    other_debtors: () => ({ id: null, name: '', amount: '', settlements: {} }),
    other_creditors: () => ({ id: null, name: '', amount: '', settlements: {} }),
    loans: () => ({ id: null, amount: '', installments: {}, interests: {} }),
    other_long_term: () => ({ id: null, amount: '', settlements: {} }),
};
function addRow(section) {
    form[section].push({ ...blank[section](), key: `new-${counter++}` });
    nextTick(() => document.querySelector(`[data-section="${section}"] .line:last-of-type input`)?.focus());
}
const removing = ref(null);   // { section, index }
const doRemove = () => { form[removing.value.section].splice(removing.value.index, 1); removing.value = null; };

// ── Months, shown by calendar year ──
const years = computed(() => {
    const byYear = new Map();
    props.months.forEach((m) => {
        if (!byYear.has(m.year)) byYear.set(m.year, Array(12).fill(null));
        byYear.get(m.year)[m.month - 1] = m.index;
    });
    return [...byYear.entries()].map(([year, cells]) => ({ year, cells }));
});
const order = computed(() => props.months.map((m) => m.index));
const monthLabel = (i) => { const m = props.months.find((x) => x.index === i); return m ? `${t(`projects.months.${m.month}`)} ${m.year}` : ''; };

// ── The pop-up with the months of one balance ──
const popup = ref(null);   // { kind: 'months' | 'allocation', target, field, title, limit }
const closePopup = () => { popup.value = null; };
const isOpen = (kind) => popup.value?.kind === kind;
function openMonths(target, field, what, name, limit) {
    spreadCount.value = 12;
    spreadFrom.value = order.value[0] ?? 0;
    popup.value = { kind: 'months', target, field, title: name ? t('opening.dialog.title', { what, name }) : what, limit };
}
const cells = computed(() => (popup.value?.kind === 'months' ? popup.value.target[popup.value.field] : null));
const entered = computed(() => (cells.value ? Object.values(cells.value).reduce((s, v) => s + num(v), 0) : 0));
const limit = computed(() => (popup.value?.limit ? num(popup.value.limit()) : null));
function copyRight(index) {
    const from = order.value.indexOf(index);
    order.value.slice(from + 1).forEach((i) => { cells.value[i] = cells.value[index]; });
}
const spreadCount = ref(12);
const spreadFrom = ref(0);
function spread() {
    const total = limit.value ?? 0;
    const n = Math.max(1, Math.floor(num(spreadCount.value)));
    const start = order.value.indexOf(Number(spreadFrom.value));
    const target = order.value.slice(start < 0 ? 0 : start, (start < 0 ? 0 : start) + n);
    Object.keys(cells.value).forEach((k) => delete cells.value[k]);
    const each = Math.floor((total / target.length) * 100) / 100;
    target.forEach((i, k) => { cells.value[i] = k === target.length - 1 ? Math.round((total - each * (target.length - 1)) * 100) / 100 : each; });
}
const clearMonths = () => Object.keys(cells.value).forEach((k) => delete cells.value[k]);
const monthCount = (series) => Object.values(series || {}).filter((v) => num(v) !== 0).length;
const monthsError = (path) => Object.keys(form.errors).some((k) => k === path || k.startsWith(`${path}.`));

// ── Sharing the depreciation of an owned asset between products ──
const editAsset = computed(() => (isOpen('allocation') ? popup.value.target : null));
const shareTotal = (row) => props.products.reduce((s, p) => s + num(row.allocations[p.id]), 0);
const manufacturingPct = (r) => Math.max(0, 100 - num(r.admin_depreciation_percentage));
const canAllocate = (r) => manufacturingPct(r) > 0 && props.products.length > 0 && num(r.monthly_depreciation) > 0;

function save(after) {
    form.after = after;
    form.put(route('projects.opening.update', props.project.id), {
        preserveScroll: true,
        onError: async () => {
            await nextTick();
            document.querySelector('[aria-invalid="true"], [role="alert"].dng')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },
    });
}
</script>

<template>
    <AppLayout :title="t('opening.title')" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ t('opening.title') }}</h2>
                <p class="lead mb0"><Link :href="route('projects.edit', project.id)">← {{ project.name }}</Link></p>
            </div>
        </div>

        <div v-if="form.errors.balance" class="note bad dng" role="alert">{{ form.errors.balance }}</div>
        <div v-else-if="form.hasErrors" class="note bad" role="alert">{{ t('products.fixErrors') }}</div>
        <div v-if="readOnly" class="note" role="status">{{ t('products.readOnly') }}</div>
        <p class="mute small">{{ t('opening.hint') }}</p>

        <div class="tiles sticky">
            <div v-for="tile in tiles" :key="tile.key" class="tile" :class="{ okTile: tile.ok, badTile: tile.key === 'difference' && !tile.ok }" :style="{ '--ca': `var(${tile.accent})` }">
                <span>{{ t(`opening.tiles.${tile.key}`) }}</span><b dir="ltr">{{ tile.value }}</b>
            </div>
        </div>

        <form novalidate @submit.prevent="save('stay')">
            <fieldset :disabled="readOnly" style="border:0;margin:0;padding:0;min-width:0">

                <!-- ASSETS -->
                <section class="card" style="--ca: var(--k1)">
                    <h3>{{ t('opening.sections.assets') }}</h3>

                    <h4>{{ t('opening.fixed.title') }}</h4>
                    <div data-section="fixed_assets">
                        <p v-if="!form.fixed_assets.length" class="empty">{{ t('opening.fixed.empty') }}</p>
                        <div v-for="(r, i) in form.fixed_assets" :key="r.key" class="rep">
                            <div class="repTop">
                                <div class="fld w2">
                                    <label :for="`fa-${i}-name`">{{ t('opening.fixed.name') }}</label>
                                    <input :id="`fa-${i}-name`" v-model="r.name" class="inp" type="text" maxlength="255" :aria-invalid="bad(`fixed_assets.${i}.name`)">
                                    <span v-if="err(`fixed_assets.${i}.name`)" class="dng small" role="alert">{{ err(`fixed_assets.${i}.name`) }}</span>
                                </div>
                                <div class="fld">
                                    <label :for="`fa-${i}-gross`">{{ t('opening.fixed.gross') }}</label>
                                    <input :id="`fa-${i}-gross`" v-model="r.gross_amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad(`fixed_assets.${i}.gross_amount`)">
                                    <span v-if="err(`fixed_assets.${i}.gross_amount`)" class="dng small" role="alert">{{ err(`fixed_assets.${i}.gross_amount`) }}</span>
                                </div>
                                <div class="fld">
                                    <label :for="`fa-${i}-acc`">{{ t('opening.fixed.accumulated') }}</label>
                                    <input :id="`fa-${i}-acc`" v-model="r.accumulated_depreciation" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad(`fixed_assets.${i}.accumulated_depreciation`)">
                                    <span v-if="err(`fixed_assets.${i}.accumulated_depreciation`)" class="dng small" role="alert">{{ err(`fixed_assets.${i}.accumulated_depreciation`) }}</span>
                                </div>
                                <div class="fld">
                                    <label>{{ t('opening.fixed.net') }}</label>
                                    <output class="inp ro" dir="ltr">{{ show(net(r)) }}</output>
                                </div>
                                <button type="button" class="btn btn-line sm delbtn" @click="removing = { section: 'fixed_assets', index: i }">{{ t('opening.remove') }}</button>
                            </div>
                            <div class="repTop line2">
                                <div class="fld">
                                    <label :for="`fa-${i}-mon`">{{ t('opening.fixed.monthly') }}</label>
                                    <input :id="`fa-${i}-mon`" v-model="r.monthly_depreciation" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad(`fixed_assets.${i}.monthly_depreciation`)">
                                </div>
                                <div class="fld">
                                    <label :for="`fa-${i}-left`">{{ t('opening.fixed.monthsLeft') }}</label>
                                    <input :id="`fa-${i}-left`" v-model="r.monthly_counts" class="inp" dir="ltr" type="number" step="1" min="0" max="600" :aria-invalid="bad(`fixed_assets.${i}.monthly_counts`)">
                                </div>
                                <div class="fld">
                                    <label :for="`fa-${i}-adm`">{{ t('opening.fixed.adminPct') }}</label>
                                    <input :id="`fa-${i}-adm`" v-model="r.admin_depreciation_percentage" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="bad(`fixed_assets.${i}.admin_depreciation_percentage`)">
                                </div>
                                <div class="fld">
                                    <label>{{ t('opening.fixed.manufacturingPct') }}</label>
                                    <output class="inp ro" dir="ltr">{{ formatNumber(manufacturingPct(r), 2) }}</output>
                                </div>
                                <div v-if="canAllocate(r)" class="fld w2">
                                    <label>{{ t('opening.fixed.allocate') }}</label>
                                    <button type="button" class="hirebtn" :class="{ has: !r.is_as_revenue_percentages && Math.abs(shareTotal(r) - 100) <= 0.01, bad: bad(`fixed_assets.${i}.allocations`) }" :aria-invalid="bad(`fixed_assets.${i}.allocations`)" @click="popup = { kind: 'allocation', target: r }">
                                        {{ r.is_as_revenue_percentages ? t('opening.fixed.byRevenue') : t('opening.fixed.custom') }} <span aria-hidden="true">✎</span>
                                    </button>
                                    <span v-if="err(`fixed_assets.${i}.allocations`)" class="dng small" role="alert">{{ err(`fixed_assets.${i}.allocations`) }}</span>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-line sm" @click="addRow('fixed_assets')">+ {{ t('opening.fixed.add') }}</button>
                    </div>

                    <h4>{{ t('opening.cash.title') }}</h4>
                    <div class="line">
                        <div class="fld">
                            <label for="cash-amount">{{ t('opening.cash.cash') }}</label>
                            <input id="cash-amount" v-model="form.cash.cash_and_bank_amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad('cash.cash_and_bank_amount')">
                            <span v-if="err('cash.cash_and_bank_amount')" class="dng small" role="alert">{{ err('cash.cash_and_bank_amount') }}</span>
                        </div>
                        <div class="fld">
                            <label for="cash-rec">{{ t('opening.cash.receivables') }}</label>
                            <input id="cash-rec" v-model="form.cash.customer_receivable_amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad('cash.customer_receivable_amount')">
                            <span v-if="err('cash.customer_receivable_amount')" class="dng small" role="alert">{{ err('cash.customer_receivable_amount') }}</span>
                        </div>
                        <div class="fld">
                            <label>{{ t('opening.cash.collections') }}</label>
                            <button type="button" class="hirebtn" :class="{ has: monthCount(form.cash.settlements) > 0, bad: monthsError('cash.settlements') }" :aria-invalid="monthsError('cash.settlements')" @click="openMonths(form.cash, 'settlements', t('opening.cash.collections'), '', () => form.cash.customer_receivable_amount)">
                                {{ monthCount(form.cash.settlements) ? t('opening.settleCount', { n: monthCount(form.cash.settlements) }) : t('opening.settleSet') }} <span aria-hidden="true">✎</span>
                            </button>
                            <span v-if="err('cash.settlements')" class="dng small" role="alert">{{ err('cash.settlements') }}</span>
                        </div>
                        <div class="fld">
                            <label>{{ t('opening.cash.inventory') }}</label>
                            <output class="inp ro" dir="ltr" :title="t('opening.cash.inventoryHint')">{{ show(inventory) }}</output>
                        </div>
                    </div>

                    <h4>{{ t('opening.debtors.title') }}</h4>
                    <div data-section="other_debtors">
                        <p v-if="!form.other_debtors.length" class="empty">{{ t('opening.debtors.empty') }}</p>
                        <div v-for="(r, i) in form.other_debtors" :key="r.key" class="line">
                            <div class="fld w2">
                                <label :for="`od-${i}-n`">{{ t('opening.name') }}</label>
                                <input :id="`od-${i}-n`" v-model="r.name" class="inp" type="text" maxlength="255" :aria-invalid="bad(`other_debtors.${i}.name`)">
                                <span v-if="err(`other_debtors.${i}.name`)" class="dng small" role="alert">{{ err(`other_debtors.${i}.name`) }}</span>
                            </div>
                            <div class="fld">
                                <label :for="`od-${i}-a`">{{ t('opening.amount') }}</label>
                                <input :id="`od-${i}-a`" v-model="r.amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad(`other_debtors.${i}.amount`)">
                            </div>
                            <div class="fld">
                                <label>{{ t('opening.debtors.payments') }}</label>
                                <button type="button" class="hirebtn" :class="{ has: monthCount(r.settlements) > 0, bad: monthsError(`other_debtors.${i}.settlements`) }" :aria-invalid="monthsError(`other_debtors.${i}.settlements`)" @click="openMonths(r, 'settlements', t('opening.debtors.payments'), r.name, () => r.amount)">
                                    {{ monthCount(r.settlements) ? t('opening.settleCount', { n: monthCount(r.settlements) }) : t('opening.settleSet') }} <span aria-hidden="true">✎</span>
                                </button>
                                <span v-if="err(`other_debtors.${i}.settlements`)" class="dng small" role="alert">{{ err(`other_debtors.${i}.settlements`) }}</span>
                            </div>
                            <button type="button" class="btn btn-line sm delbtn" @click="removing = { section: 'other_debtors', index: i }">{{ t('opening.remove') }}</button>
                        </div>
                        <button type="button" class="btn btn-line sm" @click="addRow('other_debtors')">+ {{ t('opening.debtors.add') }}</button>
                    </div>
                </section>

                <!-- LIABILITIES -->
                <section class="card" style="--ca: var(--k2)">
                    <h3>{{ t('opening.sections.liabilities') }}</h3>

                    <h4>{{ t('opening.suppliers.title') }}</h4>
                    <div class="line">
                        <div class="fld">
                            <label for="sup-amount">{{ t('opening.suppliers.amount') }}</label>
                            <input id="sup-amount" v-model="form.suppliers.amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad('suppliers.amount')">
                            <span v-if="err('suppliers.amount')" class="dng small" role="alert">{{ err('suppliers.amount') }}</span>
                        </div>
                        <div class="fld">
                            <label>{{ t('opening.suppliers.payments') }}</label>
                            <button type="button" class="hirebtn" :class="{ has: monthCount(form.suppliers.settlements) > 0, bad: monthsError('suppliers.settlements') }" :aria-invalid="monthsError('suppliers.settlements')" @click="openMonths(form.suppliers, 'settlements', t('opening.suppliers.payments'), '', () => form.suppliers.amount)">
                                {{ monthCount(form.suppliers.settlements) ? t('opening.settleCount', { n: monthCount(form.suppliers.settlements) }) : t('opening.settleSet') }} <span aria-hidden="true">✎</span>
                            </button>
                            <span v-if="err('suppliers.settlements')" class="dng small" role="alert">{{ err('suppliers.settlements') }}</span>
                        </div>
                    </div>

                    <h4>{{ t('opening.creditors.title') }}</h4>
                    <div data-section="other_creditors">
                        <p v-if="!form.other_creditors.length" class="empty">{{ t('opening.creditors.empty') }}</p>
                        <div v-for="(r, i) in form.other_creditors" :key="r.key" class="line">
                            <div class="fld w2">
                                <label :for="`oc-${i}-n`">{{ t('opening.name') }}</label>
                                <input :id="`oc-${i}-n`" v-model="r.name" class="inp" type="text" maxlength="255" :aria-invalid="bad(`other_creditors.${i}.name`)">
                                <span v-if="err(`other_creditors.${i}.name`)" class="dng small" role="alert">{{ err(`other_creditors.${i}.name`) }}</span>
                            </div>
                            <div class="fld">
                                <label :for="`oc-${i}-a`">{{ t('opening.amount') }}</label>
                                <input :id="`oc-${i}-a`" v-model="r.amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad(`other_creditors.${i}.amount`)">
                            </div>
                            <div class="fld">
                                <label>{{ t('opening.creditors.payments') }}</label>
                                <button type="button" class="hirebtn" :class="{ has: monthCount(r.settlements) > 0, bad: monthsError(`other_creditors.${i}.settlements`) }" :aria-invalid="monthsError(`other_creditors.${i}.settlements`)" @click="openMonths(r, 'settlements', t('opening.creditors.payments'), r.name, () => r.amount)">
                                    {{ monthCount(r.settlements) ? t('opening.settleCount', { n: monthCount(r.settlements) }) : t('opening.settleSet') }} <span aria-hidden="true">✎</span>
                                </button>
                                <span v-if="err(`other_creditors.${i}.settlements`)" class="dng small" role="alert">{{ err(`other_creditors.${i}.settlements`) }}</span>
                            </div>
                            <button type="button" class="btn btn-line sm delbtn" @click="removing = { section: 'other_creditors', index: i }">{{ t('opening.remove') }}</button>
                        </div>
                        <button type="button" class="btn btn-line sm" @click="addRow('other_creditors')">+ {{ t('opening.creditors.add') }}</button>
                    </div>

                    <h4>{{ t('opening.taxes.title') }}</h4>
                    <div class="line">
                        <div class="fld">
                            <label for="tx-vat">{{ t('opening.taxes.vat') }}</label>
                            <input id="tx-vat" v-model="form.taxes.vat_amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad('taxes.vat_amount')">
                            <span v-if="err('taxes.vat_amount')" class="dng small" role="alert">{{ err('taxes.vat_amount') }}</span>
                        </div>
                        <div class="fld">
                            <label for="tx-wh">{{ t('opening.taxes.withhold') }}</label>
                            <input id="tx-wh" v-model="form.taxes.credit_withhold_taxes" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad('taxes.credit_withhold_taxes')">
                            <span v-if="err('taxes.credit_withhold_taxes')" class="dng small" role="alert">{{ err('taxes.credit_withhold_taxes') }}</span>
                        </div>
                        <div class="fld">
                            <label for="tx-ct">{{ t('opening.taxes.corporate') }}</label>
                            <input id="tx-ct" v-model="form.taxes.corporate_taxes_payable" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad('taxes.corporate_taxes_payable')">
                            <span v-if="err('taxes.corporate_taxes_payable')" class="dng small" role="alert">{{ err('taxes.corporate_taxes_payable') }}</span>
                        </div>
                    </div>

                    <h4>{{ t('opening.loans.title') }}</h4>
                    <div data-section="loans">
                        <p v-if="!form.loans.length" class="empty">{{ t('opening.loans.empty') }}</p>
                        <div v-for="(r, i) in form.loans" :key="r.key" class="line">
                            <div class="fld">
                                <label :for="`ln-${i}-a`">{{ t('opening.loans.amount') }}</label>
                                <input :id="`ln-${i}-a`" v-model="r.amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad(`loans.${i}.amount`)">
                            </div>
                            <div class="fld">
                                <label>{{ t('opening.loans.installments') }}</label>
                                <button type="button" class="hirebtn" :class="{ has: monthCount(r.installments) > 0, bad: monthsError(`loans.${i}.installments`) }" :aria-invalid="monthsError(`loans.${i}.installments`)" @click="openMonths(r, 'installments', t('opening.loans.installments'), '', () => r.amount)">
                                    {{ monthCount(r.installments) ? t('opening.settleCount', { n: monthCount(r.installments) }) : t('opening.settleSet') }} <span aria-hidden="true">✎</span>
                                </button>
                                <span v-if="err(`loans.${i}.installments`)" class="dng small" role="alert">{{ err(`loans.${i}.installments`) }}</span>
                            </div>
                            <div class="fld">
                                <label>{{ t('opening.loans.interests') }}</label>
                                <button type="button" class="hirebtn" :class="{ has: monthCount(r.interests) > 0, bad: monthsError(`loans.${i}.interests`) }" :aria-invalid="monthsError(`loans.${i}.interests`)" @click="openMonths(r, 'interests', t('opening.loans.interests'), '', null)">
                                    {{ monthCount(r.interests) ? t('opening.settleCount', { n: monthCount(r.interests) }) : t('opening.settleSet') }} <span aria-hidden="true">✎</span>
                                </button>
                                <span v-if="err(`loans.${i}.interests`)" class="dng small" role="alert">{{ err(`loans.${i}.interests`) }}</span>
                            </div>
                            <button type="button" class="btn btn-line sm delbtn" @click="removing = { section: 'loans', index: i }">{{ t('opening.remove') }}</button>
                        </div>
                        <button type="button" class="btn btn-line sm" @click="addRow('loans')">+ {{ t('opening.loans.add') }}</button>
                    </div>

                    <h4>{{ t('opening.otherLong.title') }}</h4>
                    <div data-section="other_long_term">
                        <p v-if="!form.other_long_term.length" class="empty">{{ t('opening.otherLong.empty') }}</p>
                        <div v-for="(r, i) in form.other_long_term" :key="r.key" class="line">
                            <div class="fld">
                                <label :for="`ol-${i}-a`">{{ t('opening.amount') }}</label>
                                <input :id="`ol-${i}-a`" v-model="r.amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad(`other_long_term.${i}.amount`)">
                            </div>
                            <div class="fld">
                                <label>{{ t('opening.otherLong.payments') }}</label>
                                <button type="button" class="hirebtn" :class="{ has: monthCount(r.settlements) > 0, bad: monthsError(`other_long_term.${i}.settlements`) }" :aria-invalid="monthsError(`other_long_term.${i}.settlements`)" @click="openMonths(r, 'settlements', t('opening.otherLong.payments'), '', () => r.amount)">
                                    {{ monthCount(r.settlements) ? t('opening.settleCount', { n: monthCount(r.settlements) }) : t('opening.settleSet') }} <span aria-hidden="true">✎</span>
                                </button>
                                <span v-if="err(`other_long_term.${i}.settlements`)" class="dng small" role="alert">{{ err(`other_long_term.${i}.settlements`) }}</span>
                            </div>
                            <button type="button" class="btn btn-line sm delbtn" @click="removing = { section: 'other_long_term', index: i }">{{ t('opening.remove') }}</button>
                        </div>
                        <button type="button" class="btn btn-line sm" @click="addRow('other_long_term')">+ {{ t('opening.otherLong.add') }}</button>
                    </div>
                </section>

                <!-- EQUITY -->
                <section class="card" style="--ca: var(--k4)">
                    <h3>{{ t('opening.equity.title') }}</h3>
                    <div class="line">
                        <div class="fld">
                            <label for="eq-paid">{{ t('opening.equity.paidUp') }}</label>
                            <input id="eq-paid" v-model="form.equity.paid_up_capital_amount" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad('equity.paid_up_capital_amount')">
                            <span v-if="err('equity.paid_up_capital_amount')" class="dng small" role="alert">{{ err('equity.paid_up_capital_amount') }}</span>
                        </div>
                        <div class="fld">
                            <label for="eq-res">{{ t('opening.equity.reserve') }}</label>
                            <input id="eq-res" v-model="form.equity.legal_reserve" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="bad('equity.legal_reserve')">
                            <span v-if="err('equity.legal_reserve')" class="dng small" role="alert">{{ err('equity.legal_reserve') }}</span>
                        </div>
                        <div class="fld">
                            <label for="eq-ret">{{ t('opening.equity.retained') }}</label>
                            <input id="eq-ret" v-model="form.equity.retained_earnings" class="inp" dir="ltr" type="number" step="any" :aria-invalid="bad('equity.retained_earnings')">
                            <span v-if="err('equity.retained_earnings')" class="dng small" role="alert">{{ err('equity.retained_earnings') }}</span>
                        </div>
                        <div class="fld">
                            <label>{{ t('opening.equity.total') }}</label>
                            <output class="inp ro" dir="ltr">{{ show(equityTotal) }}</output>
                        </div>
                    </div>
                </section>
            </fieldset>

            <div class="toolbar">
                <button v-if="!readOnly" type="submit" class="btn" :disabled="form.processing">{{ form.processing ? t('products.actions.saving') : t('products.actions.save') }}</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" @click="save('list')">{{ t('products.actions.saveClose') }}</button>
                <Link :href="route('projects.assets.edit', project.id)" class="btn btn-line">← {{ t('opening.back') }}</Link>
                <Link :href="route('projects.edit', project.id)" class="btn btn-line">{{ t('common.cancel') }}</Link>
            </div>
        </form>

        <!-- The months of one balance -->
        <ModalDialog :open="isOpen('months')" wide :title="popup?.title || ''" :subtitle="t('opening.dialog.hint')" @close="closePopup">
            <div v-if="cells">
                <div class="spread">
                    <span class="mute small">{{ t('opening.dialog.spread') }}:</span>
                    <label class="small">{{ t('opening.dialog.from') }}
                        <select v-model.number="spreadFrom" class="sel sm" :aria-label="t('opening.dialog.from')">
                            <option v-for="m in months" :key="m.index" :value="m.index">{{ monthLabel(m.index) }}</option>
                        </select>
                    </label>
                    <label class="small">{{ t('opening.dialog.over') }}
                        <input v-model.number="spreadCount" class="inp sm" dir="ltr" type="number" min="1" :max="months.length" step="1" :aria-label="t('opening.dialog.over')">
                        {{ t('opening.dialog.months') }}
                    </label>
                    <button v-if="limit !== null" type="button" class="btn btn-line sm" @click="spread">{{ t('opening.dialog.apply') }}</button>
                    <button type="button" class="btn btn-line sm" @click="clearMonths">{{ t('opening.dialog.clear') }}</button>
                </div>
                <div class="tw nomax">
                    <table class="stm hire">
                        <thead>
                            <tr>
                                <th>{{ t('products.results.year') }}</th>
                                <th v-for="m in 12" :key="m" class="num">{{ t(`projects.months.${m}`) }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="y in years" :key="y.year">
                                <td dir="ltr">{{ y.year }}</td>
                                <td v-for="(index, m) in y.cells" :key="m">
                                    <CopyInput
                                        v-if="index !== null" v-model="cells[index]"
                                        :aria-label="`${monthLabel(index)}`"
                                        :copy="index !== order[order.length - 1]" @copy="copyRight(index)"
                                    />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <template #footer>
                <span v-if="cells && limit !== null" class="small grow" :class="entered > limit + 0.01 ? 'dng' : 'mute'">
                    {{ t('opening.dialog.settled', { a: formatNumber(entered, 0), b: formatNumber(limit, 0) }) }} · {{ t('opening.dialog.remaining', { n: formatNumber(limit - entered, 0) }) }}
                </span>
                <span v-else-if="cells" class="mute small grow">{{ t('opening.dialog.entered', { n: formatNumber(entered, 0) }) }}</span>
            </template>
        </ModalDialog>

        <!-- Sharing the depreciation of an owned asset between the products -->
        <ModalDialog :open="isOpen('allocation')" :title="t('opening.fixed.allocateTitle', { name: editAsset?.name || t('opening.fixed.name') })" :subtitle="t('opening.fixed.allocateHint')" @close="closePopup">
            <div v-if="editAsset">
                <label class="chk"><input v-model="editAsset.is_as_revenue_percentages" type="checkbox"> {{ t('opening.fixed.byRevenueLabel') }}</label>
                <div v-if="!editAsset.is_as_revenue_percentages" class="grid3 fgrid">
                    <div v-for="p in products" :key="p.id" class="fld">
                        <label :for="`al-${p.id}`">{{ p.name }}</label>
                        <input :id="`al-${p.id}`" v-model="editAsset.allocations[p.id]" class="inp" dir="ltr" type="number" step="any" min="0" max="100">
                    </div>
                    <p class="small total" :class="Math.abs(shareTotal(editAsset) - 100) > 0.01 ? 'dng' : 'okc'">{{ t('opening.fixed.shareTotal', { n: formatNumber(shareTotal(editAsset), 2) }) }}</p>
                </div>
            </div>
        </ModalDialog>

        <ConfirmDialog :open="removing !== null" :title="t('opening.removeTitle')" :confirm-label="t('projects.form.removeAction')" danger @confirm="doRemove" @cancel="removing = null">
            <p>{{ t('opening.removeText') }}</p>
        </ConfirmDialog>
    </AppLayout>
</template>

<style scoped>
h4 { margin: 18px 0 8px; font-size: 15px; color: var(--ink); }
h4:first-of-type { margin-top: 8px; }
.sticky { position: sticky; top: 0; z-index: 5; }
.okTile b { color: var(--green-ink); }
.badTile b { color: var(--red); }
.rep { padding: 14px; margin-bottom: 12px; border: 1px solid color-mix(in srgb, var(--ca) 30%, var(--line)); border-inline-start: 5px solid var(--ca); border-radius: var(--r-md); background: color-mix(in srgb, var(--ca) 4%, var(--card)); }
.repTop, .line { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; align-items: end; }
.line { padding: 10px 12px; margin-bottom: 10px; border-radius: var(--r-sm); background: color-mix(in srgb, var(--ca) 5%, var(--card)); }
.repTop + .repTop { margin-top: 12px; padding-top: 12px; border-top: 1px dashed var(--line-2); }
@media (min-width: 900px) { .repTop .w2, .line .w2 { grid-column: span 2; } }
.ro { display: block; line-height: 22px; background: color-mix(in srgb, var(--ca) 8%, var(--card)); color: var(--ink); font-weight: 700; }
.hirebtn {
    display: inline-flex; align-items: center; gap: 8px; width: 100%; justify-content: space-between; white-space: nowrap;
    border: 1.5px dashed var(--line-2); background: var(--card); color: var(--mute); border-radius: var(--r-sm);
    padding: 8px 12px; cursor: pointer; font: inherit; font-weight: 600;
}
.hirebtn:hover { border-color: var(--ca); color: var(--ink); }
.hirebtn.has { border-style: solid; border-color: var(--ca); background: color-mix(in srgb, var(--ca) 12%, var(--card)); color: var(--ink); }
.hirebtn.bad { border-color: var(--red); }
.spread { display: flex; flex-wrap: wrap; gap: 10px 14px; align-items: center; margin-bottom: 12px; padding: 8px 12px; border-radius: var(--r-sm); background: color-mix(in srgb, var(--k3) 10%, var(--card)); }
.spread .inp.sm { width: 70px; display: inline-block; }
.spread .sel.sm { display: inline-block; width: auto; }
.hire td { min-width: 104px; }
.grow { margin-inline-end: auto; }
.chk { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; }
.total { margin-top: 10px; font-weight: 700; }
.okc { color: var(--green-ink); }
</style>
