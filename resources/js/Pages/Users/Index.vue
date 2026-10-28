<!--
  ZAVERO Manufacturing Pro — People of my company ( /users )
  Location: resources/js/Pages/Users/Index.vue
  Company administrator only. Server side: App\Http\Controllers\UserController.
-->
<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/lang/i18n';

defineProps({
    users: { type: Array, required: true },
    limit: { type: Number, required: true },
    active: { type: Number, required: true },
    readOnly: { type: Boolean, default: false },
});

const { t } = useI18n();
</script>

<template>
    <AppLayout :title="t('users.title')" active="users" accent="orange">
        <div class="pagehead">
            <div>
                <h2>{{ t('users.title') }}</h2>
                <p class="lead mb0">{{ t('users.lead', { active, limit }) }}</p>
            </div>
            <div class="actions">
                <Link :href="route('users.create')" class="btn">{{ t('users.add') }}</Link>
            </div>
        </div>

        <div class="tw nomax">
            <table class="stm">
                <thead>
                    <tr>
                        <th>{{ t('users.columns.person') }}</th>
                        <th>{{ t('users.columns.jobTitle') }}</th>
                        <th>{{ t('users.columns.role') }}</th>
                        <th>{{ t('users.columns.status') }}</th>
                        <th>{{ t('users.columns.lastLogin') }}</th>
                        <th>{{ t('users.columns.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="u in users" :key="u.id">
                        <td><b>{{ u.name }}</b><br><span class="mute small" dir="ltr">{{ u.email }}</span></td>
                        <td>{{ u.job_title ?? '—' }}</td>
                        <td>{{ t(`users.roles.${u.role}`) }}</td>
                        <td><span class="badge" :class="u.active ? 'b-green' : 'b-red'">{{ u.active ? t('users.active') : t('users.suspended') }}</span></td>
                        <td dir="ltr">{{ u.last_login ?? '—' }}</td>
                        <td><Link :href="route('users.edit', u.id)" class="btn sm">{{ t('users.edit') }}</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
