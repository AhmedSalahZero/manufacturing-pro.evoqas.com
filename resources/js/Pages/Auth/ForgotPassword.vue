<!--
  ZAVERO Manufacturing Pro — Forgot password ( /forgot-password )
  Location: resources/js/Pages/Auth/ForgotPassword.vue
  Sends a reset link by e-mail. The answer is always the same, so nobody can find out
  which e-mails have accounts (App\Http\Controllers\Auth\PasswordResetLinkController).
-->
<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { useI18n } from '@/lang/i18n';

defineProps({ status: { type: String, default: null } });

const { t } = useI18n();
const form = useForm({ email: '' });
const submit = () => form.post(route('password.email'));
</script>

<template>
    <AuthLayout :title="t('auth.forgotTitle')">
        <h1>{{ t('auth.forgotTitle') }}</h1>
        <p class="lg-sub">{{ t('auth.forgotText') }}</p>

        <div v-if="status" class="note ok lg-note" role="status">{{ status }}</div>

        <form @submit.prevent="submit">
            <label for="fp-email">{{ t('auth.email') }}</label>
            <input id="fp-email" v-model="form.email" class="inp" type="email" dir="ltr" autocomplete="username" :placeholder="t('auth.emailPlaceholder')" required autofocus>
            <div class="lg-err" role="alert">{{ form.errors.email }}</div>
            <button class="btn lg-btn" type="submit" :disabled="form.processing">{{ t('auth.sendLink') }}</button>
        </form>

        <p class="lg-hint"><Link :href="route('login')" class="lg-link">{{ t('auth.backToSignIn') }}</Link></p>
    </AuthLayout>
</template>
