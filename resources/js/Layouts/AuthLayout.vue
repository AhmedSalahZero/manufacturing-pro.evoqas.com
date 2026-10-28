<!--
  ZAVERO Manufacturing Pro — AuthLayout (sign-in, forgot / reset password)
  Location: resources/js/Layouts/AuthLayout.vue
  The approved sign-in design: the factory picture on one side, the white form on the other
  (stacked on phones; mirrored in Arabic). A short write-up of what the application does sits over the picture. The language can be switched before signing in.
-->
<script setup>
import { Head } from '@inertiajs/vue3';
import LangThemeSwitch from '@/Components/LangThemeSwitch.vue';
import FlashToast from '@/Components/FlashToast.vue';
import { useI18n } from '@/lang/i18n';

defineProps({ title: { type: String, default: '' } });

const { t } = useI18n();
const features = ['products', 'costs', 'assets', 'statements', 'valuation', 'share'];
</script>

<template>
    <Head :title="title" />
    <div class="lg-split" style="--img: url('/images/login-bg.jpg')">
        <div class="lg-art">
            <div class="lg-pic" role="img" :aria-label="t('auth.artAlt')"></div>
            <div class="lg-copy" data-login-copy>
                <p class="lg-eyebrow">{{ t('auth.about.eyebrow') }}</p>
                <h2>{{ t('auth.about.headline') }}</h2>
                <p class="lg-lead">{{ t('auth.about.lead') }}</p>
                <ul class="lg-feats">
                    <li v-for="f in features" :key="f"><b>{{ t(`auth.about.features.${f}.title`) }}</b><span>{{ t(`auth.about.features.${f}.text`) }}</span></li>
                </ul>
                <p class="lg-tag">{{ t('auth.about.tagline') }}</p>
            </div>
        </div>
        <div class="lg-side">
            <div class="lg-wrap">
                <div class="lg-tools"><LangThemeSwitch :theme="false" /></div>
                <div class="lg-form">
                    <img class="lg-logoimg" src="/images/logo.png" :alt="t('app.name')" width="300" height="300">
                    <slot />
                </div>
            </div>
        </div>
    </div>
    <FlashToast />
</template>
