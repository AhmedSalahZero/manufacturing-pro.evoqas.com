<!--
  ZAVERO Manufacturing Pro — Companies ( /admin/companies )
  Location: resources/js/Pages/Admin/Companies/Index.vue
  The super admin's list of companies. Server side: App\Http\Controllers\Admin\CompanyController.
-->
<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/lang/i18n';

defineProps({
    companies: { type: Array, required: true },
});

const { t, locale } = useI18n();
const label = (c) => (locale.value === 'ar' ? c.name_ar : c.name_en);
const badge = (status) => ({ active: 'b-green', trial: 'b-amber', suspended: 'b-red' }[status] ?? 'b-amber');
</script>

<template>
    <AppLayout :title="t('companies.title')" active="companies" accent="green">
        <div class="pagehead">
            <div>
                <h2>{{ t('companies.title') }}</h2>
                <p class="lead mb0">{{ t('companies.lead') }}</p>
            </div>
            <div class="actions">
                <Link :href="route('admin.companies.create')" class="btn">{{ t('companies.add') }}</Link>
            </div>
        </div>

        <div v-if="companies.length" class="tw nomax">
            <table class="stm">
                <thead>
                    <tr>
                        <th>{{ t('companies.columns.name') }}</th>
                        <th>{{ t('companies.columns.status') }}</th>
                        <th class="num">{{ t('companies.columns.users') }}</th>
                        <th class="num">{{ t('companies.columns.studies') }}</th>
                        <th>{{ t('companies.columns.ends') }}</th>
                        <th>{{ t('companies.columns.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="c in companies" :key="c.id">
                        <td><Link :href="route('admin.companies.edit', c.id)"><b>{{ label(c) }}</b></Link></td>
                        <td><span class="badge" :class="badge(c.status)">{{ t(`companies.status.${c.status}`) }}</span></td>
                        <td class="num" dir="ltr">{{ c.active_users }} / {{ c.users_limit }}</td>
                        <td class="num">{{ c.studies }}</td>
                        <td dir="ltr">
                            {{ c.ends ?? '—' }}
                            <span v-if="c.read_only" class="badge b-red">{{ t('companies.readOnly') }}</span>
                        </td>
                        <td><Link :href="route('admin.companies.edit', c.id)" class="btn sm">{{ t('companies.open') }}</Link></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-else class="empty">
            <h3>{{ t('companies.emptyTitle') }}</h3>
            <p class="mute mb0">{{ t('companies.emptyText') }}</p>
        </div>
    </AppLayout>
</template>
