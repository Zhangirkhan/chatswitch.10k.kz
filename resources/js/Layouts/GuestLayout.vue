<script setup lang="ts">
import AccelMark from '@/Components/AccelMark.vue';
import ConnectionLostOverlay from '@/Components/ConnectionLostOverlay.vue';
</script>

<template>
    <div class="min-h-screen flex flex-col" :style="{ background: 'var(--wa-panel)' }">
        <div class="h-[220px]" :style="{ background: 'var(--wa-accent)' }"></div>

        <div class="flex-1 flex items-start justify-center -mt-[140px] px-4">
            <div
                class="w-full max-w-[460px] rounded-lg shadow-2xl overflow-hidden border"
                :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border-strong)' }"
            >
                <div class="flex items-center gap-3 px-8 pt-10 pb-4">
                    <AccelMark :size="44" />
                    <div>
                        <h1 class="text-xl font-normal text-[var(--wa-text)]">Accel</h1>
                        <p class="text-xs text-[var(--wa-text-secondary)]">Multi-WhatsApp Management</p>
                    </div>
                </div>

                <div class="px-8 pb-10">
                    <slot />
                </div>
            </div>
        </div>

        <div class="text-center py-4 text-xs text-[var(--wa-text-secondary)]">
            Accel &copy; {{ new Date().getFullYear() }}
        </div>

        <ConnectionLostOverlay />
    </div>
</template>
