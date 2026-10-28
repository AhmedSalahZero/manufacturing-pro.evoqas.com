<!--
  ZAVERO Manufacturing Pro — Public links of a study ( /projects/{id}/links )
  Location: resources/js/Pages/Sharing/Index.vue
  Create a link with a name, copy it, switch it off and on, delete it, and see how many times it was opened.
  A visitor sees the dashboard and the financial statements only (read-only). Server side: App\Http\Controllers\SharingLinkController.
-->
<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    project: { type: Object, required: true },
    links: { type: Array, default: () => [] },
});

const { t } = useI18n();
const form = useForm({ name: '' });
const copied = ref(null);
const removing = ref(null);

const create = () => form.post(route('projects.links.store', props.project.id), { preserveScroll: true, onSuccess: () => form.reset() });
const toggle = (link) => router.put(route('projects.links.toggle', [props.project.id, link.id]), {}, { preserveScroll: true });
const remove = () => {
    const link = removing.value;
    removing.value = null;
    router.delete(route('projects.links.destroy', [props.project.id, link.id]), { preserveScroll: true });
};
const copy = async (link) => {
    try {
        await navigator.clipboard.writeText(link.url);
        copied.value = link.id;
        setTimeout(() => { copied.value = null; }, 2000);
    } catch {
        window.prompt(t('sharing.copyManually'), link.url);
    }
};
</script>

<template>
    <AppLayout :title="t('sharing.title')" active="projects" accent="violet">
        <div class="pagehead">
            <div>
                <h2>{{ t('sharing.title') }}</h2>
                <p class="lead mb0"><Link :href="route('projects.edit', project.id)">← {{ project.name }}</Link></p>
            </div>
        </div>

        <div class="note" role="note">{{ t('sharing.warning') }}</div>

        <section class="card" style="--ca: var(--k1)">
            <h3>{{ t('sharing.newLink') }}</h3>
            <form class="toolbar" @submit.prevent="create">
                <div class="fld" style="flex:1;min-width:220px">
                    <label for="l-name">{{ t('sharing.name') }}</label>
                    <input id="l-name" v-model="form.name" class="inp" type="text" maxlength="120" :placeholder="t('sharing.namePlaceholder')">
                    <span v-if="form.errors.name" class="dng small" role="alert">{{ form.errors.name }}</span>
                </div>
                <button type="submit" class="btn" :disabled="form.processing || !form.name.trim()">{{ t('sharing.create') }}</button>
            </form>
        </section>

        <section class="card" style="--ca: var(--k3)">
            <p v-if="!links.length" class="empty">{{ t('sharing.empty') }}</p>
            <div v-else class="tw nomax">
                <table>
                    <thead>
                        <tr>
                            <th>{{ t('sharing.name') }}</th><th>{{ t('sharing.link') }}</th><th>{{ t('sharing.status') }}</th>
                            <th class="num">{{ t('sharing.visits') }}</th><th>{{ t('sharing.lastVisit') }}</th><th>{{ t('sharing.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="link in links" :key="link.id" :data-link="link.id">
                            <td>{{ link.name }}<div class="mute small">{{ link.created }}</div></td>
                            <td dir="ltr"><code class="small">{{ link.url }}</code></td>
                            <td><span :class="link.closed ? 'dng' : ''">{{ link.closed ? t('sharing.off') : t('sharing.on') }}</span></td>
                            <td class="num">{{ link.visits }}</td>
                            <td dir="ltr">{{ link.last_visit ?? '—' }}</td>
                            <td>
                                <div class="toolbar">
                                    <button type="button" class="btn btn-line sm" data-action="copy" @click="copy(link)">{{ copied === link.id ? t('sharing.copied') : t('sharing.copy') }}</button>
                                    <button type="button" class="btn btn-line sm" data-action="toggle" @click="toggle(link)">{{ link.closed ? t('sharing.switchOn') : t('sharing.switchOff') }}</button>
                                    <button type="button" class="btn btn-line sm" data-action="delete" @click="removing = link">{{ t('sharing.delete') }}</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <ConfirmDialog :open="removing !== null" :title="t('sharing.deleteTitle')" :confirm-label="t('sharing.delete')" danger @confirm="remove" @cancel="removing = null">
            <p>{{ t('sharing.deleteText', { name: removing?.name ?? '' }) }}</p>
        </ConfirmDialog>
    </AppLayout>
</template>
