<!--
  ZAVERO Manufacturing Pro — A study opened from a public sharing link ( /shared/{code} )
  Location: resources/js/Pages/Shared/Show.vue
  Read-only dashboard and financial statements. Nothing typed here is saved; no inputs, people or other studies are sent.
  Server side: App\Http\Controllers\PublicStudyController.
-->
<script setup>
import { ref } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import DashboardView from '@/Components/DashboardView.vue';
import StatementsTable from '@/Components/StatementsTable.vue';
import { useI18n } from '@/lang/i18n';

defineProps({
    project: { type: Object, required: true },
    link: { type: Object, required: true },
    ready: { type: Boolean, default: false },
    comments: { type: Object, default: () => ({}) },
    dashboard: { type: Object, default: null },
    statements: { type: Object, default: null },
});

const { t } = useI18n();
const view = ref('dashboard');
</script>

<template>
    <PublicLayout :title="project.name">
        <div class="pagehead">
            <div>
                <h2>{{ project.name }}</h2>
                <p v-if="project.intro" class="lead mb0">{{ project.intro }}</p>
            </div>
        </div>

        <section v-if="!ready" class="card" style="--ca: var(--k5)">
            <p class="empty">{{ t('shared.notReady') }}</p>
        </section>

        <template v-else>
            <div class="toolbar">
                <span class="seg" role="group">
                    <button type="button" class="btn" :class="{ 'btn-line': view !== 'dashboard' }" :aria-pressed="view === 'dashboard'" data-view="dashboard" @click="view = 'dashboard'">{{ t('dashboard.title') }}</button>
                    <button type="button" class="btn" :class="{ 'btn-line': view !== 'statements' }" :aria-pressed="view === 'statements'" data-view="statements" @click="view = 'statements'">{{ t('statements.title') }}</button>
                </span>
            </div>

            <template v-if="view === 'dashboard'">
                <DashboardView :dashboard="dashboard" :currency="project.currency" />
                <section v-if="comments.one || comments.two" class="card" style="--ca: var(--k5)">
                    <h3>{{ t('dashboard.notes.title') }}</h3>
                    <p v-if="comments.one" style="white-space:pre-line">{{ comments.one }}</p>
                    <p v-if="comments.two" style="white-space:pre-line">{{ comments.two }}</p>
                </section>
            </template>
            <StatementsTable v-else :statements="statements" :show-rules="false" />
        </template>
    </PublicLayout>
</template>
