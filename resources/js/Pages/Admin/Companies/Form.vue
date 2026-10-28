<!--
  ZAVERO Manufacturing Pro — Company form ( /admin/companies/create and /admin/companies/{id}/edit )
  Location: resources/js/Pages/Admin/Companies/Form.vue
  New company (with its first administrator) or the details of an existing one, and the people of that company.
  Server side: App\Http\Controllers\Admin\CompanyController.
-->
<script setup>
import { computed, reactive } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    company: { type: Object, default: null },
    users: { type: Array, default: () => [] },
    statuses: { type: Array, required: true },
    defaults: { type: Object, default: null },
});

const { t } = useI18n();
const isNew = computed(() => !props.company);

const form = useForm({
    name_en: props.company?.name_en ?? '',
    name_ar: props.company?.name_ar ?? '',
    status: props.company?.status ?? 'trial',
    users_limit: props.company?.users_limit ?? props.defaults?.users_limit ?? 5,
    subscription_starts_at: props.company?.subscription_starts_at ?? props.defaults?.subscription_starts_at ?? '',
    subscription_ends_at: props.company?.subscription_ends_at ?? props.defaults?.subscription_ends_at ?? '',
    contact_phone: props.company?.contact_phone ?? '',
    contact_email: props.company?.contact_email ?? '',
    notes: props.company?.notes ?? '',
    admin_name: '',
    admin_email: '',
    admin_password: '',
});

const save = () => {
    if (isNew.value) {
        form.post(route('admin.companies.store'));
    } else {
        form.transform((data) => ({
            name_en: data.name_en, name_ar: data.name_ar, status: data.status, users_limit: data.users_limit,
            subscription_starts_at: data.subscription_starts_at || null, subscription_ends_at: data.subscription_ends_at || null,
            contact_phone: data.contact_phone, contact_email: data.contact_email, notes: data.notes,
        })).put(route('admin.companies.update', props.company.id));
    }
};

// A new password for one of the company's people (used when e-mail is not available).
const passwords = reactive({});
const errors = reactive({});
const setPassword = (user) => {
    const f = useForm({ password: passwords[user.id] ?? '' });
    f.put(route('admin.companies.password', [props.company.id, user.id]), {
        preserveScroll: true,
        onSuccess: () => { passwords[user.id] = ''; errors[user.id] = null; },
        onError: (e) => { errors[user.id] = e.password; },
    });
};
</script>

<template>
    <AppLayout :title="isNew ? t('companies.newTitle') : t('companies.editTitle')" active="companies" accent="green">
        <div class="pagehead">
            <div>
                <h2>{{ isNew ? t('companies.newTitle') : (company.name_en) }}</h2>
                <p class="lead mb0"><Link :href="route('admin.companies.index')">{{ t('companies.back') }}</Link></p>
            </div>
        </div>

        <div v-if="company?.read_only" class="note" role="status">{{ t('companies.expiredNote') }}</div>
        <div v-if="form.hasErrors" class="note bad" role="alert">{{ t('companies.fixErrors') }}</div>

        <form novalidate @submit.prevent="save">
            <section class="card">
                <h3>{{ t('companies.sections.company') }}</h3>
                <div class="grid3 fgrid">
                    <div class="fld">
                        <label for="c-en">{{ t('companies.fields.name_en') }}</label>
                        <input id="c-en" v-model="form.name_en" class="inp" type="text" maxlength="150" :aria-invalid="!!form.errors.name_en">
                        <span v-if="form.errors.name_en" class="dng small" role="alert">{{ form.errors.name_en }}</span>
                    </div>
                    <div class="fld">
                        <label for="c-ar">{{ t('companies.fields.name_ar') }}</label>
                        <input id="c-ar" v-model="form.name_ar" class="inp" type="text" maxlength="150" dir="rtl" :aria-invalid="!!form.errors.name_ar">
                        <span v-if="form.errors.name_ar" class="dng small" role="alert">{{ form.errors.name_ar }}</span>
                    </div>
                    <div class="fld">
                        <label for="c-status">{{ t('companies.fields.status') }}</label>
                        <select id="c-status" v-model="form.status" class="sel">
                            <option v-for="s in statuses" :key="s" :value="s">{{ t(`companies.status.${s}`) }}</option>
                        </select>
                        <span class="mute small">{{ t('companies.statusHelp') }}</span>
                        <span v-if="form.errors.status" class="dng small" role="alert">{{ form.errors.status }}</span>
                    </div>
                    <div class="fld">
                        <label for="c-limit">{{ t('companies.fields.users_limit') }}</label>
                        <input id="c-limit" v-model.number="form.users_limit" class="inp" dir="ltr" type="number" min="1" max="1000" :aria-invalid="!!form.errors.users_limit">
                        <span class="mute small">{{ t('companies.limitHelp') }}</span>
                        <span v-if="form.errors.users_limit" class="dng small" role="alert">{{ form.errors.users_limit }}</span>
                    </div>
                    <div class="fld">
                        <label for="c-from">{{ t('companies.fields.subscription_starts_at') }}</label>
                        <input id="c-from" v-model="form.subscription_starts_at" class="inp" dir="ltr" type="date" :aria-invalid="!!form.errors.subscription_starts_at">
                        <span v-if="form.errors.subscription_starts_at" class="dng small" role="alert">{{ form.errors.subscription_starts_at }}</span>
                    </div>
                    <div class="fld">
                        <label for="c-to">{{ t('companies.fields.subscription_ends_at') }}</label>
                        <input id="c-to" v-model="form.subscription_ends_at" class="inp" dir="ltr" type="date" :aria-invalid="!!form.errors.subscription_ends_at">
                        <span class="mute small">{{ t('companies.endsHelp') }}</span>
                        <span v-if="form.errors.subscription_ends_at" class="dng small" role="alert">{{ form.errors.subscription_ends_at }}</span>
                    </div>
                    <div class="fld">
                        <label for="c-phone">{{ t('companies.fields.contact_phone') }}</label>
                        <input id="c-phone" v-model="form.contact_phone" class="inp" dir="ltr" type="text" maxlength="30">
                        <span v-if="form.errors.contact_phone" class="dng small" role="alert">{{ form.errors.contact_phone }}</span>
                    </div>
                    <div class="fld">
                        <label for="c-mail">{{ t('companies.fields.contact_email') }}</label>
                        <input id="c-mail" v-model="form.contact_email" class="inp" dir="ltr" type="email" maxlength="150">
                        <span v-if="form.errors.contact_email" class="dng small" role="alert">{{ form.errors.contact_email }}</span>
                    </div>
                </div>
                <div class="fld" style="margin-top:12px">
                    <label for="c-notes">{{ t('companies.fields.notes') }}</label>
                    <textarea id="c-notes" v-model="form.notes" class="pin" rows="3" style="width:100%;text-align:start" />
                </div>
            </section>

            <section v-if="isNew" class="card">
                <h3>{{ t('companies.sections.admin') }}</h3>
                <p class="mute small">{{ t('companies.adminHelp') }}</p>
                <div class="grid3 fgrid">
                    <div class="fld">
                        <label for="a-name">{{ t('companies.fields.admin_name') }}</label>
                        <input id="a-name" v-model="form.admin_name" class="inp" type="text" maxlength="120" :aria-invalid="!!form.errors.admin_name">
                        <span v-if="form.errors.admin_name" class="dng small" role="alert">{{ form.errors.admin_name }}</span>
                    </div>
                    <div class="fld">
                        <label for="a-mail">{{ t('companies.fields.admin_email') }}</label>
                        <input id="a-mail" v-model="form.admin_email" class="inp" dir="ltr" type="email" maxlength="190" autocomplete="off" :aria-invalid="!!form.errors.admin_email">
                        <span v-if="form.errors.admin_email" class="dng small" role="alert">{{ form.errors.admin_email }}</span>
                    </div>
                    <div class="fld">
                        <label for="a-pass">{{ t('companies.fields.admin_password') }}</label>
                        <input id="a-pass" v-model="form.admin_password" class="inp" dir="ltr" type="text" autocomplete="off" :aria-invalid="!!form.errors.admin_password">
                        <span class="mute small">{{ t('companies.passwordHelp') }}</span>
                        <span v-if="form.errors.admin_password" class="dng small" role="alert">{{ form.errors.admin_password }}</span>
                    </div>
                </div>
            </section>

            <div class="toolbar">
                <button type="submit" class="btn" :disabled="form.processing">{{ form.processing ? t('companies.saving') : (isNew ? t('companies.create') : t('companies.save')) }}</button>
                <Link :href="route('admin.companies.index')" class="btn btn-line">{{ t('common.cancel') }}</Link>
            </div>
        </form>

        <section v-if="!isNew" class="card">
            <h3>{{ t('companies.sections.people') }}</h3>
            <p class="mute small">{{ t('companies.peopleHelp') }}</p>
            <div class="tw nomax">
                <table class="stm">
                    <thead>
                        <tr>
                            <th>{{ t('companies.columns.person') }}</th>
                            <th>{{ t('companies.columns.role') }}</th>
                            <th>{{ t('companies.columns.status') }}</th>
                            <th>{{ t('companies.columns.lastLogin') }}</th>
                            <th>{{ t('companies.columns.newPassword') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="u in users" :key="u.id">
                            <td><b>{{ u.name }}</b><br><span class="mute small" dir="ltr">{{ u.email }}</span></td>
                            <td>{{ t(`users.roles.${u.role}`) }}</td>
                            <td><span class="badge" :class="u.active ? 'b-green' : 'b-red'">{{ u.active ? t('users.active') : t('users.suspended') }}</span></td>
                            <td dir="ltr">{{ u.last_login ?? '—' }}</td>
                            <td>
                                <div style="display:flex;gap:6px;align-items:flex-start">
                                    <input v-model="passwords[u.id]" class="inp" dir="ltr" type="text" autocomplete="off" :aria-label="t('companies.columns.newPassword')" style="min-width:150px">
                                    <button type="button" class="btn btn-line sm" :disabled="!passwords[u.id]" @click="setPassword(u)">{{ t('companies.setPassword') }}</button>
                                </div>
                                <span v-if="errors[u.id]" class="dng small" role="alert">{{ errors[u.id] }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppLayout>
</template>
