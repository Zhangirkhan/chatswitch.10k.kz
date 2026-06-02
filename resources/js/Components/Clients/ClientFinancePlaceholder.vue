<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';

defineProps<{
    message?: string;
}>();

const { t } = useI18n();
</script>

<template>
    <div
        class="rounded-lg border border-dashed px-4 py-6 text-center text-sm"
        :style="{ borderColor: 'var(--ui-border)', color: 'var(--ui-text-secondary)' }"
    >
        <div class="mb-1 text-base" aria-hidden="true">💳</div>
        <p>{{ message || t('clients.financePlaceholder') }}</p>
    </div>
</template>
