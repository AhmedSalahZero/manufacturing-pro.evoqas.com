<!--
  ZAVERO Manufacturing Pro — The study form ( /projects/{id}/edit )
  Location: resources/js/Pages/Projects/Edit.vue
  Study info, intro paragraph, general info (dates, duration, tax, returns), the products and the raw materials.
  Saved by App\Http\Controllers\ProjectController@update; the checks are in App\Http\Requests\UpdateProjectRequest
  (the server always decides; the messages under each field come from there).
  The study end date shown here uses the same rule as the server (resources/js/Utils/studyDates.js).
-->
<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import MonthYearInput from '@/Components/MonthYearInput.vue';
import { useI18n } from '@/lang/i18n';
import { addMonths, monthsBetween, parseMonth, studyEnd } from '@/Utils/studyDates';

const props = defineProps({
    project: { type: Object, required: true },
    products: { type: Array, required: true },
    rawMaterials: { type: Array, required: true },
    readOnly: { type: Boolean, default: false },
    sectors: { type: Array, required: true },
    options: { type: Object, required: true },
});

const { t, isAr } = useI18n();

// The next steps: what to fill in (numbered, in order), what to look at, and sharing. Each card says in a line what the page is for.
const stepGroups = computed(() => {
    const p = props.project;
    const to = (name, extra) => route(name, extra ?? p.id);
    const build = props.products.map((product) => ({
        id: `product-${product.id}`, href: route('projects.products.edit', [p.id, product.id]), title: product.name, desc: t('projects.form.stepsProduct'),
    }));
    if (props.rawMaterials.length) build.push({ id: 'raw', href: to('projects.raw-materials.edit'), title: t('projects.form.stepsRaw'), desc: t('projects.form.desc.raw') });
    build.push(
        { id: 'staff', href: to('projects.manpower.edit'), title: t('projects.form.stepsStaff'), desc: t('projects.form.desc.staff') },
        { id: 'expenses', href: to('projects.expenses.edit'), title: t('projects.form.stepsExpenses'), desc: t('projects.form.desc.expenses') },
        { id: 'assets', href: to('projects.assets.edit'), title: t('projects.form.stepsAssets'), desc: t('projects.form.desc.assets') },
    );
    if (!p.new_company) build.push({ id: 'opening', href: to('projects.opening.edit'), title: t('projects.form.stepsOpening'), desc: t('projects.form.desc.opening') });
    build.forEach((step, i) => { step.no = i + 1; });

    return [
        { key: 'build', accent: '--k1', items: build },
        { key: 'results', accent: '--k3', items: [
            { id: 'statements', href: to('projects.statements'), title: t('projects.form.stepsStatements'), desc: t('projects.form.desc.statements') },
            { id: 'dashboard', href: to('projects.dashboard'), title: t('projects.form.stepsDashboard'), desc: t('projects.form.desc.dashboard') },
            { id: 'sensitivity', href: to('projects.sensitivity'), title: t('projects.form.stepsSensitivity'), desc: t('projects.form.desc.sensitivity') },
            { id: 'report', href: to('projects.report'), title: t('projects.form.stepsReport'), desc: t('projects.form.desc.report') },
        ] },
        { key: 'share', accent: '--k2', items: [
            { id: 'links', href: to('projects.links'), title: t('projects.form.stepsLinks'), desc: t('projects.form.desc.links') },
        ] },
    ];
});

const now = new Date();
const thisMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
const startMonth = props.project.start_date ?? thisMonth;
const operationMonth = props.project.operation_start_date ?? startMonth;

const blankProduct = (sellingStart) => ({
    id: null, name: '', measurement_unit: 'ton', selling_start_date: sellingStart, vat_rate: 0, withhold_tax_rate: 0,
});
const blankMaterial = () => ({
    id: null, name: '', rm_inventory_coverage_days: 0, measurement_unit: 'ton', vat_rate: 0, withhold_tax_rate: 0,
});

const form = useForm({
    name: props.project.name ?? '',
    new_company: Boolean(props.project.new_company),
    main_functional_currency: props.project.main_functional_currency,
    intro_paragraph: props.project.intro_paragraph ?? '',
    business_sector_id: props.project.business_sector_id ?? '',
    start_date: startMonth,
    duration: props.project.duration ?? '',
    operation_start_date: operationMonth,
    tax_rate: props.project.tax_rate ?? '',
    return_rate: props.project.return_rate ?? '',
    perpetual_growth_rate: props.project.perpetual_growth_rate ?? '',
    products: props.products.length
        ? props.products.map((p) => ({ ...p, selling_start_date: p.selling_start_date ?? operationMonth }))
        : [blankProduct(operationMonth)],
    rawMaterials: props.rawMaterials.length ? props.rawMaterials.map((m) => ({ ...m })) : [blankMaterial()],
    after: 'stay',
});

// ── Dates ─────────────────────────────────────────────────────────
const years = computed(() => Array.from({ length: props.options.maxYears - props.options.minYears + 1 }, (_, i) => props.options.minYears + i));
const endMonth = computed(() => studyEnd(form.start_date, form.duration));
const endLabel = computed(() => {
    const parts = parseMonth(endMonth.value);

    return parts ? `${t(`projects.months.${parts.month}`)} ${parts.year}` : '—';
});
const returnEnabled = computed(() => Number(form.duration) >= 2);
const perpetualEnabled = computed(() => Number(form.duration) >= 3);

// Every start date is saved as a position counted from the study start (0 = the first month), so moving the
// study start moves the operation start and every product's selling start with it, by the same number of months.
watch(() => form.start_date, (value, before) => {
    const shift = monthsBetween(before, value);

    if (shift === null) {
        form.operation_start_date = value;

        return;
    }
    form.operation_start_date = addMonths(form.operation_start_date, shift) ?? value;
    form.products.forEach((product) => {
        product.selling_start_date = addMonths(product.selling_start_date, shift) ?? product.selling_start_date;
    });
});
watch(() => form.new_company, (isNew) => {
    if (!isNew) form.operation_start_date = form.start_date;
});

// ── Rows (products and raw materials) ─────────────────────────────
const addProduct = () => form.products.push(blankProduct(form.operation_start_date));
const addMaterial = () => form.rawMaterials.push(blankMaterial());

const removing = ref(null);
function askRemove(kind, index) {
    if (form[kind][index].id) {
        removing.value = { kind, index };   // already saved: its data goes with it, so ask first
    } else {
        form[kind].splice(index, 1);
    }
}
function doRemove() {
    form[removing.value.kind].splice(removing.value.index, 1);
    removing.value = null;
}
const removeText = computed(() => (removing.value?.kind === 'products' ? t('projects.form.removeProduct') : t('projects.form.removeRaw')));

// ── Saving ────────────────────────────────────────────────────────
function save(after) {
    form.after = after;
    form.put(route('projects.update', props.project.id), {
        preserveScroll: true,
        onError: async () => {
            await nextTick();
            document.querySelector('[aria-invalid="true"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },
    });
}

const unitLabel = (unit) => t(`projects.units.${unit}`);
const sectorName = (sector) => (isAr.value ? sector.name_ar || sector.name_en : sector.name_en);
const coverageLabel = (days) => (days === 0 ? t('projects.coverageNone') : t('projects.coverageDays', { n: days }));
const invalid = (path) => Boolean(form.errors[path]);
</script>

<template>
    <AppLayout :title="project.name" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ project.name }}</h2>
                <p class="lead mb0">
                    <Link :href="route('projects.index')">← {{ t('projects.form.back') }}</Link>
                </p>
            </div>
        </div>

        <div v-if="form.hasErrors" class="note bad" role="alert">{{ t('projects.form.fixErrors') }}</div>

        <div v-if="readOnly" class="note" role="status">{{ t('projects.form.readOnly', { owner: project.owner ?? '' }) }}</div>

        <form novalidate @submit.prevent="save('stay')">
          <fieldset :disabled="readOnly" style="border:0;margin:0;padding:0;min-width:0">
            <!-- Study info -->
            <section class="card">
                <h3>{{ t('projects.form.studyInfo') }}</h3>
                <div class="grid3 fgrid">
                    <div class="fld">
                        <label for="f-name">{{ t('projects.form.name') }}</label>
                        <input id="f-name" v-model="form.name" class="inp" type="text" maxlength="255" :aria-invalid="invalid('name')">
                        <span v-if="form.errors.name" class="dng small" role="alert">{{ form.errors.name }}</span>
                    </div>
                    <div class="fld">
                        <span id="f-company">{{ t('projects.form.companyType') }}</span>
                        <div role="radiogroup" aria-labelledby="f-company" style="display:flex;gap:18px;align-items:center;height:34px">
                            <label style="display:flex;gap:6px;align-items:center"><input v-model="form.new_company" type="radio" :value="true"> {{ t('projects.form.newCompany') }}</label>
                            <label style="display:flex;gap:6px;align-items:center"><input v-model="form.new_company" type="radio" :value="false"> {{ t('projects.form.existingCompany') }}</label>
                        </div>
                        <span v-if="form.new_company" class="mute small">{{ t('projects.form.newCompanyNote') }}</span>
                    </div>
                    <div class="fld">
                        <label for="f-currency">{{ t('projects.form.currency') }}</label>
                        <select id="f-currency" v-model="form.main_functional_currency" class="sel" :aria-invalid="invalid('main_functional_currency')">
                            <option v-for="c in options.currencies" :key="c" :value="c">{{ t(`projects.currencies.${c}`) }}</option>
                        </select>
                        <span v-if="form.errors.main_functional_currency" class="dng small" role="alert">{{ form.errors.main_functional_currency }}</span>
                    </div>
                </div>
            </section>

            <!-- Intro paragraph -->
            <section class="card">
                <h3>{{ t('projects.form.introTitle') }}</h3>
                <p class="mute small">{{ t('projects.form.introHint') }}</p>
                <textarea
                    v-model="form.intro_paragraph" class="pin" rows="5" style="width:100%;text-align:start"
                    :aria-label="t('projects.form.introTitle')" :aria-invalid="invalid('intro_paragraph')"
                />
                <span v-if="form.errors.intro_paragraph" class="dng small" role="alert">{{ form.errors.intro_paragraph }}</span>
            </section>

            <!-- General info -->
            <section class="card">
                <h3>{{ t('projects.form.general') }}</h3>
                <div class="grid3 fgrid">
                    <div class="fld">
                        <label for="f-start">{{ t('projects.form.startDate') }}</label>
                        <MonthYearInput id="f-start" v-model="form.start_date" :invalid="invalid('start_date')" />
                        <span v-if="form.errors.start_date" class="dng small" role="alert">{{ form.errors.start_date }}</span>
                    </div>
                    <div class="fld">
                        <label for="f-duration">{{ t('projects.form.duration') }}</label>
                        <select id="f-duration" v-model="form.duration" class="sel" dir="ltr" :aria-invalid="invalid('duration')">
                            <option value="" disabled>{{ t('projects.form.select') }}</option>
                            <option v-for="n in years" :key="n" :value="n">{{ n }}</option>
                        </select>
                        <span v-if="form.errors.duration" class="dng small" role="alert">{{ form.errors.duration }}</span>
                    </div>
                    <div class="fld">
                        <label for="f-end">{{ t('projects.form.endDate') }}</label>
                        <input id="f-end" class="inp" type="text" readonly :value="endLabel" aria-describedby="f-end-hint">
                        <span id="f-end-hint" class="mute small">{{ t('projects.form.endDateHint') }}</span>
                    </div>
                    <div class="fld">
                        <label for="f-operation">{{ t('projects.form.operationStart') }}</label>
                        <MonthYearInput id="f-operation" v-model="form.operation_start_date" :disabled="!form.new_company" :invalid="invalid('operation_start_date')" />
                        <span v-if="!form.new_company" class="mute small">{{ t('projects.form.operationStartHint') }}</span>
                        <span v-if="form.errors.operation_start_date" class="dng small" role="alert">{{ form.errors.operation_start_date }}</span>
                    </div>
                    <div class="fld">
                        <label for="f-sector">{{ t('projects.form.sector') }}</label>
                        <select id="f-sector" v-model="form.business_sector_id" class="sel" :aria-invalid="invalid('business_sector_id')">
                            <option value="">{{ t('projects.form.select') }}</option>
                            <option v-for="s in sectors" :key="s.id" :value="s.id">{{ sectorName(s) }}</option>
                        </select>
                        <span v-if="form.errors.business_sector_id" class="dng small" role="alert">{{ form.errors.business_sector_id }}</span>
                    </div>
                    <div class="fld">
                        <label for="f-tax">{{ t('projects.form.taxRate') }}</label>
                        <input id="f-tax" v-model="form.tax_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="invalid('tax_rate')">
                        <span v-if="form.errors.tax_rate" class="dng small" role="alert">{{ form.errors.tax_rate }}</span>
                    </div>
                    <div class="fld">
                        <label for="f-return">{{ t('projects.form.returnRate') }}</label>
                        <input id="f-return" v-model="form.return_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :disabled="!returnEnabled" :aria-invalid="invalid('return_rate')">
                        <span v-if="!returnEnabled" class="mute small">{{ t('projects.form.returnRateHint') }}</span>
                        <span v-if="form.errors.return_rate" class="dng small" role="alert">{{ form.errors.return_rate }}</span>
                    </div>
                    <div class="fld">
                        <label for="f-perpetual">{{ t('projects.form.perpetual') }}</label>
                        <input id="f-perpetual" v-model="form.perpetual_growth_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="10" :disabled="!perpetualEnabled" :aria-invalid="invalid('perpetual_growth_rate')">
                        <span class="mute small">{{ t('projects.form.perpetualHint') }}</span>
                        <span v-if="form.errors.perpetual_growth_rate" class="dng small" role="alert">{{ form.errors.perpetual_growth_rate }}</span>
                    </div>
                </div>
            </section>

            <!-- Products -->
            <section class="card">
                <h3>{{ t('projects.form.productsTitle') }}</h3>
                <p class="mute small">{{ t('projects.form.productsHint') }}</p>
                <span v-if="form.errors.products" class="dng small" role="alert">{{ form.errors.products }}</span>

                <div v-for="(product, i) in form.products" :key="product.id ?? `new-${i}`" class="rep">
                    <div class="fld w2">
                        <label :for="`p-${i}-name`">{{ t('projects.form.productName') }}</label>
                        <input :id="`p-${i}-name`" v-model="product.name" class="inp" type="text" maxlength="255" :aria-invalid="invalid(`products.${i}.name`)">
                        <span v-if="form.errors[`products.${i}.name`]" class="dng small" role="alert">{{ form.errors[`products.${i}.name`] }}</span>
                    </div>
                    <div class="fld">
                        <label :for="`p-${i}-unit`">{{ t('projects.form.unit') }}</label>
                        <select :id="`p-${i}-unit`" v-model="product.measurement_unit" class="sel" :aria-invalid="invalid(`products.${i}.measurement_unit`)">
                            <option v-for="u in options.units" :key="u" :value="u">{{ unitLabel(u) }}</option>
                        </select>
                        <span v-if="form.errors[`products.${i}.measurement_unit`]" class="dng small" role="alert">{{ form.errors[`products.${i}.measurement_unit`] }}</span>
                    </div>
                    <div class="fld w2">
                        <label :for="`p-${i}-sell`">{{ t('projects.form.sellingStart') }}</label>
                        <MonthYearInput :id="`p-${i}-sell`" v-model="product.selling_start_date" :invalid="invalid(`products.${i}.selling_start_date`)" />
                        <span v-if="form.errors[`products.${i}.selling_start_date`]" class="dng small" role="alert">{{ form.errors[`products.${i}.selling_start_date`] }}</span>
                    </div>
                    <div class="fld">
                        <label :for="`p-${i}-vat`">{{ t('projects.form.vat') }}</label>
                        <input :id="`p-${i}-vat`" v-model="product.vat_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="invalid(`products.${i}.vat_rate`)">
                        <span v-if="form.errors[`products.${i}.vat_rate`]" class="dng small" role="alert">{{ form.errors[`products.${i}.vat_rate`] }}</span>
                    </div>
                    <div class="fld">
                        <label :for="`p-${i}-wh`">{{ t('projects.form.withhold') }}</label>
                        <input :id="`p-${i}-wh`" v-model="product.withhold_tax_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="invalid(`products.${i}.withhold_tax_rate`)">
                        <span v-if="form.errors[`products.${i}.withhold_tax_rate`]" class="dng small" role="alert">{{ form.errors[`products.${i}.withhold_tax_rate`] }}</span>
                    </div>
                    <button type="button" class="btn btn-line sm" :disabled="form.products.length === 1" @click="askRemove('products', i)">{{ t('projects.form.remove') }}</button>
                </div>

                <button type="button" class="btn btn-line sm" @click="addProduct">+ {{ t('projects.form.addProduct') }}</button>
            </section>

            <!-- Raw materials -->
            <section class="card">
                <h3>{{ t('projects.form.rawTitle') }}</h3>
                <p class="mute small">{{ t('projects.form.rawHint') }}</p>
                <span v-if="form.errors.rawMaterials" class="dng small" role="alert">{{ form.errors.rawMaterials }}</span>

                <div v-for="(material, i) in form.rawMaterials" :key="material.id ?? `new-${i}`" class="rep">
                    <div class="fld w2">
                        <label :for="`m-${i}-name`">{{ t('projects.form.rawName') }}</label>
                        <input :id="`m-${i}-name`" v-model="material.name" class="inp" type="text" maxlength="255" :aria-invalid="invalid(`rawMaterials.${i}.name`)">
                        <span v-if="form.errors[`rawMaterials.${i}.name`]" class="dng small" role="alert">{{ form.errors[`rawMaterials.${i}.name`] }}</span>
                    </div>
                    <div class="fld">
                        <label :for="`m-${i}-cov`">{{ t('projects.form.coverage') }}</label>
                        <select :id="`m-${i}-cov`" v-model="material.rm_inventory_coverage_days" class="sel" :aria-invalid="invalid(`rawMaterials.${i}.rm_inventory_coverage_days`)">
                            <option v-for="d in options.coverageDays" :key="d" :value="d">{{ coverageLabel(d) }}</option>
                        </select>
                        <span v-if="form.errors[`rawMaterials.${i}.rm_inventory_coverage_days`]" class="dng small" role="alert">{{ form.errors[`rawMaterials.${i}.rm_inventory_coverage_days`] }}</span>
                    </div>
                    <div class="fld">
                        <label :for="`m-${i}-unit`">{{ t('projects.form.unit') }}</label>
                        <select :id="`m-${i}-unit`" v-model="material.measurement_unit" class="sel" :aria-invalid="invalid(`rawMaterials.${i}.measurement_unit`)">
                            <option v-for="u in options.units" :key="u" :value="u">{{ unitLabel(u) }}</option>
                        </select>
                        <span v-if="form.errors[`rawMaterials.${i}.measurement_unit`]" class="dng small" role="alert">{{ form.errors[`rawMaterials.${i}.measurement_unit`] }}</span>
                    </div>
                    <div class="fld">
                        <label :for="`m-${i}-vat`">{{ t('projects.form.vat') }}</label>
                        <input :id="`m-${i}-vat`" v-model="material.vat_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="invalid(`rawMaterials.${i}.vat_rate`)">
                        <span v-if="form.errors[`rawMaterials.${i}.vat_rate`]" class="dng small" role="alert">{{ form.errors[`rawMaterials.${i}.vat_rate`] }}</span>
                    </div>
                    <div class="fld">
                        <label :for="`m-${i}-wh`">{{ t('projects.form.withhold') }}</label>
                        <input :id="`m-${i}-wh`" v-model="material.withhold_tax_rate" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :aria-invalid="invalid(`rawMaterials.${i}.withhold_tax_rate`)">
                        <span v-if="form.errors[`rawMaterials.${i}.withhold_tax_rate`]" class="dng small" role="alert">{{ form.errors[`rawMaterials.${i}.withhold_tax_rate`] }}</span>
                    </div>
                    <button type="button" class="btn btn-line sm" :disabled="form.rawMaterials.length === 1" @click="askRemove('rawMaterials', i)">{{ t('projects.form.remove') }}</button>
                </div>

                <button type="button" class="btn btn-line sm" @click="addMaterial">+ {{ t('projects.form.addRaw') }}</button>
            </section>

          </fieldset>

            <!-- Next steps: three groups of numbered cards (enter the data, see the results, share) -->
            <section class="card stp" style="--ca: var(--k4)" data-next-steps>
                <h3>{{ t('projects.form.stepsTitle') }}</h3>
                <p v-if="!products.length" class="mute small">{{ t('projects.form.stepsSaveFirst') }}</p>
                <template v-else>
                    <div v-for="group in stepGroups" :key="group.key" class="stp-group" :data-step-group="group.key" :style="{ '--sc': `var(${group.accent})` }">
                        <h4>{{ t(`projects.form.steps.${group.key}`) }}<span>{{ t(`projects.form.steps.${group.key}Hint`) }}</span></h4>
                        <div class="stp-grid">
                            <Link v-for="step in group.items" :key="step.id" :href="step.href" class="stp-card" data-step>
                                <span class="stp-no" aria-hidden="true">{{ step.no ?? '›' }}</span>
                                <span class="stp-txt"><b>{{ step.title }}</b><small>{{ step.desc }}</small></span>
                            </Link>
                        </div>
                    </div>
                </template>
            </section>

            <div class="toolbar">
                <button v-if="!readOnly" type="submit" class="btn" :disabled="form.processing">{{ form.processing ? t('projects.form.saving') : t('projects.form.save') }}</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" @click="save('list')">{{ t('projects.form.saveClose') }}</button>
                <Link :href="route('projects.index')" class="btn btn-line">{{ t('common.cancel') }}</Link>
            </div>
        </form>

        <ConfirmDialog
            :open="!!removing" :title="t('projects.form.removeTitle')" :confirm-label="t('projects.form.removeAction')"
            danger @confirm="doRemove" @cancel="removing = null"
        >
            <p>{{ removeText }}</p>
        </ConfirmDialog>
    </AppLayout>
</template>

<style scoped>
.stp-group { margin-top: 18px; }
.stp-group h4 { margin: 0 0 8px; font-size: 14px; color: var(--ink); display: flex; flex-wrap: wrap; gap: 4px 12px; align-items: baseline; }
.stp-group h4 span { font-size: 12.5px; font-weight: 400; color: var(--mute); }
.stp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; }
.stp-card { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 14px; text-decoration: none; color: var(--ink);
    border: 1px solid color-mix(in srgb, var(--sc) 28%, var(--line)); border-inline-start: 5px solid var(--sc);
    background: linear-gradient(135deg, color-mix(in srgb, var(--sc) 11%, var(--card)), var(--card)); transition: transform .12s, box-shadow .12s; }
.stp-card:hover, .stp-card:focus-visible { transform: translateY(-2px); box-shadow: 0 6px 16px color-mix(in srgb, var(--sc) 22%, transparent); }
.stp-no { flex: none; width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; font-weight: 700; font-size: 14px; color: #fff; background: var(--sc); }
.stp-txt { display: flex; flex-direction: column; min-width: 0; gap: 2px; }
.stp-txt b { font-size: 14px; overflow-wrap: anywhere; }
.stp-txt small { font-size: 12px; color: var(--mute); line-height: 1.35; }
.rep {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    align-items: end;
    padding: 12px;
    margin-bottom: 10px;
    border: 1px solid var(--line);
    border-radius: var(--r-md);
}
@media (min-width: 900px) {
    .rep .w2 { grid-column: span 2; }
}
</style>
