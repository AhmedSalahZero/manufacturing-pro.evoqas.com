<!--
  ZAVERO Manufacturing Pro — A sharing link that was switched off ( /shared/{code} )
  Location: resources/js/Pages/Shared/Closed.vue
-->
<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { useI18n } from '@/lang/i18n';

const { t } = useI18n();
</script>

<template>
    <PublicLayout :title="t('shared.closedTitle')">
        <section class="card" style="--ca: var(--k4)">
            <h2>{{ t('shared.closedTitle') }}</h2>
            <p class="empty">{{ t('shared.closedText') }}</p>
        </section>
    </PublicLayout>
</template>
