<script setup lang="ts">
defineProps<{
    title: string;
    subtitle?: string;
    clickable?: boolean;
}>();
</script>

<template>
    <component
        :is="clickable === false ? 'div' : 'button'"
        :type="clickable === false ? undefined : 'button'"
        class="setting-row"
        :class="{ 'is-button': clickable !== false }"
    >
        <div v-if="$slots.icon" class="shrink-0 w-6 flex items-center justify-center text-[var(--wa-icon)]">
            <slot name="icon" />
        </div>
        <div class="flex-1 min-w-0 text-left">
            <div class="text-[15px] text-[var(--wa-text)] truncate">{{ title }}</div>
            <div v-if="subtitle" class="text-xs text-[var(--wa-text-secondary)] truncate mt-0.5">
                {{ subtitle }}
            </div>
            <slot />
        </div>
        <div v-if="clickable !== false" class="shrink-0 text-[var(--wa-text-muted)]">
            <slot name="trailing">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </slot>
        </div>
    </component>
</template>

<style scoped>
.setting-row {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.875rem 1.5rem;
    width: 100%;
    transition: background-color 0.15s ease;
}
.setting-row.is-button {
    cursor: pointer;
}
.setting-row.is-button:hover {
    background-color: var(--wa-panel-hover);
}
</style>
