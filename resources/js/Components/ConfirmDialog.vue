<!--
  ZAVERO Manufacturing Pro — ConfirmDialog (a small "are you sure?" window)
  Location: resources/js/Components/ConfirmDialog.vue
  <ConfirmDialog :open="show" :title="..." :confirm-label="..." danger @confirm="..." @cancel="show = false">text</ConfirmDialog>
  Escape or a click outside cancels. The default slot holds the text (or an input, for the copy window).
  The form inside the slot may use  @submit.prevent  itself; otherwise the confirm button sends "confirm".
-->
<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    confirmLabel: { type: String, required: true },
    danger: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
    cancelLabel: { type: String, default: '' },
});
const emit = defineEmits(['confirm', 'cancel']);

const { t } = useI18n();
const box = ref(null);

function onKey(event) {
    if (event.key === 'Escape') emit('cancel');
}

watch(() => props.open, async (open) => {
    if (open) {
        document.addEventListener('keydown', onKey);
        await nextTick();
        (box.value?.querySelector('input, textarea, select') ?? box.value?.querySelector('button.btn:not(.btn-line)'))?.focus();
    } else {
        document.removeEventListener('keydown', onKey);
    }
}, { immediate: true });

onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="modal-bg" @mousedown.self="emit('cancel')">
            <form ref="box" class="modal" role="dialog" aria-modal="true" :aria-label="title" style="max-width:520px" @submit.prevent="emit('confirm')">
                <h3>{{ title }}</h3>
                <slot />
                <div class="acts">
                    <button type="button" class="btn btn-line" @click="emit('cancel')">{{ cancelLabel || t('common.cancel') }}</button>
                    <button type="submit" class="btn" :class="{ 'btn-red': danger }" :disabled="busy">{{ confirmLabel }}</button>
                </div>
            </form>
        </div>
    </Teleport>
</template>
