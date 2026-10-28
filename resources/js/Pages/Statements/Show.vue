<!--
  ZAVERO Manufacturing Pro — Financial statements of a study ( /projects/{id}/statements )
  Location: resources/js/Pages/Statements/Show.vue
  Income statement, cash flow and balance sheet. Everything is calculated (nothing is typed here): one column per year;
  click a year to open its months. Four coloured tiles on top and a "does the balance sheet balance?" check.
  Server side: App\Http\Controllers\StatementsController (App\Services\StatementsService builds the rows).
-->
<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import StatementsTable from '@/Components/StatementsTable.vue';
import { useI18n } from '@/lang/i18n';

defineProps({
    project: { type: Object, required: true },
    ready: { type: Boolean, default: false },
    statements: { type: Object, default: null },
    taxRate: { type: Number, default: 0 },
});

const { t } = useI18n();
</script>

<template>
    <AppLayout :title="t('statements.title')" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ t('statements.title') }}</h2>
                <p class="lead mb0"><Link :href="route('projects.edit', project.id)">← {{ project.name }}</Link></p>
            </div>
        </div>

        <section v-if="!ready" class="card" style="--ca: var(--k5)">
            <p class="empty">{{ t('statements.notReady') }}</p>
            <p><Link :href="route('projects.edit', project.id)" class="btn btn-line sm">{{ t('statements.goToStudy') }}</Link></p>
        </section>

        <StatementsTable v-else :statements="statements" :tax-rate="taxRate" />

        <div class="toolbar">
            <Link :href="route('projects.edit', project.id)" class="btn btn-line">← {{ t('statements.back') }}</Link>
        </div>
    </AppLayout>
</template>
