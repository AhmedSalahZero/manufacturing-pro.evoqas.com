<!--
  ZAVERO Manufacturing Pro — PublicLayout (the page behind a public sharing link)
  Location: resources/js/Layouts/PublicLayout.vue
  A slim top bar with the logo, "read only" badge and the language / theme switch. No menus, no sign-in: a visitor only looks.
-->
<script setup>
import { Head } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import LangThemeSwitch from '@/Components/LangThemeSwitch.vue';
import { useI18n } from '@/lang/i18n';

defineProps({ title: { type: String, default: '' } });

const { t } = useI18n();

onMounted(() => document.body.setAttribute('data-accent', 'violet'));
onBeforeUnmount(() => document.body.removeAttribute('data-accent'));
</script>

<template>
    <Head :title="title" />

    <header class="top">
        <div class="wrap">
            <span class="brand" style="color:inherit">
                <span class="logo-pill"><img src="/images/logo-mark.png" :alt="t('app.name')" width="30" height="30"></span>
                <span class="brand-t">{{ t('app.name') }}</span>
            </span>
            <div class="topr">
                <span class="vbadge"><b>{{ t('shared.badge') }}</b></span>
                <LangThemeSwitch />
            </div>
        </div>
    </header>

    <main class="page wrap">
        <slot />
    </main>
</template>
