<!--
  ZAVERO Manufacturing Pro — AppLayout (every signed-in screen)
  Location: resources/js/Layouts/AppLayout.vue
  The approved top bar with the logo, the section tabs and the person's tools
  (language, theme, sign out), the second bar with the pages of the study (only inside a study), then the screen itself. Also shows:
    · a notice while the device is offline;
    · a notice when a new version of the app is ready ("Update now").
  Each section's colour comes from data-accent on <body> (see resources/css/design/base.css).
-->
<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import LangThemeSwitch from '@/Components/LangThemeSwitch.vue';
import FlashToast from '@/Components/FlashToast.vue';
import AppIcon from '@/Components/AppIcon.vue';
import StudyNav from '@/Components/StudyNav.vue';
import { useI18n } from '@/lang/i18n';
import { useOnline } from '@/composables/useOnline';
import { applyUpdate, updateReady } from '@/pwa/register';
import { sectionsFor } from '@/Layouts/navigation';

const props = defineProps({
    title: { type: String, default: '' },
    active: { type: String, default: 'home' },
    accent: { type: String, default: 'blue' },
});

const { t } = useI18n();
const { online } = useOnline();
const page = usePage();
const user = computed(() => page.props.auth.user);
const tabs = computed(() => sectionsFor(user.value.role));
const company = computed(() => user.value.company);
const study = computed(() => page.props.study ?? null);
const endingSoon = computed(() => company.value && !company.value.read_only && company.value.days_left !== null && company.value.days_left <= 30);

onMounted(() => document.body.setAttribute('data-accent', props.accent));
onBeforeUnmount(() => document.body.removeAttribute('data-accent'));

const signOut = () => router.post(route('logout'));
</script>

<template>
    <Head :title="title" />

    <header class="top">
        <div class="wrap">
            <Link :href="route('home')" class="brand" style="color:inherit;text-decoration:none">
                <span class="logo-pill"><img src="/images/logo-mark.png" :alt="t('app.name')" width="30" height="30"></span>
                <span class="brand-t">{{ t('app.name') }}</span>
            </Link>

            <nav class="tabbar" :aria-label="t('nav.main')">
                <Link
                    v-for="s in tabs" :key="s.key" :href="route(s.route)" as="button"
                    :class="{ on: s.key === active }" :aria-current="s.key === active ? 'page' : undefined"
                >{{ t(s.label) }}</Link>
            </nav>

            <div class="topr">
                <div class="vbadge"><b>{{ user.name }}</b>{{ company ? `${company.name} · ` : '' }}{{ user.email }}</div>
                <LangThemeSwitch />
                <button type="button" class="btn btn-line sm" @click="signOut"><AppIcon name="logout" :size="14" />{{ t('common.signOut') }}</button>
            </div>
        </div>
    </header>

    <StudyNav v-if="study" :study="study" />

    <main class="page wrap">
        <div v-if="!online" class="note" role="status">{{ t('pwa.offline') }}</div>
        <div v-if="company?.read_only" class="note bad" role="alert">{{ t('subscription.ended', { company: company.name, date: company.ends }) }}</div>
        <div v-else-if="endingSoon" class="note" role="status">{{ t('subscription.endingSoon', { company: company.name, days: company.days_left, date: company.ends }) }}</div>
        <div v-if="updateReady" class="note ok" role="status">
            {{ t('pwa.updateReady') }}
            <button type="button" class="btn sm" style="margin-inline-start:12px" @click="applyUpdate">{{ t('pwa.update') }}</button>
        </div>
        <slot />
    </main>

    <FlashToast />
</template>
