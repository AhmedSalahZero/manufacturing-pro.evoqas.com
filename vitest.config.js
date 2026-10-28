// ZAVERO Manufacturing Pro — settings for the screen tests (`npm test`).
// Location: vitest.config.js
import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: { '@': fileURLToPath(new URL('./resources/js', import.meta.url)) },
    },
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.spec.js', 'resources/js/**/*.spec.js'],
    },
});
