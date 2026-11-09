<!--
  ZAVERO Manufacturing Pro — The last page of a customer ( /fill/{code}/thanks )
  Location: resources/js/Pages/Shared/Thanks.vue
  Shown after the customer has saved his last page. What he typed is already saved in the study; he can go back to the form while the link works.
-->
<script setup>
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { useI18n } from '@/lang/i18n';

defineProps({ project: { type: Object, required: true } });

const { t } = useI18n();
</script>

<template>
    <PublicLayout :title="t('fill.thanksTitle')">
        <section class="card" style="--ca: var(--k3)" data-thanks>
            <h2>{{ t('fill.thanksTitle') }}</h2>
            <p>{{ t('fill.thanksText', { name: project.name }) }}</p>
            <p><Link :href="route('projects.edit', project.id)" class="btn btn-line">{{ t('fill.backToForm') }}</Link></p>
        </section>
    </PublicLayout>
</template>
