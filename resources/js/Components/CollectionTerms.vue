<!--
  ZAVERO Manufacturing Pro — CollectionTerms (when the money arrives, or is paid)
  Location: resources/js/Components/CollectionTerms.vue
  One policy: a cash % (same month) and up to two later payments, each a % and a number of days.
  The three must add up to 100 %. Used for product collections and for raw material payments.
  <CollectionTerms v-model="policy" :title="..." :due-days="[15, 30, ...]" :error="..." id-prefix="x" />
  (the policy is { cash_payment, rate: [a, b], due_in_days: [d1, d2] }; the person's typing changes it in place)
-->
<script setup>
import { computed } from 'vue';
import { useI18n } from '@/lang/i18n';
import { addsUpTo100 } from '@/Utils/productPlan';

const props = defineProps({
    modelValue: { type: Object, required: true },
    title: { type: String, required: true },
    dueDays: { type: Array, required: true },
    error: { type: String, default: '' },
    idPrefix: { type: String, required: true },
    disabled: { type: Boolean, default: false },
    // 'collect' (sales) or 'pay' (purchases): only changes the words
    kind: { type: String, default: 'collect' },
});

const { t } = useI18n();

const sum = computed(() => Number(props.modelValue.cash_payment || 0) + props.modelValue.rate.reduce((s, r) => s + Number(r || 0), 0));
const ok = computed(() => addsUpTo100(props.modelValue.cash_payment, props.modelValue.rate));
const label = (name) => t(`terms.${props.kind}.${name}`);
</script>

<template>
    <div class="terms">
        <div class="terms-head">
            <b>{{ title }}</b>
            <span class="badge" :class="ok ? 'b-green' : 'b-red'" role="status">{{ t('terms.total', { total: Math.round(sum * 100) / 100 }) }}</span>
        </div>

        <div class="grid3 fgrid">
            <div class="fld">
                <label :for="`${idPrefix}-cash`">{{ label('cash') }}</label>
                <input :id="`${idPrefix}-cash`" v-model="modelValue.cash_payment" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :disabled="disabled" :aria-invalid="!!error">
            </div>
        </div>

        <div v-for="n in 2" :key="n" class="grid3 fgrid">
            <div class="fld">
                <label :for="`${idPrefix}-rate-${n}`">{{ label('later') }} {{ n }} (%)</label>
                <input :id="`${idPrefix}-rate-${n}`" v-model="modelValue.rate[n - 1]" class="inp" dir="ltr" type="number" step="any" min="0" max="100" :disabled="disabled" :aria-invalid="!!error">
            </div>
            <div class="fld">
                <label :for="`${idPrefix}-days-${n}`">{{ label('after') }} {{ n }}</label>
                <select :id="`${idPrefix}-days-${n}`" v-model="modelValue.due_in_days[n - 1]" class="sel" dir="ltr" :disabled="disabled">
                    <option v-for="d in dueDays" :key="d" :value="d">{{ t('terms.days', { n: d }) }}</option>
                </select>
            </div>
        </div>

        <span v-if="error" class="dng small" role="alert">{{ error }}</span>
    </div>
</template>

<style scoped>
.terms {
    padding: 12px;
    margin-bottom: 10px;
    border: 1px solid var(--line);
    border-radius: var(--r-md);
}
.terms-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 8px;
}
</style>
