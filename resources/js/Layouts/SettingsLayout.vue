<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from './AuthenticatedLayout.vue';
import SettingsSidebar from '@/Pages/Settings/Partials/SettingsSidebar.vue';
import SettingsContentSkeleton from '@/Components/Settings/SettingsContentSkeleton.vue';

defineProps<{
    title?: string;
    subtitle?: string;
}>();

type SkeletonVariant = 'default' | 'table' | 'cards' | 'form';

const navigating = ref(false);
const skeletonVariant = ref<SkeletonVariant>('default');
const page = usePage();
const settingsFlashWarning = computed(() => {
    const flash = page.props.flash as { warning?: string } | undefined;

    return typeof flash?.warning === 'string' ? flash.warning : '';
});

function variantForUrl(url: string): SkeletonVariant {
    if (url.includes('/settings/clients')) {
        return 'table';
    }
    if (url.includes('/settings/funnels') || url.includes('/settings/connections')) {
        return 'cards';
    }
    if (
        url.includes('/settings/onboarding')
        || url.includes('/settings/ai-quality')
        || url.includes('/settings/tone-profile')
        || url.includes('/settings/system')
        || url.includes('/settings/changelog')
    ) {
        return 'form';
    }
    if (url.includes('/settings/users') || url.includes('/settings/departments')) {
        return 'table';
    }

    return 'default';
}

function visitPath(url: string | URL): string {
    if (typeof url === 'string') {
        return url;
    }

    return url.pathname;
}

let removeStart: (() => void) | undefined;
let removeFinish: (() => void) | undefined;

onMounted(() => {
    removeStart = router.on('start', (event) => {
        const path = visitPath(event.detail.visit.url);
        if (!path.includes('/settings')) {
            return;
        }
        skeletonVariant.value = variantForUrl(path);
        navigating.value = true;
    });
    removeFinish = router.on('finish', () => {
        navigating.value = false;
    });
});

onUnmounted(() => {
    removeStart?.();
    removeFinish?.();
});
</script>

<template>
    <AuthenticatedLayout>
        <div class="flex h-full w-full bg-[var(--ui-bg)]">
            <SettingsSidebar class="shrink-0" />

            <div class="flex-1 flex flex-col min-w-0 border-l border-[var(--ui-border-strong)] bg-[var(--ui-bg)]">
                <div
                    class="h-[60px] px-6 flex items-center justify-between shrink-0"
                    :style="{ background: 'var(--ui-surface-muted)', borderBottom: '1px solid var(--ui-border-strong)' }"
                >
                    <div class="min-w-0">
                        <h2 class="text-[17px] font-normal text-[var(--ui-text)] truncate">
                            {{ title }}
                        </h2>
                        <p v-if="subtitle" class="text-xs text-[var(--ui-text-secondary)] truncate">
                            {{ subtitle }}
                        </p>
                    </div>
                    <slot name="actions" />
                </div>

                <div
                    v-if="settingsFlashWarning"
                    class="mx-6 mt-4 rounded-xl border px-4 py-3 text-sm shrink-0"
                    :style="{
                        borderColor: 'color-mix(in srgb, #d97706 45%, var(--ui-border))',
                        background: 'color-mix(in srgb, #d97706 10%, var(--ui-surface))',
                        color: 'var(--ui-text)',
                    }"
                >
                    {{ settingsFlashWarning }}
                </div>

                <div class="flex-1 overflow-y-auto wa-scrollbar relative">
                    <slot />
                    <div
                        v-if="navigating"
                        class="absolute inset-0 z-10 bg-[var(--ui-bg)]"
                        aria-busy="true"
                        aria-live="polite"
                    >
                        <SettingsContentSkeleton :variant="skeletonVariant" />
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
