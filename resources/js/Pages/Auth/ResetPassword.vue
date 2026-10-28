<!--
  ZAVERO Manufacturing Pro — Choose a new password ( /reset-password/{token} )
  Location: resources/js/Pages/Auth/ResetPassword.vue
  Opened from the e-mailed link (App\Http\Controllers\Auth\NewPasswordController).
-->
<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { useI18n } from '@/lang/i18n';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const { t } = useI18n();
const form = useForm({ token: props.token, email: props.email ?? '', password: '', password_confirmation: '' });
const submit = () => form.post(route('password.store'), { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <AuthLayout :title="t('auth.resetTitle')">
        <h1>{{ t('auth.resetTitle') }}</h1>
        <p class="lg-sub">{{ t('auth.resetText') }}</p>

        <form @submit.prevent="submit">
            <label for="rp-email">{{ t('auth.email') }}</label>
            <input id="rp-email" v-model="form.email" class="inp" type="email" dir="ltr" autocomplete="username" required>

            <label for="rp-password">{{ t('auth.newPassword') }}</label>
            <input id="rp-password" v-model="form.password" class="inp" type="password" dir="ltr" autocomplete="new-password" required autofocus>

            <label for="rp-confirm">{{ t('auth.confirmPassword') }}</label>
            <input id="rp-confirm" v-model="form.password_confirmation" class="inp" type="password" dir="ltr" autocomplete="new-password" required>

            <div class="lg-err" role="alert">{{ form.errors.password || form.errors.email }}</div>
            <button class="btn lg-btn" type="submit" :disabled="form.processing">{{ t('auth.saveNewPassword') }}</button>
        </form>

        <p class="lg-hint"><Link :href="route('login')" class="lg-link">{{ t('auth.backToSignIn') }}</Link></p>
    </AuthLayout>
</template>
