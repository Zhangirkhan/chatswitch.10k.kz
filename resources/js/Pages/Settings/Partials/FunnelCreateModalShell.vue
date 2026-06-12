<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        accent?: 'ai' | 'manual' | 'neutral';
        title: string;
        subtitle?: string;
        eyebrow?: string;
        wide?: boolean;
        closeLabel: string;
    }>(),
    {
        accent: 'neutral',
        subtitle: '',
        eyebrow: '',
        wide: false,
    },
);

defineEmits<{
    close: [];
}>();

const accentColor = computed(() => {
    if (props.accent === 'ai') {
        return '#eab308';
    }
    if (props.accent === 'manual') {
        return 'var(--ui-accent)';
    }
    return 'var(--ui-accent)';
});

const modalClass = computed(() => (props.wide ? 'max-w-3xl' : 'max-w-lg'));
</script>

<template>
    <div
        class="funnel-create-backdrop fixed inset-0 z-[2000] flex items-center justify-center p-4 sm:p-6"
        role="dialog"
        aria-modal="true"
        @click.self="$emit('close')"
    >
        <div
            class="funnel-create-modal relative flex w-full max-h-[min(92vh,820px)] flex-col overflow-hidden rounded-2xl border shadow-2xl"
            :class="modalClass"
            :style="{ '--funnel-create-accent': accentColor }"
            @click.stop
        >
            <div class="funnel-create-modal__glow" aria-hidden="true" />

            <header class="relative shrink-0 border-b px-6 py-5 sm:px-7" :style="{ borderColor: 'var(--ui-border)' }">
                <button
                    type="button"
                    class="absolute right-4 top-4 rounded-lg p-2 text-[var(--ui-text-secondary)] transition hover:bg-[var(--ui-surface-hover)] hover:text-[var(--ui-text)]"
                    :aria-label="closeLabel"
                    @click="$emit('close')"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>

                <div class="flex items-start gap-4 pr-10">
                    <span
                        class="funnel-create-modal__icon shrink-0"
                        :class="{
                            'funnel-create-modal__icon--ai': accent === 'ai',
                            'funnel-create-modal__icon--manual': accent === 'manual',
                        }"
                        aria-hidden="true"
                    >
                        <svg
                            v-if="accent === 'ai'"
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.5 6.5L22 12l-6.5 2.5L13 21l-2.5-6.5L4 12l6.5-2.5L13 3z" />
                        </svg>
                        <svg
                            v-else-if="accent === 'manual'"
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                        </svg>
                        <svg
                            v-else
                            class="h-5 w-5"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.8"
                            viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </span>

                    <div class="min-w-0">
                        <p
                            v-if="eyebrow"
                            class="text-[11px] font-semibold uppercase tracking-[0.14em]"
                            :style="{ color: 'var(--funnel-create-accent)' }"
                        >
                            {{ eyebrow }}
                        </p>
                        <h3 class="text-lg font-semibold tracking-tight text-[var(--ui-text)] sm:text-xl">
                            {{ title }}
                        </h3>
                        <p
                            v-if="subtitle"
                            class="mt-1.5 max-w-2xl text-sm leading-relaxed text-[var(--ui-text-secondary)]"
                        >
                            {{ subtitle }}
                        </p>
                    </div>
                </div>
            </header>

            <div class="relative flex-1 overflow-y-auto wa-scrollbar px-6 py-5 sm:px-7">
                <slot />
            </div>

            <footer
                v-if="$slots.footer"
                class="relative shrink-0 border-t px-6 py-4 sm:px-7"
                :style="{ borderColor: 'var(--ui-border)' }"
            >
                <slot name="footer" />
            </footer>
        </div>
    </div>
</template>

<style scoped>
.funnel-create-backdrop {
    background: color-mix(in srgb, var(--ui-bg) 55%, rgb(0 0 0 / 45%));
    backdrop-filter: blur(6px);
}

.funnel-create-modal {
    background: var(--ui-surface);
    border-color: var(--ui-border-strong);
    animation: funnel-create-in 0.28s cubic-bezier(0.22, 1, 0.36, 1);
}

.funnel-create-modal__glow {
    position: absolute;
    inset: -28% auto auto 50%;
    width: 340px;
    height: 220px;
    transform: translateX(-50%);
    background: radial-gradient(
        circle,
        color-mix(in srgb, var(--funnel-create-accent) 10%, transparent) 0%,
        transparent 68%
    );
    pointer-events: none;
    opacity: 0.75;
}

.funnel-create-modal__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 0.85rem;
    color: var(--ui-text);
    background: var(--ui-surface-muted);
    border: 1px solid var(--ui-border);
}

.funnel-create-modal__icon--ai {
    color: #eab308;
    background: color-mix(in srgb, #eab308 12%, var(--ui-surface));
    border-color: color-mix(in srgb, #eab308 32%, var(--ui-border));
}

.funnel-create-modal__icon--manual {
    color: var(--ui-accent);
    background: color-mix(in srgb, var(--ui-accent) 10%, var(--ui-surface));
    border-color: color-mix(in srgb, var(--ui-accent) 28%, var(--ui-border));
}

@keyframes funnel-create-in {
    from {
        opacity: 0;
        transform: translateY(12px) scale(0.98);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}
</style>
