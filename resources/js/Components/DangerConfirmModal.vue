<script setup lang="ts">
import { computed, useId } from 'vue';

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

function onBackdrop(): void {
    if (!props.busy) {
        emit('close');
    }
}

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
                class="w-full max-w-[440px] overflow-hidden rounded-2xl border shadow-2xl flex flex-col"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border-strong)' }"
                @click.stop
            >
                <div class="px-5 py-4 flex items-start justify-between gap-3 border-b" :style="{ borderColor: 'var(--wa-border)' }">
                    <h3 :id="headingId" class="text-base font-semibold text-[var(--wa-text)]">
                        {{ title }}
                    </h3>
                    <button
                        type="button"
                        class="w-9 h-9 shrink-0 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)] text-[var(--wa-text-secondary)] disabled:opacity-40"
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
