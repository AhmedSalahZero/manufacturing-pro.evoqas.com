<!--
  ZAVERO Manufacturing Pro — LangThemeSwitch (English / العربية, and light / dark)
  Location: resources/js/Components/LangThemeSwitch.vue
  Used in the top bar and on the sign-in page. Saved via usePreferences.
  <LangThemeSwitch :theme="false" /> hides the light/dark button (the sign-in panel is always white).
-->
<script setup>
import { ref } from 'vue';
import AppIcon from './AppIcon.vue';
import { useI18n } from '@/lang/i18n';
import { usePreferences } from '@/composables/usePreferences';

defineProps({ theme: { type: Boolean, default: true } });

const { locale, t } = useI18n();
const { savePreference } = usePreferences();
const dark = ref(document.documentElement.getAttribute('data-theme') === 'dark');

function toggleTheme() {
    dark.value = !dark.value;
    savePreference({ theme: dark.value ? 'dark' : 'light' });
}
</script>

<template>
    <div class="seg" role="group" :aria-label="t('common.language')">
        <button type="button" class="btn" :class="{ 'btn-line': locale !== 'en' }" :aria-pressed="locale === 'en'" @click="savePreference({ language: 'en' })">EN</button>
        <button type="button" class="btn" :class="{ 'btn-line': locale !== 'ar' }" :aria-pressed="locale === 'ar'" @click="savePreference({ language: 'ar' })">ع</button>
    </div>
    <button
        v-if="theme" type="button" class="btn btn-line sm" :aria-label="dark ? t('common.switchToLight') : t('common.switchToDark')"
        :title="dark ? t('common.light') : t('common.dark')" @click="toggleTheme"
    >
        <AppIcon :name="dark ? 'sun' : 'moon'" />
    </button>
</template>
