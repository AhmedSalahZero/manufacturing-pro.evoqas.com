<!--
  ZAVERO Manufacturing Pro — Studies ( /projects )
  Location: resources/js/Pages/Projects/Index.vue
  My list of studies (projects): create one from its name, open it, copy it, delete it.
  Server side: App\Http\Controllers\ProjectController (index, store, copy, destroy).
-->
<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import ModalDialog from '@/Components/ModalDialog.vue';
import { useI18n } from '@/lang/i18n';
import { parseMonth } from '@/Utils/studyDates';

const props = defineProps({
    studies: { type: Array, required: true },
    colleagues: { type: Array, default: () => [] },
});

const { t } = useI18n();

const createForm = useForm({ name: '' });
const createStudy = () => createForm.post(route('projects.store'), { onSuccess: () => createForm.reset() });

// Copy window
const copying = ref(null);
const copyForm = useForm({ name: '' });
const askCopy = (study) => {
    copying.value = study;
    copyForm.name = `${study.name} ${t('projects.copySuffix')}`;
    copyForm.clearErrors();
};
const doCopy = () => copyForm.post(route('projects.copy', copying.value.id), {
    preserveScroll: true,
    onSuccess: () => (copying.value = null),
});

// Delete window
const deleting = ref(null);
const deleteForm = useForm({});
const doDelete = () => deleteForm.delete(route('projects.destroy', deleting.value.id), {
    preserveScroll: true,
    onSuccess: () => (deleting.value = null),
});

// Share window: for each colleague, no access / view / view and edit.
const sharing = ref(null);
const choices = ref({});
const shareForm = useForm({ shares: [] });
const askShare = (study) => {
    sharing.value = study;
    choices.value = Object.fromEntries(props.colleagues.map((c) => {
        const row = study.shares.find((s) => s.user_id === c.id);

        return [c.id, row ? (row.can_edit ? 'edit' : 'view') : 'none'];
    }));
    shareForm.clearErrors();
};
const doShare = () => shareForm.transform(() => ({
    shares: Object.entries(choices.value).filter(([, v]) => v !== 'none').map(([id, v]) => ({ user_id: Number(id), can_edit: v === 'edit' })),
})).put(route('projects.shares', sharing.value.id), {
    preserveScroll: true,
    onSuccess: () => (sharing.value = null),
});

// Share template window: the "fill" link of a study. The customer opens it without signing in and fills in the input pages straight into
// the study (no statements, no dashboard). Here: create it, copy it, pause / resume it, set the day it expires, delete it.
const templateId = ref(null);
const tplStudy = computed(() => props.studies.find((s) => s.id === templateId.value) ?? null);
const tpl = computed(() => tplStudy.value?.fill_link ?? null);
const newExpiry = ref('');
const expiryInput = ref('');
const copied = ref(false);
const today = new Date().toISOString().slice(0, 10);
const askTemplate = (study) => {
    templateId.value = study.id;
    newExpiry.value = '';
    copied.value = false;
};
watch(tpl, (link) => { expiryInput.value = link?.expires_at ?? ''; }, { immediate: true });
const keep = { preserveScroll: true };
const createTemplate = () => router.post(route('projects.links.store', templateId.value), { type: 'fill', name: '', expires_at: newExpiry.value || null }, keep);
const toggleTemplate = () => router.put(route('projects.links.toggle', [templateId.value, tpl.value.id]), {}, keep);
const saveExpiry = () => router.put(route('projects.links.expiry', [templateId.value, tpl.value.id]), { expires_at: expiryInput.value || null }, keep);
const clearExpiry = () => { expiryInput.value = ''; saveExpiry(); };
const deleteTemplate = () => {
    if (window.confirm(t('projects.template.deleteText'))) router.delete(route('projects.links.destroy', [templateId.value, tpl.value.id]), keep);
};
const copyTemplate = async () => {
    try {
        await navigator.clipboard.writeText(tpl.value.url);
        copied.value = true;
        setTimeout(() => { copied.value = false; }, 2000);
    } catch {
        window.prompt(t('sharing.copyManually'), tpl.value.url);
    }
};

function monthLabel(value) {
    const parts = parseMonth(value);

    return parts ? `${t(`projects.months.${parts.month}`)} ${parts.year}` : '—';
}

const period = (study) => (study.start && study.end ? `${monthLabel(study.start)} – ${monthLabel(study.end)}` : '—');
</script>

<template>
    <AppLayout :title="t('projects.title')" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ t('projects.title') }}</h2>
                <p class="lead mb0">{{ t('projects.lead') }}</p>
            </div>
        </div>

        <form class="card" @submit.prevent="createStudy">
            <h3>{{ t('projects.newTitle') }}</h3>
            <div class="toolbar" style="border-bottom:0;padding-bottom:0;margin-bottom:0">
                <div class="fld grow" style="min-width:240px">
                    <label for="new-study">{{ t('projects.newLabel') }}</label>
                    <input
                        id="new-study" v-model="createForm.name" class="inp" type="text" maxlength="255" required
                        :placeholder="t('projects.newPlaceholder')" :aria-invalid="!!createForm.errors.name"
                    >
                </div>
                <button class="btn" type="submit" :disabled="createForm.processing || !createForm.name.trim()" style="align-self:flex-end">{{ t('projects.add') }}</button>
            </div>
            <p v-if="createForm.errors.name" class="dng small" role="alert">{{ createForm.errors.name }}</p>
        </form>

        <div v-if="studies.length" class="tw nomax">
            <table class="stm">
                <thead>
                    <tr>
                        <th>{{ t('projects.columns.name') }}</th>
                        <th>{{ t('projects.columns.status') }}</th>
                        <th>{{ t('projects.columns.owner') }}</th>
                        <th>{{ t('projects.columns.company') }}</th>
                        <th>{{ t('projects.columns.period') }}</th>
                        <th class="num">{{ t('projects.columns.duration') }}</th>
                        <th>{{ t('projects.columns.updated') }}</th>
                        <th>{{ t('projects.columns.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="study in studies" :key="study.id">
                        <td><Link :href="route('projects.edit', study.id)"><b>{{ study.name }}</b></Link></td>
                        <td>
                            <span class="badge" :class="study.completed ? 'b-green' : 'b-amber'">
                                {{ study.completed ? t('projects.status.complete') : t('projects.status.draft') }}
                            </span>
                        </td>
                        <td>
                            {{ study.owner ?? '—' }}
                            <span v-if="study.access !== 'owner'" class="badge b-amber">{{ t(`projects.access.${study.access}`) }}</span>
                            <span v-else-if="study.shared_with" class="badge b-green">{{ t('projects.sharedWith', { count: study.shared_with }) }}</span>
                        </td>
                        <td>{{ study.new_company ? t('projects.company.new') : t('projects.company.existing') }}</td>
                        <td>{{ period(study) }}</td>
                        <td class="num">{{ study.duration ?? '—' }}</td>
                        <td dir="ltr" class="num">{{ study.updated_at }}</td>
                        <td>
                            <div class="actgrid" data-actions>
                                <Link :href="route('projects.edit', study.id)" class="btn sm">{{ t('projects.open') }}</Link>
                                <a v-if="study.can_excel" :href="route('projects.excel', study.id)" class="btn btn-line sm" :title="t('projects.excelHint')" download>{{ t('projects.excel') }}</a>
                                <button type="button" class="btn btn-line sm" @click="askCopy(study)">{{ t('projects.copy') }}</button>
                                <button v-if="study.can_share" type="button" class="btn btn-line sm" @click="askShare(study)">{{ t('projects.share.button') }}</button>
                                <button v-if="study.can_share" type="button" class="btn btn-line sm" data-action="template" @click="askTemplate(study)">{{ t('projects.template.button') }}</button>
                                <button v-if="study.can_delete" type="button" class="btn btn-line sm" @click="deleting = study">{{ t('projects.delete') }}</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="empty">
            <h3>{{ t('projects.emptyTitle') }}</h3>
            <p class="mute mb0">{{ t('projects.emptyText') }}</p>
        </div>

        <ConfirmDialog
            :open="!!copying" :title="t('projects.copyTitle')" :confirm-label="t('projects.copyAction')"
            :busy="copyForm.processing" @confirm="doCopy" @cancel="copying = null"
        >
            <div class="fld">
                <label for="copy-name">{{ t('projects.copyName') }}</label>
                <input id="copy-name" v-model="copyForm.name" class="inp" type="text" maxlength="255" required>
                <span v-if="copyForm.errors.name" class="dng small" role="alert">{{ copyForm.errors.name }}</span>
            </div>
            <p class="mute small" style="margin-top:10px">{{ t('projects.copyHelp') }}</p>
        </ConfirmDialog>

        <ConfirmDialog
            :open="!!sharing" :title="t('projects.share.title', { name: sharing?.name ?? '' })" :confirm-label="t('projects.share.save')"
            :busy="shareForm.processing" @confirm="doShare" @cancel="sharing = null"
        >
            <p v-if="!colleagues.length" class="mute">{{ t('projects.share.noColleagues') }}</p>
            <template v-else>
                <p class="mute small">{{ t('projects.share.help') }}</p>
                <div v-for="c in colleagues" :key="c.id" style="display:flex;gap:10px;align-items:center;justify-content:space-between;margin:8px 0">
                    <label :for="`share-${c.id}`"><b>{{ c.name }}</b><br><span class="mute small" dir="ltr">{{ c.email }}</span></label>
                    <select :id="`share-${c.id}`" v-model="choices[c.id]" class="sel" style="max-width:200px">
                        <option value="none">{{ t('projects.share.none') }}</option>
                        <option value="view">{{ t('projects.share.view') }}</option>
                        <option value="edit">{{ t('projects.share.edit') }}</option>
                    </select>
                </div>
                <p v-if="shareForm.hasErrors" class="dng small" role="alert">{{ Object.values(shareForm.errors)[0] }}</p>
            </template>
        </ConfirmDialog>

        <ModalDialog :open="!!tplStudy" :title="t('projects.template.title', { name: tplStudy?.name ?? '' })" :subtitle="t('projects.template.hint')" @close="templateId = null">
            <div v-if="tplStudy" data-template="window">
                <template v-if="!tpl">
                    <p class="mute">{{ t('projects.template.none') }}</p>
                    <div class="fld">
                        <label for="tpl-new-exp">{{ t('projects.template.expires') }}</label>
                        <input id="tpl-new-exp" v-model="newExpiry" class="inp" type="date" :min="today" dir="ltr" style="max-width:220px">
                    </div>
                    <p><button type="button" class="btn" data-action="tpl-create" @click="createTemplate">{{ t('projects.template.create') }}</button></p>
                </template>
                <template v-else>
                    <div class="fld">
                        <label for="tpl-url">{{ t('projects.template.link') }}</label>
                        <input id="tpl-url" class="inp" type="text" readonly dir="ltr" :value="tpl.url" @focus="$event.target.select()">
                    </div>
                    <p data-tpl-status>
                        <span class="badge" :class="tpl.status === 'active' ? 'b-green' : 'b-amber'">{{ t(`projects.template.status.${tpl.status}`) }}</span>
                        <span class="mute small"> · {{ t('sharing.visits') }}: {{ tpl.visits }}</span>
                    </p>
                    <div class="actgrid" style="margin:10px 0">
                        <button type="button" class="btn sm" data-action="tpl-copy" @click="copyTemplate">{{ copied ? t('sharing.copied') : t('projects.template.copy') }}</button>
                        <button type="button" class="btn btn-line sm" data-action="tpl-toggle" @click="toggleTemplate">{{ tpl.closed ? t('projects.template.resume') : t('projects.template.pause') }}</button>
                        <button type="button" class="btn btn-line sm" data-action="tpl-delete" @click="deleteTemplate">{{ t('projects.template.delete') }}</button>
                    </div>
                    <div class="fld">
                        <label for="tpl-exp">{{ t('projects.template.expires') }}</label>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                            <input id="tpl-exp" v-model="expiryInput" class="inp" type="date" dir="ltr" style="max-width:220px">
                            <button type="button" class="btn btn-line sm" data-action="tpl-expiry" @click="saveExpiry">{{ t('projects.template.saveDate') }}</button>
                            <button v-if="tpl.expires_at" type="button" class="btn btn-line sm" data-action="tpl-noexpiry" @click="clearExpiry">{{ t('projects.template.noExpiry') }}</button>
                        </div>
                        <span class="mute small">{{ t('projects.template.expiresHelp') }}</span>
                    </div>
                </template>
            </div>
        </ModalDialog>

        <ConfirmDialog
            :open="!!deleting" :title="t('projects.deleteTitle')" :confirm-label="t('projects.deleteAction')"
            danger :busy="deleteForm.processing" @confirm="doDelete" @cancel="deleting = null"
        >
            <p>{{ t('projects.deleteText', { name: deleting?.name ?? '' }) }}</p>
        </ConfirmDialog>
    </AppLayout>
</template>

<style scoped>
/* the action buttons of a study: two rows of three */
.actgrid { display: grid; grid-template-columns: repeat(3, max-content); gap: 6px; justify-content: start; }
</style>
