<!--
  ZAVERO Manufacturing Pro — Home ( /home )
  Location: resources/js/Pages/Home.vue
  The first screen after signing in: four numbers about my studies, "continue where you left off" (the five studies
  worked on last, each with quick buttons) and quick actions (start a new study, open the last one).
  Server side: App\Http\Controllers\HomeController.
-->
<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/lang/i18n';
import { formatNumber } from '@/Utils/format';

const props = defineProps({
    stats: { type: Object, default: () => ({ studies: 0, shared: 0, edited_week: 0, open_links: 0 }) },
    recent: { type: Array, default: () => [] },
});

const { t } = useI18n();
const page = usePage();
const name = computed(() => page.props.auth.user.name);
const last = computed(() => props.recent[0] ?? null);

const tiles = computed(() => [
    { key: 'studies', accent: '--k1' },
    { key: 'shared', accent: '--k6' },
    { key: 'edited_week', accent: '--k3' },
    { key: 'open_links', accent: '--k2' },
].map((tile) => ({ ...tile, value: formatNumber(props.stats[tile.key] ?? 0, 0) })));

const createForm = useForm({ name: '' });
const createStudy = () => createForm.post(route('projects.store'), { onSuccess: () => createForm.reset() });
</script>

<template>
    <AppLayout :title="t('home.title')" active="home">
        <div class="pagehead">
            <div>
                <h2>{{ t('home.welcome', { name }) }}</h2>
                <p class="lead mb0">{{ t('home.lead') }}</p>
            </div>
            <div class="actions">
                <Link :href="route('projects.index')" class="btn">{{ t('home.openStudies') }}</Link>
            </div>
        </div>

        <div class="tiles" data-home="stats">
            <div v-for="tile in tiles" :key="tile.key" class="tile" :style="{ '--tc': `var(${tile.accent})` }" :data-stat="tile.key">
                <span>{{ t(`home.stats.${tile.key}`) }}</span><b>{{ tile.value }}</b>
            </div>
        </div>

        <div class="home-grid">
            <section class="card" style="--ca: var(--k1)" data-home="recent">
                <h3>{{ t('home.recentTitle') }}</h3>
                <div v-if="recent.length === 0" class="empty">{{ t('home.recentEmpty') }}</div>
                <ul v-else class="recent">
                    <li v-for="study in recent" :key="study.id" class="recent-row">
                        <div class="recent-main">
                            <Link :href="route('projects.edit', study.id)"><b>{{ study.name }}</b></Link>
                            <span class="mute small">
                                {{ t('home.edited', { date: study.updated_at }) }}
                                <template v-if="study.access === 'edit' || study.access === 'view'"> · {{ t(`projects.access.${study.access}`) }}</template>
                                <template v-if="study.completed"> · {{ t('home.completed') }}</template>
                            </span>
                        </div>
                        <div class="recent-btns">
                            <Link :href="route('projects.edit', study.id)" class="btn sm">{{ t('home.btnEdit') }}</Link>
                            <Link :href="route('projects.statements', study.id)" class="btn sm btn-line">{{ t('home.btnStatements') }}</Link>
                            <Link :href="route('projects.dashboard', study.id)" class="btn sm btn-line">{{ t('home.btnDashboard') }}</Link>
                        </div>
                    </li>
                </ul>
            </section>

            <section class="card" style="--ca: var(--k3)" data-home="actions">
                <h3>{{ t('home.quickTitle') }}</h3>
                <form class="quick-new" @submit.prevent="createStudy">
                    <label for="home-new">{{ t('home.newStudy') }}</label>
                    <div class="quick-row">
                        <input id="home-new" v-model="createForm.name" class="inp" type="text" maxlength="150" :placeholder="t('home.newPlaceholder')" required>
                        <button class="btn" type="submit" :disabled="createForm.processing">{{ t('home.create') }}</button>
                    </div>
                    <div v-if="createForm.errors.name" class="note bad">{{ createForm.errors.name }}</div>
                </form>
                <div v-if="last" class="quick-list">
                    <Link :href="route('projects.edit', last.id)" class="btn btn-line" data-quick="last">{{ t('home.openLast', { name: last.name }) }}</Link>
                    <Link :href="route('projects.report', last.id)" class="btn btn-line" data-quick="report">{{ t('home.reportLast') }}</Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>

<style scoped>
.home-grid { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(280px, 1fr); gap: 16px; align-items: start; }
@media (max-width: 900px) { .home-grid { grid-template-columns: minmax(0, 1fr); } }
.recent { list-style: none; margin: 0; padding: 0; }
.recent-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 0; border-top: 1px solid var(--line); }
.recent-row:first-child { border-top: 0; }
.recent-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.recent-btns { display: flex; gap: 6px; flex-wrap: wrap; }
.quick-new { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
.quick-new label { font-weight: 600; font-size: 13px; }
.quick-row { display: flex; gap: 8px; }
.quick-row .inp { flex: 1; min-width: 0; }
.quick-list { display: flex; flex-direction: column; gap: 8px; }
.quick-list .btn { justify-content: center; }
</style>
