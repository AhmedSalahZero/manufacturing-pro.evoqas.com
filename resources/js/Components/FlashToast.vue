<!--
  ZAVERO Manufacturing Pro — FlashToast (the short message after an action)
  Location: resources/js/Components/FlashToast.vue
  Shows the server's flash message ("Saved", or an error) as a pill at the bottom of the
  screen for 4 seconds. Errors are shown in red and stay a little longer.
-->
<script setup>
import { ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const message = ref('');
const kind = ref('success');
let timer;

watch(() => page.props.flash, (flash) => {
    const found = ['error', 'warning', 'success', 'info'].find((k) => flash?.[k]);
    if (!found) return;

    kind.value = found;
    message.value = flash[found];
    clearTimeout(timer);
    timer = setTimeout(() => (message.value = ''), found === 'error' ? 7000 : 4000);
}, { immediate: true, deep: true });
</script>

<template>
    <div class="toast" :class="{ on: !!message }" role="status" :style="kind === 'error' ? 'background:var(--red)' : ''">{{ message }}</div>
</template>
