<!--
  ZAVERO Manufacturing Pro — TermsPair (first operation year and following years side by side)
  Location: resources/js/Components/TermsPair.vue
  Holds one or two <CollectionTerms>. The second one (give it class="later") sits next to the first with a vertical
  blue line between them; on a narrow screen they stack, the blue line on top. One box alone (a one-year study) is full width.
  <TermsPair :single="policies.length < 2"> <CollectionTerms .../> <CollectionTerms class="later" .../> </TermsPair>
-->
<script setup>
defineProps({ single: { type: Boolean, default: false } });
</script>

<template>
    <div class="pair" :class="{ single }">
        <slot />
    </div>
</template>

<style scoped>
.pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    margin-bottom: 10px;
}
.pair.single {
    grid-template-columns: 1fr;
}
.pair :deep(.terms) {
    margin-bottom: 0;
}
.pair :deep(.terms.later) {
    border-inline-start: 3px solid var(--blue);
    border-start-start-radius: 0;
    border-end-start-radius: 0;
}
.pair :deep(.terms:not(.later)):not(:only-child) {
    border-start-end-radius: 0;
    border-end-end-radius: 0;
}
@media (max-width: 760px) {
    .pair {
        grid-template-columns: 1fr;
    }
    .pair :deep(.terms.later) {
        border-inline-start: 1px solid var(--line);
        border-top: 3px solid var(--blue);
        border-radius: var(--r-md);
    }
}
</style>
