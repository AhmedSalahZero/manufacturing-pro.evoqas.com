<!--
  ZAVERO Manufacturing Pro — MonthYearInput (pick a month and a year)
  Location: resources/js/Components/MonthYearInput.vue
  <MonthYearInput v-model="form.start_date" :invalid="!!form.errors.start_date" />
  The value is "YYYY-MM" (or "" while nothing is chosen). Two drop-downs, so it looks and works the same
  on every phone, tablet and computer, in English and Arabic, always with the digits 0-9.
-->
<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from '@/lang/i18n';
import { formatMonth, parseMonth, yearOptions } from '@/Utils/studyDates';

const props = defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, default: undefined },
    disabled: { type: Boolean, default: false },
    invalid: { type: Boolean, default: false },
    minYear: { type: Number, default: undefined },
    maxYear: { type: Number, default: undefined },
});
const emit = defineEmits(['update:modelValue']);

const { t } = useI18n();
const month = ref(parseMonth(props.modelValue)?.month ?? '');
const year = ref(parseMonth(props.modelValue)?.year ?? '');

watch(() => props.modelValue, (value) => {
    const parts = parseMonth(value);
    month.value = parts?.month ?? '';
    year.value = parts?.year ?? '';
});

const years = computed(() => yearOptions(props.modelValue, props.minYear, props.maxYear));

function change() {
    if (month.value !== '' && year.value !== '') {
        emit('update:modelValue', formatMonth(year.value, month.value));
    }
}
</script>

<template>
    <div class="myi" :class="{ bad: invalid }">
        <select :id="id" v-model="month" class="sel" :disabled="disabled" :aria-label="t('projects.month')" :aria-invalid="invalid" @change="change">
            <option value="" disabled>{{ t('projects.month') }}</option>
            <option v-for="m in 12" :key="m" :value="m">{{ t(`projects.months.${m}`) }}</option>
        </select>
        <select v-model="year" class="sel" dir="ltr" :disabled="disabled" :aria-label="t('projects.year')" :aria-invalid="invalid" @change="change">
            <option value="" disabled>{{ t('projects.year') }}</option>
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
    </div>
</template>

<style scoped>
.myi { display: flex; gap: 8px; }
.myi .sel { flex: 1 1 0; min-width: 0; }
.myi.bad .sel { border-color: var(--red); }
</style>
