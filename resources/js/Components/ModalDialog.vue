<!--
  ZAVERO Manufacturing Pro — ModalDialog (a pop-up window for filling in a table or a list)
  Location: resources/js/Components/ModalDialog.vue
  <ModalDialog :open="show" :title="..." :subtitle="..." wide @close="show = false"> ...inputs... </ModalDialog>
  Used where a block of inputs would make the page too long (hirings by month, customised payment terms).
  What is typed is saved on the page's own form straight away, so "Done" only closes the window.
  Escape, the cross, "Done" or a click outside closes it. It is not a form, so Enter never saves the page by mistake.
-->
<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    wide: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const { t } = useI18n();
const box = ref(null);

function onKey(event) {
    if (event.key === 'Escape') emit('close');
}

watch(() => props.open, async (open) => {
    if (open) {
        document.addEventListener('keydown', onKey);
        await nextTick();
        box.value?.querySelector('input, select, textarea')?.focus();
    } else {
        document.removeEventListener('keydown', onKey);
    }
}, { immediate: true });

onBeforeUnmount(() => document.removeEventListener('keydown', onKey));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="modal-bg" @mousedown.self="emit('close')">
            <div ref="box" class="modal md" :class="{ wide }" role="dialog" aria-modal="true" :aria-label="title">
                <div class="md-head">
                    <div>
                        <h3>{{ title }}</h3>
                        <p v-if="subtitle" class="mute small mb0">{{ subtitle }}</p>
                    </div>
                    <button type="button" class="md-x" :aria-label="t('common.close')" @click="emit('close')">✕</button>
                </div>
                <div class="md-body"><slot /></div>
                <div class="acts">
                    <slot name="footer" />
                    <button type="button" class="btn" @click="emit('close')">{{ t('common.done') }}</button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.md { padding: 0; display: flex; flex-direction: column; border-top: 5px solid var(--sec, var(--blue)); }
.md-head { display: flex; justify-content: space-between; gap: 12px; align-items: flex-start; padding: 18px 22px 12px; background: linear-gradient(180deg, color-mix(in srgb, var(--sec, var(--blue)) 10%, var(--card)), var(--card)); }
.md-head h3 { margin: 0 0 2px; }
.md-x { border: 0; background: transparent; color: var(--mute); font-size: 18px; cursor: pointer; padding: 2px 8px; border-radius: 8px; }
.md-x:hover { background: var(--hover); color: var(--ink); }
.md-body { padding: 4px 22px; overflow: auto; }
.md .acts { padding: 12px 22px 18px; margin-top: 0; align-items: center; }
</style>
