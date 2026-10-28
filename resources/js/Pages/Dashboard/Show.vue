<!--
  ZAVERO Manufacturing Pro — Dashboard of a study ( /projects/{id}/dashboard )
  Location: resources/js/Pages/Dashboard/Show.vue
  KPIs, charts, discounted cash flow and ratios (components/DashboardView.vue) and the two notes under the charts.
  Server side: App\Http\Controllers\DashboardController (numbers from App\Services\DashboardService).
-->
<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import DashboardView from '@/Components/DashboardView.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    project: { type: Object, required: true },
    ready: { type: Boolean, default: false },
    canEdit: { type: Boolean, default: false },
    comments: { type: Object, default: () => ({ one: '', two: '' }) },
    dashboard: { type: Object, default: null },
});

const { t } = useI18n();
const notes = useForm({ one: props.comments.one ?? '', two: props.comments.two ?? '' });
const saveNotes = () => notes.put(route('projects.dashboard.comments', props.project.id), { preserveScroll: true });
</script>

<template>
    <AppLayout :title="t('dashboard.title')" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ t('dashboard.title') }}</h2>
                <p class="lead mb0"><Link :href="route('projects.edit', project.id)">← {{ project.name }}</Link></p>
            </div>
            <div v-if="ready" class="toolbar">
                <Link :href="route('projects.sensitivity', project.id)" class="btn btn-line sm">{{ t('dashboard.toSensitivity') }}</Link>
                <Link :href="route('projects.report', project.id)" class="btn btn-line sm">{{ t('dashboard.toReport') }}</Link>
            </div>
        </div>

        <section v-if="!ready" class="card" style="--ca: var(--k5)">
            <p class="empty">{{ t('dashboard.notReady') }}</p>
            <p><Link :href="route('projects.edit', project.id)" class="btn btn-line sm">{{ t('dashboard.goToStudy') }}</Link></p>
        </section>

        <template v-else>
            <DashboardView :dashboard="dashboard" :currency="project.currency" />

            <section class="card" style="--ca: var(--k5)">
                <h3>{{ t('dashboard.notes.title') }}</h3>
                <form @submit.prevent="saveNotes">
                    <div class="fld">
                        <label for="n-one">{{ t('dashboard.notes.one') }}</label>
                        <textarea id="n-one" v-model="notes.one" class="pin" rows="3" style="width:100%;text-align:start" :readonly="!canEdit" maxlength="5000"></textarea>
                        <span v-if="notes.errors.one" class="dng small" role="alert">{{ notes.errors.one }}</span>
                    </div>
                    <div class="fld">
                        <label for="n-two">{{ t('dashboard.notes.two') }}</label>
                        <textarea id="n-two" v-model="notes.two" class="pin" rows="3" style="width:100%;text-align:start" :readonly="!canEdit" maxlength="5000"></textarea>
                        <span v-if="notes.errors.two" class="dng small" role="alert">{{ notes.errors.two }}</span>
                    </div>
                    <button v-if="canEdit" type="submit" class="btn sm" :disabled="notes.processing">{{ t('dashboard.notes.save') }}</button>
                </form>
            </section>
        </template>
    </AppLayout>
</template>
