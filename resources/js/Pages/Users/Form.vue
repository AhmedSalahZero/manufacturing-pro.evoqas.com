<!--
  ZAVERO Manufacturing Pro — Person form ( /users/create and /users/{id}/edit )
  Location: resources/js/Pages/Users/Form.vue
  Add a person to my company, or change one. Server side: App\Http\Controllers\UserController.
-->
<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    person: { type: Object, default: null },
});

const { t } = useI18n();
const isNew = computed(() => !props.person);

const form = useForm({
    name: props.person?.name ?? '',
    email: props.person?.email ?? '',
    password: '',
    job_title: props.person?.job_title ?? '',
    role: props.person?.role ?? 'user',
    is_active: props.person?.active ?? true,
});

const save = () => {
    if (isNew.value) {
        form.post(route('users.store'));
    } else {
        form.transform((data) => ({
            name: data.name, job_title: data.job_title, role: data.role, is_active: data.is_active, password: data.password || null,
        })).put(route('users.update', props.person.id));
    }
};
</script>

<template>
    <AppLayout :title="isNew ? t('users.newTitle') : t('users.editTitle')" active="users" accent="orange">
        <div class="pagehead">
            <div>
                <h2>{{ isNew ? t('users.newTitle') : person.name }}</h2>
                <p class="lead mb0"><Link :href="route('users.index')">{{ t('users.back') }}</Link></p>
            </div>
        </div>

        <div v-if="form.hasErrors" class="note bad" role="alert">{{ t('users.fixErrors') }}</div>

        <form class="card" novalidate @submit.prevent="save">
            <div class="grid3 fgrid">
                <div class="fld">
                    <label for="u-name">{{ t('users.fields.name') }}</label>
                    <input id="u-name" v-model="form.name" class="inp" type="text" maxlength="120" :aria-invalid="!!form.errors.name">
                    <span v-if="form.errors.name" class="dng small" role="alert">{{ form.errors.name }}</span>
                </div>
                <div class="fld">
                    <label for="u-mail">{{ t('users.fields.email') }}</label>
                    <input id="u-mail" v-model="form.email" class="inp" dir="ltr" type="email" maxlength="190" autocomplete="off" :readonly="!isNew" :aria-invalid="!!form.errors.email">
                    <span v-if="!isNew" class="mute small">{{ t('users.emailFixed') }}</span>
                    <span v-if="form.errors.email" class="dng small" role="alert">{{ form.errors.email }}</span>
                </div>
                <div class="fld">
                    <label for="u-job">{{ t('users.fields.job_title') }}</label>
                    <input id="u-job" v-model="form.job_title" class="inp" type="text" maxlength="100">
                    <span v-if="form.errors.job_title" class="dng small" role="alert">{{ form.errors.job_title }}</span>
                </div>
                <div class="fld">
                    <label for="u-role">{{ t('users.fields.role') }}</label>
                    <select id="u-role" v-model="form.role" class="sel" :disabled="person?.is_me">
                        <option value="user">{{ t('users.roles.user') }}</option>
                        <option value="company_admin">{{ t('users.roles.company_admin') }}</option>
                    </select>
                    <span class="mute small">{{ t('users.roleHelp') }}</span>
                    <span v-if="form.errors.role" class="dng small" role="alert">{{ form.errors.role }}</span>
                </div>
                <div class="fld">
                    <label for="u-pass">{{ isNew ? t('users.fields.password') : t('users.fields.newPassword') }}</label>
                    <input id="u-pass" v-model="form.password" class="inp" dir="ltr" type="text" autocomplete="off" :aria-invalid="!!form.errors.password">
                    <span class="mute small">{{ isNew ? t('users.passwordHelpNew') : t('users.passwordHelpEdit') }}</span>
                    <span v-if="form.errors.password" class="dng small" role="alert">{{ form.errors.password }}</span>
                </div>
                <div v-if="!isNew" class="fld">
                    <span id="u-active">{{ t('users.fields.is_active') }}</span>
                    <label style="display:flex;gap:8px;align-items:center;height:34px">
                        <input v-model="form.is_active" type="checkbox" :disabled="person.is_me" aria-labelledby="u-active">
                        {{ t('users.activeLabel') }}
                    </label>
                    <span class="mute small">{{ t('users.activeHelp') }}</span>
                    <span v-if="form.errors.is_active" class="dng small" role="alert">{{ form.errors.is_active }}</span>
                </div>
            </div>

            <div class="toolbar" style="margin-top:14px">
                <button type="submit" class="btn" :disabled="form.processing">{{ form.processing ? t('users.saving') : (isNew ? t('users.create') : t('users.save')) }}</button>
                <Link :href="route('users.index')" class="btn btn-line">{{ t('common.cancel') }}</Link>
            </div>
        </form>
    </AppLayout>
</template>
