<script setup lang="ts">
import AuthenticatedLayout from './AuthenticatedLayout.vue';
import SettingsSidebar from '@/Pages/Settings/Partials/SettingsSidebar.vue';

defineProps<{
    title?: string;
    subtitle?: string;
}>();
</script>

<template>
    <AuthenticatedLayout>
        <div class="flex h-full w-full bg-[var(--wa-bg)]">
            <SettingsSidebar class="shrink-0" />

            <div class="flex-1 flex flex-col min-w-0 border-l border-[var(--wa-border)] bg-[var(--wa-bg)]">
                <!-- Page header -->
                <div
                    class="h-[60px] px-6 flex items-center justify-between shrink-0"
                    :style="{ background: 'var(--wa-panel-header)', borderBottom: '1px solid var(--wa-border)' }"
                >
                    <div class="min-w-0">
                        <h2 class="text-[17px] font-normal text-[var(--wa-text)] truncate">
                            {{ title }}
                        </h2>
                        <p v-if="subtitle" class="text-xs text-[var(--wa-text-secondary)] truncate">
                            {{ subtitle }}
                        </p>
                    </div>
                    <slot name="actions" />
                </div>

                <!-- Scrollable content -->
                <div class="flex-1 overflow-y-auto wa-scrollbar">
                    <slot />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
