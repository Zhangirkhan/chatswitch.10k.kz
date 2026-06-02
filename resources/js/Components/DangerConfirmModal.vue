<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

/**
 * Модальное подтверждение деструктивных действий (замена window.confirm).
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description: string;
        confirmLabel?: string;
        cancelLabel?: string;
        busy?: boolean;
        /** danger — красная кнопка; primary — акцентная */
        confirmVariant?: 'danger' | 'primary';
    }>(),
    {
        confirmLabel: 'Подтвердить',
        cancelLabel: 'Отмена',
        busy: false,
        confirmVariant: 'danger',
    },
);

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'confirm'): void;
}>();

const panelRef = ref<HTMLElement | null>(null);
let previousFocus: HTMLElement | null = null;

function onBackdrop(): void {
    if (!props.busy) {
        emit('close');
    }
}

function getFocusableElements(): HTMLElement[] {
    const panel = panelRef.value;
    if (!panel) {
        return [];
    }

    return Array.from(
        panel.querySelectorAll<HTMLElement>(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
        ),
    ).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

function onKeydown(event: KeyboardEvent): void {
    if (!props.open) {
        return;
    }

    if (event.key === 'Escape' && !props.busy) {
        event.preventDefault();
        emit('close');
        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const focusable = getFocusableElements();
    if (focusable.length === 0) {
        event.preventDefault();
        panelRef.value?.focus();
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    const active = document.activeElement;

    if (event.shiftKey) {
        if (active === first || active === panelRef.value) {
            event.preventDefault();
            last.focus();
        }
        return;
    }

    if (active === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(
    () => props.open,
    async (isOpen) => {
        if (isOpen) {
            previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            document.addEventListener('keydown', onKeydown);
            await nextTick();
            (getFocusableElements()[0] ?? panelRef.value)?.focus();
            return;
        }

        document.removeEventListener('keydown', onKeydown);
        previousFocus?.focus();
        previousFocus = null;
    },
);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
});

const headingId = useId();

const confirmButtonClass = computed(() =>
    props.confirmVariant === 'primary'
        ? 'text-white bg-[var(--wa-accent)] hover:opacity-95'
        : 'text-white bg-[var(--wa-danger)] hover:opacity-95',
);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="fixed inset-0 z-[1205] flex items-center justify-center bg-black/55 p-4"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="headingId"
            @click.self="onBackdrop"
        >
            <div
                ref="panelRef"
                tabindex="-1"
                class="w-full max-w-[440px] overflow-hidden rounded-2xl border shadow-2xl flex flex-col outline-none"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-control-rim)', boxShadow: 'var(--wa-control-rim-shadow)' }"
                @click.stop
            >
                <div class="px-5 py-4 flex items-start justify-between gap-3 border-b" :style="{ borderColor: 'var(--wa-border)' }">
                    <h3 :id="headingId" class="text-base font-semibold text-[var(--wa-text)]">
                        {{ title }}
                    </h3>
                    <button
                        type="button"
                        class="danger-confirm-close w-9 h-9 shrink-0 rounded-full flex items-center justify-center disabled:opacity-40"
                        aria-label="Закрыть"
                        :disabled="busy"
                        @click="emit('close')"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                <div class="px-5 py-4">
                    <p class="text-sm text-[var(--wa-text)] leading-relaxed whitespace-pre-wrap">{{ description }}</p>
                </div>
                <div
                    class="px-5 py-4 flex flex-col-reverse sm:flex-row sm:justify-end gap-2 border-t"
                    :style="{ borderColor: 'var(--wa-border)' }"
                >
                    <button
                        type="button"
                        class="py-2.5 px-4 rounded-xl text-sm font-medium border border-[var(--wa-border)] text-[var(--wa-text)] hover:bg-[var(--wa-panel-hover)] disabled:opacity-50"
                        :disabled="busy"
                        @click="emit('close')"
                    >
                        {{ cancelLabel }}
                    </button>
                    <button
                        type="button"
                        class="py-2.5 px-4 rounded-xl text-sm font-medium disabled:opacity-50"
                        :class="confirmButtonClass"
                        :disabled="busy"
                        @click="emit('confirm')"
                    >
                        {{ busy ? 'Подождите…' : confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.danger-confirm-close {
    color: var(--wa-danger);
    transition: background-color 0.15s ease, color 0.15s ease;
}

.danger-confirm-close:hover:not(:disabled) {
    background: color-mix(in srgb, var(--wa-danger) 10%, var(--wa-panel-hover));
    color: color-mix(in srgb, var(--wa-danger) 86%, var(--wa-text));
}
</style>
