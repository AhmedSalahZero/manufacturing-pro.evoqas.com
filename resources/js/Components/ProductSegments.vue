<!--
  ZAVERO Manufacturing Pro — ProductSegments (a product's sales channels and export markets)
  Location: resources/js/Components/ProductSegments.vue
  Two tabs: Local (channels) and Export (markets, only when the study has export sales).
    Local:  share of the local quantity per channel and year · price difference % per channel and year · what results · collection terms
    Export: share of the export quantity per market and year · price in the market's currency (first price, yearly growth) ·
            the exchange rates of the study (read only) · what results in both currencies · collection terms
  What is typed is saved on the page's own form (the rows are changed in place); the server works the same figures out again when saving.
  <ProductSegments :years="years" :segments="form.segments" :settings="salesSettings" :local-quantity="{0: 6000}" ... />
-->
<script setup>
import { computed, ref } from 'vue';
import CollectionTerms from '@/Components/CollectionTerms.vue';
import CopyInput from '@/Components/CopyInput.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import { useI18n } from '@/lang/i18n';
import { formatNumber } from '@/Utils/format';
import { addsUpTo100 } from '@/Utils/productPlan';
import { averagePrice, exportLines, foreignPrices, localLines, shareTotal } from '@/Utils/segments';

const props = defineProps({
    years: { type: Array, required: true },
    // { local: [...channel rows], export: [...market rows] } — changed in place
    segments: { type: Object, required: true },
    // { hasExport, currency, channels, markets, fx: { usd: { 0: 50, ... } } }
    settings: { type: Object, required: true },
    // quantities of each year as worked out by the product's sales table: year index → number
    localQuantity: { type: Object, required: true },
    exportQuantity: { type: Object, required: true },
    basePrice: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    dueDays: { type: Array, required: true },
    periods: { type: Number, default: 1 },
    readOnly: { type: Boolean, default: false },
});

const { t } = useI18n();

const tab = ref('local');
const kinds = computed(() => (props.settings.hasExport ? ['local', 'export'] : ['local']));
const yearIndexes = computed(() => props.years.map((y) => y.index));
const rows = computed(() => props.segments[tab.value] ?? []);
const err = (path) => props.errors[path] ?? '';
const show = (n, d = 1) => formatNumber(n, d);

function copyRight(map, position) {
    const from = map[yearIndexes.value[position]];
    yearIndexes.value.slice(position + 1).forEach((y) => { map[y] = from; });
}

const label = (key, vars) => t(`products.segments.${key}`, vars);
// The euro is stored as 'euro' and shown as EUR.
const currencyName = (code) => (code === 'euro' ? 'EUR' : String(code ?? '').toUpperCase());
const fxCurrencies = computed(() => [...new Set((props.segments.export ?? []).map((r) => r.currency))]);

// ── what results ──
const lines = computed(() => yearIndexes.value.map((y) => (tab.value === 'local'
    ? localLines(rows.value, y, props.localQuantity[y], props.basePrice[y])
    : exportLines(rows.value, y, yearIndexes.value, props.exportQuantity[y], props.settings.fx, (m) => m.currency))));
const yearTotal = (position, field) => lines.value[position].reduce((s, l) => s + l[field], 0);
const average = (position) => averagePrice(lines.value[position]);
const foreignOf = (row, y) => foreignPrices(row.first_foreign_price, row.foreign_growth_rates, yearIndexes.value)[y] ?? 0;

const totalOk = (y) => addsUpTo100(shareTotal(rows.value, y)) || (tab.value === 'local' ? props.localQuantity[y] : props.exportQuantity[y]) <= 0;

// ── own collection terms (a pop-up per channel / market) ──
const editing = ref(null);
const editRow = computed(() => (editing.value === null ? null : rows.value[editing.value]));
const rowName = (row) => row.name;
const periodTitle = (i) => (i === 0 ? t('products.collection.firstYear') : t('products.collection.laterYears'));
</script>

<template>
    <section class="card">
        <h3>{{ label('title') }}</h3>
        <p class="mute small">{{ label('hint') }}</p>

        <div v-if="kinds.length > 1" class="seg" role="radiogroup" :aria-label="label('title')">
            <button v-for="k in kinds" :key="k" type="button" class="btn" :class="{ 'btn-line': tab !== k }" role="radio" :aria-checked="tab === k" @click="tab = k">
                {{ label(k === 'local' ? 'tabLocal' : 'tabExport') }}
            </button>
        </div>

        <p v-if="!rows.length" class="empty mt2">{{ label(tab === 'local' ? 'noChannels' : 'noMarkets') }}</p>

        <template v-else>
            <span v-if="err(`segments.${tab}`)" class="dng small" role="alert">{{ err(`segments.${tab}`) }}</span>

            <!-- Share of the quantity -->
            <h4 class="mt2">{{ label(tab === 'local' ? 'allocationLocal' : 'allocationExport') }}</h4>
            <p class="mute small">{{ label('allocationHint') }}</p>
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
                            <td class="mute">{{ label(tab === 'local' ? 'quantityLocal' : 'quantityExport') }}</td>
                            <td v-for="y in years" :key="y.index" class="num mute" dir="ltr">{{ show(tab === 'local' ? localQuantity[y.index] : exportQuantity[y.index]) }}</td>
                        </tr>
                        <tr v-for="(row, i) in rows" :key="tab + (row.sales_channel_id ?? row.export_market_id)">
                            <td>{{ rowName(row) }}<span v-if="tab === 'export'" class="mute small"> ({{ currencyName(row.currency) }})</span></td>
                            <td v-for="(y, pos) in years" :key="y.index">
                                <CopyInput v-model="row.allocation_percentages[y.index]" :max="100" suffix="%" :aria-label="`${rowName(row)} ${y.year}`" :invalid="Boolean(err(`segments.${tab}.${i}.allocation_percentages.${y.index}`))" :copy="pos < years.length - 1" @copy="copyRight(row.allocation_percentages, pos)" />
                            </td>
                        </tr>
                        <tr>
                            <td><b>{{ t('terms.totalLabel') }}</b></td>
                            <td v-for="y in years" :key="y.index" class="num" dir="ltr">
                                <span class="badge" :class="totalOk(y.index) ? 'b-green' : 'b-red'" role="status">{{ Math.round(shareTotal(rows, y.index) * 100) / 100 }}%</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Price -->
            <template v-if="tab === 'local'">
                <h4 class="mt2">{{ label('difference') }}</h4>
                <p class="mute small">{{ label('differenceHint') }}</p>
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
                                <td class="mute">{{ t('products.sales.localPrice') }}</td>
                                <td v-for="y in years" :key="y.index" class="num mute" dir="ltr">{{ show(basePrice[y.index], 0) }}</td>
                            </tr>
                            <tr v-for="(row, i) in rows" :key="'d' + row.sales_channel_id">
                                <td>{{ rowName(row) }}</td>
                                <td v-for="(y, pos) in years" :key="y.index">
                                    <CopyInput v-model="row.price_differences[y.index]" :min="-99" :max="1000" suffix="%" :aria-label="`${rowName(row)} ${label('difference')} ${y.year}`" :invalid="Boolean(err(`segments.local.${i}.price_differences.${y.index}`))" :copy="pos < years.length - 1" @copy="copyRight(row.price_differences, pos)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <template v-else>
                <h4 class="mt2">{{ label('foreignPrice') }}</h4>
                <p class="mute small">{{ label('foreignPriceHint') }}</p>
                <div class="tw nomax">
                    <table class="stm">
                        <thead>
                            <tr>
                                <th />
                                <th class="num">{{ label('firstPrice') }}</th>
                                <th v-for="(y, pos) in years" :key="y.index" class="num" dir="ltr">{{ pos === 0 ? '' : `${y.year} ${label('growth')}` }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in rows" :key="'f' + row.export_market_id">
                                <td>{{ rowName(row) }} <span class="mute small">({{ currencyName(row.currency) }})</span></td>
                                <td>
                                    <input v-model="row.first_foreign_price" class="inp" dir="ltr" type="number" step="any" min="0" :aria-label="`${rowName(row)} ${label('firstPrice')}`" :aria-invalid="Boolean(err(`segments.export.${i}.first_foreign_price`))">
                                </td>
                                <td v-for="(y, pos) in years" :key="y.index">
                                    <CopyInput v-if="pos > 0" v-model="row.foreign_growth_rates[y.index]" suffix="%" :aria-label="`${rowName(row)} ${label('growth')} ${y.year}`" :invalid="Boolean(err(`segments.export.${i}.foreign_growth_rates.${y.index}`))" :copy="pos < years.length - 1" @copy="copyRight(row.foreign_growth_rates, pos)" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <span v-for="(row, i) in rows" :key="'e' + i" class="dng small block" role="alert">{{ err(`segments.export.${i}.export_market_id`) }}</span>

                <h4 class="mt2">{{ label('fx') }}</h4>
                <p class="mute small">{{ label('fxHint', { currency: currencyName(settings.currency) }) }}</p>
                <div class="tw nomax">
                    <table class="stm">
                        <thead>
                            <tr>
                                <th />
                                <th v-for="y in years" :key="y.index" class="num" dir="ltr">{{ y.year }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in fxCurrencies" :key="c">
                                <td>1 {{ currencyName(c) }} =</td>
                                <td v-for="y in years" :key="y.index" class="num" dir="ltr">{{ show((settings.fx[c] ?? {})[y.index] ?? 0, 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>

            <!-- What results -->
            <h4 class="mt2">{{ label('preview') }}</h4>
            <div class="tw nomax">
                <table class="stm">
                    <thead>
                        <tr>
                            <th />
                            <th v-for="y in years" :key="y.index" class="num" dir="ltr">{{ y.year }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="(row, i) in rows" :key="'p' + i">
                            <tr class="grouprow">
                                <td :colspan="years.length + 1"><b>{{ rowName(row) }}</b></td>
                            </tr>
                            <tr>
                                <td class="indent">{{ label('quantity') }}</td>
                                <td v-for="(y, pos) in years" :key="y.index" class="num" dir="ltr">{{ show(lines[pos][i].quantity) }}</td>
                            </tr>
                            <tr v-if="tab === 'export'">
                                <td class="indent">{{ label('foreignPriceIn', { currency: currencyName(row.currency) }) }}</td>
                                <td v-for="(y, pos) in years" :key="y.index" class="num" dir="ltr">{{ show(lines[pos][i].foreign, 2) }}</td>
                            </tr>
                            <tr v-if="tab === 'export'">
                                <td class="indent">{{ label('rate') }}</td>
                                <td v-for="(y, pos) in years" :key="y.index" class="num" dir="ltr">{{ show(lines[pos][i].rate, 2) }}</td>
                            </tr>
                            <tr v-if="tab === 'export'">
                                <td class="indent">{{ label('valueIn', { currency: currencyName(row.currency) }) }}</td>
                                <td v-for="(y, pos) in years" :key="y.index" class="num" dir="ltr">{{ show(lines[pos][i].foreignValue, 0) }}</td>
                            </tr>
                            <tr v-if="tab === 'local'">
                                <td class="indent">{{ label('price') }}</td>
                                <td v-for="(y, pos) in years" :key="y.index" class="num" dir="ltr">{{ show(lines[pos][i].price, 2) }}</td>
                            </tr>
                            <tr>
                                <td class="indent">{{ label('valueIn', { currency: currencyName(settings.currency) }) }}</td>
                                <td v-for="(y, pos) in years" :key="y.index" class="num" dir="ltr">{{ show(lines[pos][i].value, 0) }}</td>
                            </tr>
                        </template>
                        <tr class="grouprow">
                            <td><b>{{ label(tab === 'local' ? 'totalLocal' : 'totalExport') }}</b></td>
                            <td v-for="(y, pos) in years" :key="y.index" class="num" dir="ltr"><b>{{ show(yearTotal(pos, 'value'), 0) }}</b></td>
                        </tr>
                        <tr>
                            <td>{{ label(tab === 'local' ? 'averageLocal' : 'averageExport') }}</td>
                            <td v-for="(y, pos) in years" :key="y.index" class="num" dir="ltr">{{ show(average(pos), 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Collection terms -->
            <h4 class="mt2">{{ label('terms') }}</h4>
            <p class="mute small">{{ label('termsHint') }}</p>
            <ul class="termslist">
                <li v-for="(row, i) in rows" :key="'t' + i">
                    <span>{{ rowName(row) }}</span>
                    <span class="badge" :class="row.uses_own_terms ? 'b-amber' : 'b-green'">{{ label(row.uses_own_terms ? 'termsOwn' : 'termsDefault') }}</span>
                    <label class="chk"><input v-model="row.uses_own_terms" type="checkbox" :disabled="readOnly"> {{ label('useOwn') }}</label>
                    <button v-if="row.uses_own_terms" type="button" class="btn btn-line sm" @click="editing = i">{{ label('editTerms') }}</button>
                    <span v-for="n in periods" :key="n" class="dng small" role="alert">{{ err(`segments.${tab}.${i}.collection_policy_value.${n - 1}`) }}</span>
                </li>
            </ul>
        </template>

        <ModalDialog :open="editRow !== null" :title="label('termsTitle', { name: editRow ? rowName(editRow) : '' })" :subtitle="label('termsSubtitle')" wide @close="editing = null">
            <template v-if="editRow">
                <CollectionTerms
                    v-for="(p, i) in editRow.collection_policy_value" :key="i"
                    v-model="editRow.collection_policy_value[i]"
                    :title="`${t(`products.collection.${tab}`)} — ${periodTitle(i)}`" :due-days="dueDays" :disabled="readOnly"
                    :error="err(`segments.${tab}.${editing}.collection_policy_value.${i}`)" :id-prefix="`seg-${tab}-${editing}-${i}`" kind="collect"
                />
            </template>
        </ModalDialog>
    </section>
</template>

<style scoped>
.indent { padding-inline-start: 22px; color: var(--mute, #6b7280); }
.grouprow td { background: var(--hover, rgba(0, 0, 0, 0.03)); }
.termslist { list-style: none; margin: 0; padding: 0; }
.termslist li { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--line); }
.termslist li > span:first-child { min-width: 160px; font-weight: 600; }
.chk { display: inline-flex; align-items: center; gap: 6px; }
.block { display: block; }
</style>
