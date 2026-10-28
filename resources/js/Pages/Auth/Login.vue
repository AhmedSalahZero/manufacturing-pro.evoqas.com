<!--
  ZAVERO Manufacturing Pro — Sign-in screen ( /login )
  Location: resources/js/Pages/Auth/Login.vue
  E-mail + password (checks: App\Http\Requests\Auth\LoginRequest).
  Shows why someone was signed out (e.g. account suspended) and the "password changed" message.
-->
<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import { useI18n } from '@/lang/i18n';

defineProps({ status: { type: String, default: null } });

const { t } = useI18n();
const form = useForm({ email: '', password: '', remember: true });
const submit = () => form.post(route('login'), { onFinish: () => form.reset('password') });
</script>

<template>
    <AuthLayout :title="t('auth.signIn')">
        <h1>{{ t('auth.signIn') }}</h1>
        <p class="lg-sub">{{ t('auth.signInSub') }}</p>

        <div v-if="status" class="note ok lg-note" role="status">{{ status }}</div>

        <form @submit.prevent="submit">
            <label for="lg-email">{{ t('auth.email') }}</label>
            <input id="lg-email" v-model="form.email" class="inp" type="email" dir="ltr" autocomplete="username" :placeholder="t('auth.emailPlaceholder')" required autofocus>

            <div class="lg-row">
                <label for="lg-password">{{ t('auth.password') }}</label>
                <Link :href="route('password.request')" class="lg-link">{{ t('auth.forgot') }}</Link>
            </div>
            <input id="lg-password" v-model="form.password" class="inp" type="password" dir="ltr" autocomplete="current-password" :placeholder="t('auth.passwordPlaceholder')" required>

            <div class="lg-err" role="alert">{{ form.errors.email }}</div>

            <label class="lg-check"><input v-model="form.remember" type="checkbox"> {{ t('auth.remember') }}</label>

            <button class="btn lg-btn" type="submit" :disabled="form.processing">{{ t('auth.signIn') }}</button>
        </form>
    </AuthLayout>
</template>
