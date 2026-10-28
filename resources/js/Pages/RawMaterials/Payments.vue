<!--
  ZAVERO Manufacturing Pro — Raw material payment terms ( /projects/{id}/raw-materials )
  Location: resources/js/Pages/RawMaterials/Payments.vue
  How each raw material is paid to the supplier: a cash % and up to two later payments, for the first operation year
  and (when the study lasts more than a year) for the following years. Saved by
  App\Http\Controllers\RawMaterialPaymentController@update, which recalculates the purchases and supplier balances.
-->
<script setup>
import { computed, nextTick } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import CollectionTerms from '@/Components/CollectionTerms.vue';
import TermsPair from '@/Components/TermsPair.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    project: { type: Object, required: true },
    materials: { type: Array, required: true },
    lastProduct: { type: Number, default: null },
    readOnly: { type: Boolean, default: false },
    options: { type: Object, required: true },
});

const { t } = useI18n();

const form = useForm({
    rawMaterials: Object.fromEntries(props.materials.map((m) => [m.id, { collection_policy_value: m.policy.map((p) => ({ ...p, rate: [...p.rate], due_in_days: [...p.due_in_days] })) }])),
    comment_for_raw_materials: props.project.comment ?? '',
    after: 'stay',
});

const periodTitle = (i) => (i === 0 ? t('rawPayments.firstYear') : t('rawPayments.laterYears'));
const errorOf = (id, i) => form.errors[`rawMaterials.${id}.collection_policy_value.${i}`] ?? '';
const unitLabel = (unit) => t(`projects.units.${unit}`);
const coverage = computed(() => (days) => (days === 0 ? t('projects.coverageNone') : t('projects.coverageDays', { n: days })));

function save(after) {
    form.after = after;
    form.put(route('projects.raw-materials.update', props.project.id), {
        preserveScroll: true,
        onError: async () => {
            await nextTick();
            document.querySelector('[aria-invalid="true"], [role="alert"]')?.scrollIntoView({ block: 'center', behavior: 'smooth' });
        },
    });
}
</script>

<template>
    <AppLayout :title="t('rawPayments.title')" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ t('rawPayments.title') }}</h2>
                <p class="lead mb0">
                    <Link :href="route('projects.edit', project.id)">← {{ project.name }}</Link>
                </p>
            </div>
        </div>

        <p class="mute">{{ t('rawPayments.lead') }}</p>

        <div v-if="form.hasErrors" class="note bad" role="alert">{{ t('products.fixErrors') }}</div>
        <div v-if="readOnly" class="note" role="status">{{ t('products.readOnly') }}</div>

        <form novalidate @submit.prevent="save('stay')">
            <fieldset :disabled="readOnly" style="border:0;margin:0;padding:0;min-width:0">
                <section v-for="m in materials" :key="m.id" class="card">
                    <h3>{{ m.name }}</h3>
                    <p class="mute small">
                        {{ unitLabel(m.measurement_unit) }} · {{ t('rawPayments.vat') }} {{ m.vat_rate }}% · {{ t('rawPayments.withhold') }} {{ m.withhold_rate }}% · {{ t('rawPayments.coverage') }}: {{ coverage(m.coverage_days) }}
                    </p>
                    <TermsPair :single="form.rawMaterials[m.id].collection_policy_value.length < 2">
                        <CollectionTerms
                            v-for="(policy, i) in form.rawMaterials[m.id].collection_policy_value" :key="i"
                            v-model="form.rawMaterials[m.id].collection_policy_value[i]"
                            :title="periodTitle(i)" :due-days="options.dueDays" :error="errorOf(m.id, i)"
                            :id-prefix="`rm-${m.id}-${i}`" kind="pay" :class="{ later: i > 0 }"
                        />
                    </TermsPair>
                </section>

                <p v-if="!materials.length" class="empty">{{ t('rawPayments.none') }}</p>

                <section class="card">
                    <h3>{{ t('products.comment.title') }}</h3>
                    <textarea v-model="form.comment_for_raw_materials" class="pin" rows="4" style="width:100%;text-align:start" :aria-label="t('products.comment.title')" />
                    <span v-if="form.errors.comment_for_raw_materials" class="dng small" role="alert">{{ form.errors.comment_for_raw_materials }}</span>
                </section>
            </fieldset>

            <div class="toolbar">
                <button v-if="!readOnly" type="submit" class="btn" :disabled="form.processing">{{ form.processing ? t('products.actions.saving') : t('products.actions.save') }}</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" @click="save('next')">{{ t('rawPayments.saveNext') }} →</button>
                <button v-if="!readOnly" type="button" class="btn btn-line" :disabled="form.processing" @click="save('list')">{{ t('products.actions.saveClose') }}</button>
                <Link v-if="lastProduct" :href="route('projects.products.edit', [project.id, lastProduct])" class="btn btn-line">← {{ t('rawPayments.lastProduct') }}</Link>
                <Link :href="route('projects.edit', project.id)" class="btn btn-line">{{ t('common.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
