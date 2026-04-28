import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

const root = dirname(fileURLToPath(import.meta.url));

export default defineConfig({
    plugins: [vue()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.spec.ts'],
    },
    resolve: {
        alias: {
            '@': resolve(root, 'resources/js'),
            'ziggy-js': resolve(root, 'vendor/tightenco/ziggy'),
        },
    },
});
