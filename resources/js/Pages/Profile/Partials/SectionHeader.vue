<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useI18n } from '@/composables/useI18n';

defineProps<{
    title: string;
}>();

const { t } = useI18n();

function goBack() {
    // Clearing the ?section param returns to the main settings list.
    router.visit(route('profile.edit'), {
        preserveScroll: false,
        preserveState: false,
    });
}
</script>

<template>
    <div class="h-[60px] shrink-0 flex items-center gap-4 px-4">
        <button
            type="button"
            @click="goBack"
            class="w-10 h-10 rounded-full flex items-center justify-center text-[var(--wa-icon)] hover:bg-[var(--wa-panel-hover)] transition"
            :title="t('common.back')"
            :aria-label="t('common.back')"
        >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </button>
        <h2 class="text-[var(--wa-text)] text-lg font-normal truncate">{{ title }}</h2>
    </div>
</template>
