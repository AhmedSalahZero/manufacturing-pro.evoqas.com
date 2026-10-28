<!--
  ZAVERO Manufacturing Pro — A product: sales, monthly pattern, inventory, collections, raw materials
  ( /projects/{id}/products/{id}/edit )
  Location: resources/js/Pages/Products/Edit.vue
  Saved by App\Http\Controllers\ProductController@update (App\Services\ProductPlanService), which works the yearly
  quantities and values out again, recalculates the whole study and sends back the monthly results.
  The arithmetic you see while typing is in resources/js/Utils/productPlan.js.
-->
<script setup>
import { computed, nextTick, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CollectionTerms from '@/Components/CollectionTerms.vue';
import CopyInput from '@/Components/CopyInput.vue';
import TermsPair from '@/Components/TermsPair.vue';
import { useI18n } from '@/lang/i18n';
import { formatNumber } from '@/Utils/format';
import {
    addsUpTo100, exportPercent, exportQuantity, localQuantity, num, partValue, repriceFrom, targetQuantity, total, yearSales,
} from '@/Utils/productPlan';

const props = defineProps({
    project: { type: Object, required: true },
    product: { type: Object, required: true },
    years: { type: Array, required: true },
    sales: { type: Object, required: true },
    seasonality: { type: Object, required: true },
    inventory: { type: Object, required: true },
    policy: { type: Object, required: true },
    recipe: { type: Array, required: true },
    rawMaterials: { type: Array, required: true },
    nextProduct: { type: Number, default: null },
    results: { type: Array, default: () => [] },
    readOnly: { type: Boolean, default: false },
    options: { type: Object, required: true },
});

const { t } = useI18n();

const copyPolicy = (list) => list.map((p) => ({ ...p, rate: [...p.rate], due_in_days: [...p.due_in_days] }));

const form = useForm({
    max_capacity: { ...props.sales.max_capacity },
    target_percentages: { ...props.sales.target_percentages },
    local_target_quantity_percentages: { ...props.sales.local_target_quantity_percentages },
    local_growth_rates: { ...props.sales.local_growth_rates },
    local_price_per_unit: { ...props.sales.local_price_per_unit },
    export_growth_rates: { ...props.sales.export_growth_rates },
    export_price_per_unit: { ...props.sales.export_price_per_unit },
    seasonality: { type: props.seasonality.type, quarterly: [...props.seasonality.quarterly], monthly: [...props.seasonality.monthly] },
    fg_inventory_quantity: props.inventory.quantity,
    fg_inventory_value: props.inventory.value,
    fg_inventory_coverage_days: props.inventory.coverage,
    fg_beginning_inventory_breakdowns: Object.fromEntries(Object.entries(props.inventory.breakdowns).map(([k, v]) => [k, { percentage: v }])),
    collection_policy_value: { local: copyPolicy(props.policy.local), export: copyPolicy(props.policy.export) },
    rawMaterials: props.recipe.map((r) => ({ raw_material_id: r.raw_material_id, percentages: { ...r.percentages } })),
    comment_for_sales: props.product.comment ?? '',
    after: 'stay',
});

const yearIndexes = computed(() => props.years.map((y) => y.index));
const err = (path) => form.errors[path] ?? '';
const invalid = (path) => Boolean(form.errors[path]);

// ── Sales table ──────────────────────────────────────────────────
const target = (y) => targetQuantity(form.max_capacity[y], form.target_percentages[y]);
const local = (y) => localQuantity(form.max_capacity[y], form.target_percentages[y], form.local_target_quantity_percentages[y]);
const exported = (y) => exportQuantity(form.max_capacity[y], form.target_percentages[y], form.local_target_quantity_percentages[y]);
const value = (y) => yearSales(form.max_capacity[y], form.target_percentages[y], form.local_target_quantity_percentages[y], form.local_price_per_unit[y], form.export_price_per_unit[y]);
const show = (n) => formatNumber(n, 1);

// A price follows the year before it (grown by the year's growth %); typing a price or a growth % updates the years after it.
function growthChanged(kind, position) {
    const prices = kind === 'local' ? 'local_price_per_unit' : 'export_price_per_unit';
    const growth = kind === 'local' ? 'local_growth_rates' : 'export_growth_rates';
    form[prices] = repriceFrom(form[prices], form[growth], yearIndexes.value, position);
}
function priceChanged(kind, position) {
    growthChanged(kind, position + 1);
}

// The three dots next to a number copy it into the cells after it in the same row.
function copyRight(map, position) {
    const from = map[yearIndexes.value[position]];
    yearIndexes.value.slice(position + 1).forEach((y) => { map[y] = from; });
}
function copyArray(list, position) {
    for (let i = position + 1; i < list.length; i++) list[i] = list[position];
}
// A price copied to the following years makes them equal, so their growth is 0 %.
function copyPrice(kind, position) {
    const growth = kind === 'local' ? form.local_growth_rates : form.export_growth_rates;
    copyRight(kind === 'local' ? form.local_price_per_unit : form.export_price_per_unit, position);
    yearIndexes.value.slice(position + 1).forEach((y) => { growth[y] = 0; });
}
// A growth % copied to the following years re-prices them one after the other.
function copyGrowth(kind, position) {
    copyRight(kind === 'local' ? form.local_growth_rates : form.export_growth_rates, position);
    growthChanged(kind, position + 1);
}

// ── Monthly pattern ──────────────────────────────────────────────
const patternTotal = computed(() => (form.seasonality.type === 'flat' ? 100 : total(form.seasonality[form.seasonality.type])));
const patternOk = computed(() => form.seasonality.type === 'flat' || addsUpTo100(form.seasonality[form.seasonality.type]));
const monthName = (n) => t(`projects.months.${n}`);

// ── Opening inventory ────────────────────────────────────────────
const parts = ['raw_material_value', 'direct_labor_value', 'manufacturing_overheads_value'];
const partsTotal = computed(() => parts.reduce((s, p) => s + num(form.fg_beginning_inventory_breakdowns[p].percentage), 0));

// ── Raw materials (the recipe) ───────────────────────────────────
const chosen = computed(() => new Set(form.rawMaterials.map((r) => Number(r.raw_material_id))));
const available = computed(() => props.rawMaterials.filter((m) => !chosen.value.has(m.id)));
const materialName = (id) => props.rawMaterials.find((m) => m.id === Number(id))?.name ?? '';
const unitLabel = (unit) => t(`projects.units.${unit}`);
function addMaterial(event) {
    const id = Number(event.target.value);
    if (!id) return;
    form.rawMaterials.push({ raw_material_id: id, percentages: Object.fromEntries(yearIndexes.value.map((y) => [y, 0])) });
    event.target.value = '';
}
const yearRecipeTotal = (y) => form.rawMaterials.reduce((s, r) => s + num(r.percentages[y]), 0);
const overYears = computed(() => props.years.filter((y) => yearRecipeTotal(y.index) > 100.0001));

// ── Calculated results: each year can open to show its local and export sales ──
const openYears = ref(new Set());
const isOpen = (year) => openYears.value.has(year);
const allOpen = computed(() => props.results.length > 0 && props.results.every((r) => openYears.value.has(r.year)));
function toggle(year) {
    const next = new Set(openYears.value);
    next.has(year) ? next.delete(year) : next.add(year);
    openYears.value = next;
}
function toggleAll() {
    openYears.value = allOpen.value ? new Set() : new Set(props.results.map((r) => r.year));
}

// ── Saving ───────────────────────────────────────────────────────
function save(after) {
    form.after = after;
    form.put(route('projects.products.update', [props.project.id, props.product.id]), {
        preserveScroll: true,
        onError: async () => {
            await nextTick();
            document.querySelector('[aria-invalid="true"], .terms [role="alert"], [role="alert"].dng')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },
    });
}
const periodTitle = (stream, i) => `${t(`products.collection.${stream}`)} — ${i === 0 ? t('products.collection.firstYear') : t('products.collection.laterYears')}`;
</script>

<template>
    <AppLayout :title="product.name" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ product.name }}</h2>
                <p class="lead mb0">
                    <Link :href="route('projects.edit', project.id)">← {{ project.name }}</Link>
                    · {{ unitLabel(product.measurement_unit) }}
                    · {{ t('products.info.sellingStart') }}: <span dir="ltr">{{ product.selling_start ?? '—' }}</span>
                    · {{ t('products.info.vat') }} {{ product.vat_rate }}% · {{ t('products.info.withhold') }} {{ product.withhold_rate }}%
                </p>
            </div>
        </div>

        <div v-if="form.hasErrors" class="note bad" role="alert">{{ t('products.fixErrors') }}</div>
        <div v-if="readOnly" class="note" role="status">{{ t('products.readOnly') }}</div>

        <form novalidate @submit.prevent="save('stay')">
            <fieldset :disabled="readOnly" style="border:0;margin:0;padding:0;min-width:0">

                <!-- Yearly sales -->
                <section class="card">
                    <h3>{{ t('products.sales.title') }}</h3>
                    <p class="mute small">{{ t('products.sales.hint', { unit: unitLabel(product.measurement_unit) }) }}</p>
                    <div class="tw nomax">
                        <table class="stm">
                            <thead>
                                <tr>
                                    <th />
                                    <th v-for="y in years" :key="y.index" class="num" dir="ltr">{{ y.year }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>{{ t('products.sales.capacity') }}</td>
                                    <td v-for="(y, pos) in years" :key="y.index">
                                        <CopyInput v-model="form.max_capacity[y.index]" :aria-label="`${t('products.sales.capacity')} ${y.year}`" :invalid="invalid(`max_capacity.${y.index}`)" :copy="pos < years.length - 1" @copy="copyRight(form.max_capacity, pos)" />
                                        <span v-if="err(`max_capacity.${y.index}`)" class="dng small" role="alert">{{ err(`max_capacity.${y.index}`) }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>{{ t('products.sales.targetPercent') }}</td>
                                    <td v-for="(y, pos) in years" :key="y.index">
                                        <CopyInput v-model="form.target_percentages[y.index]" :max="100" :aria-label="`${t('products.sales.targetPercent')} ${y.year}`" :invalid="invalid(`target_percentages.${y.index}`)" :copy="pos < years.length - 1" @copy="copyRight(form.target_percentages, pos)" />
                                        <span v-if="err(`target_percentages.${y.index}`)" class="dng small" role="alert">{{ err(`target_percentages.${y.index}`) }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><b>{{ t('products.sales.targetQuantity') }}</b></td>
                                    <td v-for="y in years" :key="y.index" class="num" dir="ltr">{{ show(target(y.index)) }}</td>
                                </tr>
                                <tr>
                                    <td>{{ t('products.sales.localPercent') }}</td>
                                    <td v-for="(y, pos) in years" :key="y.index">
                                        <CopyInput v-model="form.local_target_quantity_percentages[y.index]" :max="100" :aria-label="`${t('products.sales.localPercent')} ${y.year}`" :invalid="invalid(`local_target_quantity_percentages.${y.index}`)" :copy="pos < years.length - 1" @copy="copyRight(form.local_target_quantity_percentages, pos)" />
                                        <span v-if="err(`local_target_quantity_percentages.${y.index}`)" class="dng small" role="alert">{{ err(`local_target_quantity_percentages.${y.index}`) }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><b>{{ t('products.sales.localQuantity') }}</b></td>
                                    <td v-for="y in years" :key="y.index" class="num" dir="ltr">{{ show(local(y.index)) }}</td>
                                </tr>
                                <tr>
                                    <td>{{ t('products.sales.localGrowth') }}</td>
                                    <td v-for="(y, pos) in years" :key="y.index">
                                        <CopyInput v-if="pos > 0" v-model="form.local_growth_rates[y.index]" :aria-label="`${t('products.sales.localGrowth')} ${y.year}`" :invalid="invalid(`local_growth_rates.${y.index}`)" :copy="pos < years.length - 1" @change="growthChanged('local', pos)" @copy="copyGrowth('local', pos)" />
                                        <span v-else class="mute">—</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>{{ t('products.sales.localPrice') }}</td>
                                    <td v-for="(y, pos) in years" :key="y.index">
                                        <CopyInput v-model="form.local_price_per_unit[y.index]" :aria-label="`${t('products.sales.localPrice')} ${y.year}`" :invalid="invalid(`local_price_per_unit.${y.index}`)" :copy="pos < years.length - 1" @change="priceChanged('local', pos)" @copy="copyPrice('local', pos)" />
                                        <span v-if="err(`local_price_per_unit.${y.index}`)" class="dng small" role="alert">{{ err(`local_price_per_unit.${y.index}`) }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>{{ t('products.sales.exportPercent') }}</td>
                                    <td v-for="y in years" :key="y.index" class="num" dir="ltr">{{ show(exportPercent(form.local_target_quantity_percentages[y.index])) }}%</td>
                                </tr>
                                <tr>
                                    <td><b>{{ t('products.sales.exportQuantity') }}</b></td>
                                    <td v-for="y in years" :key="y.index" class="num" dir="ltr">{{ show(exported(y.index)) }}</td>
                                </tr>
                                <tr>
                                    <td>{{ t('products.sales.exportGrowth') }}</td>
                                    <td v-for="(y, pos) in years" :key="y.index">
                                        <CopyInput v-if="pos > 0" v-model="form.export_growth_rates[y.index]" :aria-label="`${t('products.sales.exportGrowth')} ${y.year}`" :invalid="invalid(`export_growth_rates.${y.index}`)" :copy="pos < years.length - 1" @change="growthChanged('export', pos)" @copy="copyGrowth('export', pos)" />
                                        <span v-else class="mute">—</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td>{{ t('products.sales.exportPrice') }}</td>
                                    <td v-for="(y, pos) in years" :key="y.index">
                                        <CopyInput v-model="form.export_price_per_unit[y.index]" :aria-label="`${t('products.sales.exportPrice')} ${y.year}`" :invalid="invalid(`export_price_per_unit.${y.index}`)" :copy="pos < years.length - 1" @change="priceChanged('export', pos)" @copy="copyPrice('export', pos)" />
                                        <span v-if="err(`export_price_per_unit.${y.index}`)" class="dng small" role="alert">{{ err(`export_price_per_unit.${y.index}`) }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td><b>{{ t('products.sales.salesValue') }}</b></td>
                                    <td v-for="y in years" :key="y.index" class="num" dir="ltr"><b>{{ formatNumber(value(y.index), 0) }}</b></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mute small">{{ t('products.sales.derivedNote') }}</p>
                </section>

                <!-- Monthly pattern -->
                <section class="card">
                    <h3>{{ t('products.season.title') }}</h3>
                    <p class="mute small">{{ t('products.season.hint') }}</p>
                    <div class="seg" role="radiogroup" :aria-label="t('products.season.title')">
                        <button v-for="type in ['flat', 'quarterly', 'monthly']" :key="type" type="button" class="btn" :class="{ 'btn-line': form.seasonality.type !== type }" role="radio" :aria-checked="form.seasonality.type === type" @click="form.seasonality.type = type">
                            {{ t(`products.season.${type}`) }}
                        </button>
                    </div>

                    <p v-if="form.seasonality.type === 'flat'" class="mute small mt2">{{ t('products.season.flatNote') }}</p>

                    <div v-else-if="form.seasonality.type === 'quarterly'" class="grid3 fgrid mt2">
                        <div v-for="q in 4" :key="q" class="fld">
                            <label>{{ t('products.season.quarter', { n: q }) }} (%)</label>
                            <CopyInput v-model="form.seasonality.quarterly[q - 1]" :max="100" :aria-label="t('products.season.quarter', { n: q })" :invalid="invalid(`seasonality.quarterly.${q - 1}`)" :copy="q < 4" @copy="copyArray(form.seasonality.quarterly, q - 1)" />
                        </div>
                    </div>

                    <div v-else class="grid3 fgrid mt2">
                        <div v-for="m in 12" :key="m" class="fld">
                            <label>{{ monthName(m) }} (%)</label>
                            <CopyInput v-model="form.seasonality.monthly[m - 1]" :max="100" :aria-label="monthName(m)" :invalid="invalid(`seasonality.monthly.${m - 1}`)" :copy="m < 12" @copy="copyArray(form.seasonality.monthly, m - 1)" />
                        </div>
                    </div>

                    <p v-if="form.seasonality.type !== 'flat'" class="mt2">
                        <span class="badge" :class="patternOk ? 'b-green' : 'b-red'" role="status">{{ t('terms.total', { total: Math.round(patternTotal * 100) / 100 }) }}</span>
                    </p>
                    <span v-if="err(`seasonality.${form.seasonality.type}`)" class="dng small" role="alert">{{ err(`seasonality.${form.seasonality.type}`) }}</span>
                </section>

                <!-- Finished goods inventory -->
                <section class="card">
                    <h3>{{ t('products.inventory.title') }}</h3>
                    <p class="mute small">{{ t('products.inventory.hint') }}</p>

                    <div class="grid3 fgrid">
                        <div class="fld">
                            <label for="fg-days">{{ t('products.inventory.coverage') }}</label>
                            <select id="fg-days" v-model="form.fg_inventory_coverage_days" class="sel" dir="ltr" :aria-invalid="invalid('fg_inventory_coverage_days')">
                                <option v-for="d in options.coverageDays" :key="d" :value="d">{{ d === 0 ? t('projects.coverageNone') : t('projects.coverageDays', { n: d }) }}</option>
                            </select>
                            <span v-if="err('fg_inventory_coverage_days')" class="dng small" role="alert">{{ err('fg_inventory_coverage_days') }}</span>
                        </div>
                        <template v-if="!project.new_company">
                            <div class="fld">
                                <label for="fg-qty">{{ t('products.inventory.quantity') }}</label>
                                <input id="fg-qty" v-model="form.fg_inventory_quantity" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="invalid('fg_inventory_quantity')">
                                <span v-if="err('fg_inventory_quantity')" class="dng small" role="alert">{{ err('fg_inventory_quantity') }}</span>
                            </div>
                            <div class="fld">
                                <label for="fg-value">{{ t('products.inventory.value') }}</label>
                                <input id="fg-value" v-model="form.fg_inventory_value" class="inp" dir="ltr" type="number" step="any" min="0" :aria-invalid="invalid('fg_inventory_value')">
                                <span v-if="err('fg_inventory_value')" class="dng small" role="alert">{{ err('fg_inventory_value') }}</span>
                            </div>
                        </template>
                    </div>

                    <p v-if="project.new_company" class="mute small">{{ t('products.inventory.newCompanyNote') }}</p>

                    <div v-else class="tw nomax mt2">
                        <table class="stm">
                            <thead>
                                <tr>
                                    <th>{{ t('products.inventory.split') }}</th>
                                    <th class="num">%</th>
                                    <th class="num">{{ t('products.inventory.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in parts" :key="p">
                                    <td>{{ t(`products.inventory.parts.${p}`) }}</td>
                                    <td>
                                        <input v-model="form.fg_beginning_inventory_breakdowns[p].percentage" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-label="t(`products.inventory.parts.${p}`)">
                                    </td>
                                    <td class="num" dir="ltr">{{ formatNumber(partValue(form.fg_inventory_value, form.fg_beginning_inventory_breakdowns[p].percentage), 0) }}</td>
                                </tr>
                                <tr>
                                    <td><b>{{ t('terms.totalLabel') }}</b></td>
                                    <td class="num" dir="ltr"><span class="badge" :class="addsUpTo100(partsTotal) || num(form.fg_inventory_value) === 0 ? 'b-green' : 'b-red'">{{ Math.round(partsTotal * 100) / 100 }}%</span></td>
                                    <td class="num" dir="ltr">{{ formatNumber(num(form.fg_inventory_value), 0) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <span v-if="err('fg_beginning_inventory_breakdowns')" class="dng small" role="alert">{{ err('fg_beginning_inventory_breakdowns') }}</span>
                </section>

                <!-- Collections -->
                <section class="card">
                    <h3>{{ t('products.collection.title') }}</h3>
                    <p class="mute small">{{ t('products.collection.hint') }}</p>
                    <TermsPair v-for="stream in ['local', 'export']" :key="stream" :single="form.collection_policy_value[stream].length < 2">
                        <CollectionTerms
                            v-for="(p, i) in form.collection_policy_value[stream]" :key="`${stream}-${i}`"
                            v-model="form.collection_policy_value[stream][i]"
                            :title="periodTitle(stream, i)" :due-days="options.dueDays" :error="err(`collection_policy_value.${stream}.${i}`)"
                            :id-prefix="`cp-${stream}-${i}`" kind="collect" :class="{ later: i > 0 }"
                        />
                    </TermsPair>
                    <p class="mute small">{{ t('products.collection.exportNote') }}</p>
                </section>

                <!-- Raw materials -->
                <section class="card">
                    <h3>{{ t('products.recipe.title') }}</h3>
                    <p class="mute small">{{ t('products.recipe.hint') }}</p>
                    <span v-if="err('rawMaterials')" class="dng small" role="alert">{{ err('rawMaterials') }}</span>

                    <div v-if="form.rawMaterials.length" class="tw nomax">
                        <table class="stm">
                            <thead>
                                <tr>
                                    <th>{{ t('products.recipe.material') }}</th>
                                    <th v-for="y in years" :key="y.index" class="num" dir="ltr">{{ y.year }}</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(line, i) in form.rawMaterials" :key="line.raw_material_id">
                                    <td>{{ materialName(line.raw_material_id) }} <span class="mute small">({{ t('products.recipe.ofPrice') }})</span></td>
                                    <td v-for="(y, pos) in years" :key="y.index">
                                        <CopyInput v-model="line.percentages[y.index]" :max="100" suffix="%" :aria-label="`${materialName(line.raw_material_id)} ${y.year}`" :invalid="invalid(`rawMaterials.${i}.percentages.${y.index}`)" :copy="pos < years.length - 1" @copy="copyRight(line.percentages, pos)" />
                                    </td>
                                    <td><button type="button" class="btn btn-line sm" @click="form.rawMaterials.splice(i, 1)">{{ t('products.recipe.remove') }}</button></td>
                                </tr>
                                <tr>
                                    <td><b>{{ t('terms.totalLabel') }}</b></td>
                                    <td v-for="y in years" :key="y.index" class="num" dir="ltr">
                                        <span class="badge" :class="yearRecipeTotal(y.index) > 100.0001 ? 'b-amber' : 'b-green'">{{ Math.round(yearRecipeTotal(y.index) * 100) / 100 }}%</span>
                                    </td>
                                    <td />
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="empty">{{ t('products.recipe.none') }}</p>

                    <div v-if="overYears.length" class="note" role="status">{{ t('products.recipe.over', { years: overYears.map((y) => y.year).join(', ') }) }}</div>

                    <div v-if="available.length" class="fld mt2" style="max-width:320px">
                        <label for="add-material">{{ t('products.recipe.add') }}</label>
                        <select id="add-material" class="sel" @change="addMaterial">
                            <option value="">{{ t('products.recipe.pick') }}</option>
                            <option v-for="m in available" :key="m.id" :value="m.id">{{ m.name }} ({{ unitLabel(m.measurement_unit) }})</option>
                        </select>
                    </div>
                    <p v-else-if="!rawMaterials.length" class="mute small">{{ t('products.recipe.noneInStudy') }}</p>
                </section>

                <!-- Comment -->
                <section class="card">
                    <h3>{{ t('products.comment.title') }}</h3>
                    <textarea v-model="form.comment_for_sales" class="pin" rows="4" style="width:100%;text-align:start" :aria-label="t('products.comment.title')" />
                    <span v-if="err('comment_for_sales')" class="dng small" role="alert">{{ err('comment_for_sales') }}</span>
                </section>
            </fieldset>

            <!-- Calculated results -->
            <section class="card">
                <h3>{{ t('products.results.title') }}</h3>
                <p class="mute small">{{ t('products.results.hint') }}</p>
                <div v-if="results.length" class="tw nomax">
                    <p class="mb2"><button type="button" class="btn btn-line sm" @click="toggleAll">{{ allOpen ? t('products.results.collapseAll') : t('products.results.expandAll') }}</button></p>
                    <table class="stm">
                        <thead>
                            <tr>
                                <th>{{ t('products.results.year') }}</th>
                                <th class="num">{{ t('products.results.salesValue') }}</th>
                                <th class="num">{{ t('products.results.salesQuantity') }}</th>
                                <th class="num">{{ t('products.results.madeQuantity') }}</th>
                                <th class="num">{{ t('products.results.closingStock') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="r in results" :key="r.year">
                                <tr>
                                    <td dir="ltr">
                                        <button type="button" class="rowtoggle" :aria-expanded="isOpen(r.year)" :aria-label="isOpen(r.year) ? t('products.results.collapse') : t('products.results.expand')" @click="toggle(r.year)">{{ isOpen(r.year) ? '▾' : '▸' }}</button>
                                        {{ r.year }}
                                    </td>
                                    <td class="num" dir="ltr">{{ formatNumber(r.sales_value, 0) }}</td>
                                    <td class="num" dir="ltr">{{ formatNumber(r.sales_quantity, 1) }}</td>
                                    <td class="num" dir="ltr">{{ formatNumber(r.made_quantity, 1) }}</td>
                                    <td class="num" dir="ltr">{{ formatNumber(r.closing_inventory, 1) }}</td>
                                </tr>
                                <template v-if="isOpen(r.year)">
                                    <tr class="subrow">
                                        <td>{{ t('products.results.localSales') }}</td>
                                        <td class="num" dir="ltr">{{ formatNumber(r.local_value ?? 0, 0) }}</td>
                                        <td class="num" dir="ltr">{{ formatNumber(r.local_quantity ?? 0, 1) }}</td>
                                        <td /><td />
                                    </tr>
                                    <tr class="subrow">
                                        <td>{{ t('products.results.exportSales') }}</td>
                                        <td class="num" dir="ltr">{{ formatNumber(r.export_value ?? 0, 0) }}</td>
                                        <td class="num" dir="ltr">{{ formatNumber(r.export_quantity ?? 0, 1) }}</td>
                                        <td /><td />
                                    </tr>
                                </template>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p v-else class="empty">{{ t('products.results.none') }}</p>
            </section>

            <div class="toolbar">
                <button v-if="!readOnly" type="submit" class="btn" :disabled="form.processing">{{ form.processing ? t('products.actions.saving') : t('products.actions.save') }}</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" @click="save('next')">{{ nextProduct ? t('products.actions.saveNext') : t('products.actions.saveRaw') }}</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" @click="save('list')">{{ t('products.actions.saveClose') }}</button>
                <Link :href="route('projects.edit', project.id)" class="btn btn-line">{{ t('common.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>

<style scoped>
.rowtoggle {
    border: 0;
    background: transparent;
    color: var(--blue);
    cursor: pointer;
    padding: 0 6px;
}
.subrow td {
    color: var(--mute, #6b7280);
    font-size: 0.92em;
}
.subrow td:first-child {
    padding-inline-start: 28px;
}
</style>
