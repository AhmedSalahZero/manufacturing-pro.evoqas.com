<!--
  ZAVERO Manufacturing Pro — The "Sales channels and export" card of the study form
  Location: resources/js/Components/SalesChannelsCard.vue
  The export on/off switch, the sales channels (local customer types), the foreign currencies with their FX forecast
  per study year, and the export markets (country + currency). It edits the study form it is given (form.has_export_sales,
  form.sales_channels, form.fx_rates, form.export_markets); the server checks everything (UpdateProjectRequest).
-->
<script setup>
import { computed, ref, watch } from 'vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { useI18n } from '@/lang/i18n';
import { canAdd, duplicateNames, foreignCurrencies, studyYears, syncFxRows } from '@/Utils/salesSettings';

const props = defineProps({
    form: { type: Object, required: true },
    options: { type: Object, required: true },
    exportProducts: { type: Array, default: () => [] },
});

const { t } = useI18n();
const form = props.form;

const years = computed(() => studyYears(form.start_date, form.duration));
const choices = computed(() => foreignCurrencies(props.options.currencies, form.main_functional_currency));
const chosen = computed(() => form.fx_rates.map((row) => row.currency));
const channelDuplicates = computed(() => duplicateNames(form.sales_channels.map((c) => c.name)));
const marketDuplicates = computed(() => duplicateNames(form.export_markets.map((m) => m.name)));

// The study currency can never be a foreign one, and every FX row needs a slot for every study year.
watch([() => form.main_functional_currency, years], () => {
    const kept = chosen.value.filter((code) => code !== form.main_functional_currency);
    form.fx_rates = syncFxRows(form.fx_rates, kept, years.value.length);
});

// ── The export switch (R1.1, R1.6) ────────────────────────────────
const askOff = ref(false);
function setExport(value) {
    if (value === form.has_export_sales) return;
    if (!value && props.exportProducts.length) {
        askOff.value = true;   // products still sell abroad: ask first
        return;
    }
    form.has_export_sales = value;
    form.clear_export = false;
}
function confirmOff() {
    form.has_export_sales = false;
    form.clear_export = true;   // the server sets the export of every product to 0 when this is saved
    askOff.value = false;
}

// ── Currencies ────────────────────────────────────────────────────
function toggleCurrency(code) {
    const next = chosen.value.includes(code) ? chosen.value.filter((c) => c !== code) : [...chosen.value, code];
    form.fx_rates = syncFxRows(form.fx_rates, next, years.value.length);
    // A market paid in a currency that was just unticked has no currency any more.
    form.export_markets.forEach((market) => { if (!next.includes(market.currency)) market.currency = ''; });
}

// ── Channels and markets ──────────────────────────────────────────
const addChannel = () => form.sales_channels.push({ id: null, name: '' });
const removeChannel = (i) => form.sales_channels.splice(i, 1);
const addMarket = () => form.export_markets.push({ id: null, name: '', currency: chosen.value[0] ?? '' });
const removeMarket = (i) => form.export_markets.splice(i, 1);

const err = (path) => form.errors[path];
const bad = (path) => Boolean(form.errors[path]);
</script>

<template>
    <section class="card" data-sales-card>
        <h3>{{ t('projects.sales.title') }}</h3>
        <p class="mute small">{{ t('projects.sales.hint') }}</p>

        <!-- Sales channels -->
        <div class="blk">
            <h4>{{ t('projects.sales.channels') }}</h4>
            <p class="mute small">{{ t('projects.sales.channelsHint') }}</p>
            <span v-if="err('sales_channels')" class="dng small" role="alert">{{ err('sales_channels') }}</span>

            <div v-for="(channel, i) in form.sales_channels" :key="channel.id ?? `c-${i}`" class="row">
                <div class="fld">
                    <label :for="`ch-${i}`">{{ t('projects.sales.channelName') }}</label>
                    <input :id="`ch-${i}`" v-model="channel.name" class="inp" type="text" maxlength="100" data-channel-name
                           :aria-invalid="bad(`sales_channels.${i}.name`) || channelDuplicates.includes(i)">
                    <span v-if="err(`sales_channels.${i}.name`)" class="dng small" role="alert">{{ err(`sales_channels.${i}.name`) }}</span>
                    <span v-else-if="channelDuplicates.includes(i)" class="dng small" role="alert">{{ t('projects.sales.duplicate') }}</span>
                </div>
                <button type="button" class="btn btn-line sm" data-channel-remove @click="removeChannel(i)">{{ t('projects.form.remove') }}</button>
            </div>

            <button type="button" class="btn btn-line sm" data-channel-add :disabled="!canAdd(form.sales_channels, options.maxChannels)" @click="addChannel">+ {{ t('projects.sales.addChannel') }}</button>
            <span v-if="!canAdd(form.sales_channels, options.maxChannels)" class="mute small"> {{ t('projects.sales.limit', { n: options.maxChannels }) }}</span>
        </div>

        <!-- The switch -->
        <div class="blk">
            <h4 id="sales-export-label">{{ t('projects.sales.exportQuestion') }}</h4>
            <div role="radiogroup" aria-labelledby="sales-export-label" style="display:flex;gap:18px;align-items:center">
                <label style="display:flex;gap:6px;align-items:center"><input type="radio" name="has-export" data-export-yes :checked="form.has_export_sales === true" @change="setExport(true)"> {{ t('projects.sales.yes') }}</label>
                <label style="display:flex;gap:6px;align-items:center"><input type="radio" name="has-export" data-export-no :checked="form.has_export_sales === false" @click.prevent="setExport(false)"> {{ t('projects.sales.no') }}</label>
            </div>
            <span v-if="err('has_export_sales')" class="dng small" role="alert">{{ err('has_export_sales') }}</span>
        </div>

        <template v-if="form.has_export_sales">
            <!-- Foreign currencies and the FX forecast -->
            <div class="blk" data-fx>
                <h4>{{ t('projects.sales.currencies') }}</h4>
                <p class="mute small">{{ t('projects.sales.currenciesHint', { currency: t(`projects.currencies.${form.main_functional_currency}`) }) }}</p>
                <div class="chips">
                    <label v-for="code in choices" :key="code" class="chip" :class="{ on: chosen.includes(code) }">
                        <input type="checkbox" :checked="chosen.includes(code)" :data-currency="code"
                               :disabled="!chosen.includes(code) && !canAdd(chosen, options.maxCurrencies)" @change="toggleCurrency(code)">
                        {{ t(`projects.currencies.${code}`) }}
                    </label>
                </div>
                <span v-if="err('fx_rates')" class="dng small" role="alert">{{ err('fx_rates') }}</span>

                <div v-if="form.fx_rates.length" class="fxwrap">
                    <p class="mute small">{{ t('projects.sales.fxHint', { currency: t(`projects.currencies.${form.main_functional_currency}`) }) }}</p>
                    <table class="fx">
                        <thead>
                            <tr>
                                <th scope="col">{{ t('projects.sales.currency') }}</th>
                                <th v-for="(year, y) in years" :key="y" scope="col" dir="ltr">{{ year }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in form.fx_rates" :key="row.currency" :data-fx-row="row.currency">
                                <th scope="row">1 {{ t(`projects.currencies.${row.currency}`) }} =</th>
                                <td v-for="(year, y) in years" :key="y">
                                    <input v-model="row.rates[y]" class="inp" dir="ltr" type="number" step="any" min="0" :aria-label="`${row.currency} ${year}`"
                                           :aria-invalid="bad(`fx_rates.${i}.rates.${y}`)">
                                    <span v-if="err(`fx_rates.${i}.rates.${y}`)" class="dng small" role="alert">{{ err(`fx_rates.${i}.rates.${y}`) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Export markets -->
            <div class="blk" data-markets>
                <h4>{{ t('projects.sales.markets') }}</h4>
                <p class="mute small">{{ t('projects.sales.marketsHint') }}</p>
                <span v-if="err('export_markets')" class="dng small" role="alert">{{ err('export_markets') }}</span>

                <div v-for="(market, i) in form.export_markets" :key="market.id ?? `m-${i}`" class="row">
                    <div class="fld">
                        <label :for="`mk-${i}`">{{ t('projects.sales.marketName') }}</label>
                        <input :id="`mk-${i}`" v-model="market.name" class="inp" type="text" maxlength="100" data-market-name
                               :aria-invalid="bad(`export_markets.${i}.name`) || marketDuplicates.includes(i)">
                        <span v-if="err(`export_markets.${i}.name`)" class="dng small" role="alert">{{ err(`export_markets.${i}.name`) }}</span>
                        <span v-else-if="marketDuplicates.includes(i)" class="dng small" role="alert">{{ t('projects.sales.duplicate') }}</span>
                    </div>
                    <div class="fld">
                        <label :for="`mc-${i}`">{{ t('projects.sales.marketCurrency') }}</label>
                        <select :id="`mc-${i}`" v-model="market.currency" class="sel" data-market-currency :aria-invalid="bad(`export_markets.${i}.currency`)">
                            <option value="" disabled>{{ t('projects.form.select') }}</option>
                            <option v-for="code in chosen" :key="code" :value="code">{{ t(`projects.currencies.${code}`) }}</option>
                        </select>
                        <span v-if="err(`export_markets.${i}.currency`)" class="dng small" role="alert">{{ err(`export_markets.${i}.currency`) }}</span>
                    </div>
                    <button type="button" class="btn btn-line sm" data-market-remove @click="removeMarket(i)">{{ t('projects.form.remove') }}</button>
                </div>

                <button type="button" class="btn btn-line sm" data-market-add :disabled="!canAdd(form.export_markets, options.maxMarkets) || !chosen.length" @click="addMarket">+ {{ t('projects.sales.addMarket') }}</button>
                <span v-if="!chosen.length" class="mute small"> {{ t('projects.sales.pickCurrencyFirst') }}</span>
                <span v-else-if="!canAdd(form.export_markets, options.maxMarkets)" class="mute small"> {{ t('projects.sales.limit', { n: options.maxMarkets }) }}</span>
            </div>
        </template>

        <ConfirmDialog
            :open="askOff" :title="t('projects.sales.offTitle')" :confirm-label="t('projects.sales.offAction')"
            danger @confirm="confirmOff" @cancel="askOff = false"
        >
            <p>{{ t('projects.sales.offText', { products: exportProducts.join(', ') }) }}</p>
        </ConfirmDialog>
    </section>
</template>

<style scoped>
.blk { margin-top: 16px; }
.blk h4 { margin: 0 0 6px; font-size: 14px; }
.row { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; align-items: end; padding: 10px 12px; margin-bottom: 8px; border: 1px solid var(--line); border-radius: var(--r-md); }
.chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 6px 0; }
.chip { display: inline-flex; gap: 6px; align-items: center; padding: 6px 12px; border: 1px solid var(--line); border-radius: 999px; cursor: pointer; }
.chip.on { border-color: var(--k1); background: color-mix(in srgb, var(--k1) 10%, var(--card)); }
.fxwrap { overflow-x: auto; margin-top: 10px; }
.fx { border-collapse: collapse; min-width: 100%; }
.fx th, .fx td { padding: 6px 8px; text-align: start; vertical-align: top; }
.fx thead th { font-size: 12.5px; color: var(--mute); }
.fx td .inp { min-width: 84px; }
</style>
