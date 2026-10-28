<!--
  ZAVERO Manufacturing Pro — CopyInput (a number cell with a "copy to the following cells" button)
  Location: resources/js/Components/CopyInput.vue
  GENERAL RULE: every number typed into a row of cells (one cell per year, month, ...) uses this component. The three dots
  next to the number copy it into all the cells after it in the same row (the next years, the next months); the parent
  does the copying when it hears "copy".
  <CopyInput v-model="form.max_capacity[y.index]" :aria-label="..." :invalid="..." suffix="%" @copy="copyRight(...)" />
-->
<script setup>
import { useI18n } from '@/lang/i18n';

const model = defineModel();

defineProps({
    ariaLabel: { type: String, default: '' },
    invalid: { type: Boolean, default: false },
    min: { type: [Number, String], default: 0 },
    max: { type: [Number, String], default: undefined },
    suffix: { type: String, default: '' },
    // false for the last cell of a row (nothing after it to copy into)
    copy: { type: Boolean, default: true },
});

defineEmits(['change', 'copy']);

const { t } = useI18n();
</script>

<template>
    <span class="cin">
        <input v-model="model" class="inp" dir="ltr" type="number" step="any" :min="min" :max="max" :aria-label="ariaLabel" :aria-invalid="invalid" @change="$emit('change', $event)">
        <span v-if="suffix" class="cin-suffix" aria-hidden="true">{{ suffix }}</span>
        <button v-if="copy" type="button" class="cin-dots" :title="t('common.copyRight')" :aria-label="t('common.copyRight')" @click="$emit('copy')">⋯</button>
    </span>
</template>

<style scoped>
.cin {
    display: flex;
    align-items: center;
    gap: 4px;
}
.cin .inp {
    min-width: 0;
    flex: 1 1 auto;
}
.cin-suffix {
    color: var(--mute, #6b7280);
    font-size: 0.85em;
}
.cin-dots {
    flex: none;
    padding: 0 6px;
    border: 1px solid var(--line);
    border-radius: var(--r-sm, 6px);
    background: transparent;
    color: var(--mute, #6b7280);
    font-size: 1.1em;
    line-height: 1.4;
    cursor: pointer;
}
.cin-dots:hover,
.cin-dots:focus-visible {
    color: var(--blue);
    border-color: var(--blue);
}
</style>
