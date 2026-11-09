<!--
  ZAVERO Manufacturing Pro — A sharing link that cannot be used ( /shared/{code} or /fill/{code} )
  Location: resources/js/Pages/Shared/Closed.vue
  Shown when the owner paused the link ("paused") or its expiry date has passed ("expired"). The server answers 410.
-->
<script setup>
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { useI18n } from '@/lang/i18n';

defineProps({ reason: { type: String, default: 'paused' } });

const { t } = useI18n();
</script>

<template>
    <PublicLayout :title="t(reason === 'expired' ? 'shared.expiredTitle' : 'shared.closedTitle')">
        <section class="card" style="--ca: var(--k4)" :data-reason="reason">
            <h2>{{ t(reason === 'expired' ? 'shared.expiredTitle' : 'shared.closedTitle') }}</h2>
            <p class="empty">{{ t(reason === 'expired' ? 'shared.expiredText' : 'shared.closedText') }}</p>
        </section>
    </PublicLayout>
</template>
