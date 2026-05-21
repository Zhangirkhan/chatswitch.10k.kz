<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title?: string;
        subtitle?: string;
        /** sm=440, md=520, lg=560, xl=640, 2xl=720, 3xl=896, 4xl=1024 */
        maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl' | '3xl' | '4xl';
        closeable?: boolean;
        showClose?: boolean;
        zIndex?: number;
        ariaLabel?: string;
        panelClass?: string;
        bodyClass?: string;
    }>(),
    {
        title: '',
        subtitle: '',
        maxWidth: 'lg',
        closeable: true,
        showClose: true,
        zIndex: 1200,
        ariaLabel: '',
        panelClass: '',
        bodyClass: '',
    },
);

const emit = defineEmits<{
    close: [];
}>();

const headingId = useId();
const panelRef = ref<HTMLElement | null>(null);
let previousFocus: HTMLElement | null = null;

const maxWidthClass: Record<string, string> = {
    sm: 'max-w-[440px]',
    md: 'max-w-[520px]',
    lg: 'max-w-[560px]',
    xl: 'max-w-[640px]',
    '2xl': 'max-w-[720px]',
    '3xl': 'max-w-[896px]',
    '4xl': 'max-w-[1024px]',
};

function close(): void {
    if (props.closeable) {
        emit('close');
    }
}

function onBackdrop(): void {
    close();
}

function onKeydown(event: KeyboardEvent): void {
    if (!props.open || event.key !== 'Escape') {
        return;
    }
    event.preventDefault();
    close();
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

function focusFirst(): void {
    const focusable = getFocusableElements();
    (focusable[0] ?? panelRef.value)?.focus();
}

function onTabKeydown(event: KeyboardEvent): void {
    if (!props.open || event.key !== 'Tab') {
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
            document.body.style.overflow = 'hidden';
            document.addEventListener('keydown', onKeydown);
            document.addEventListener('keydown', onTabKeydown);
            await nextTick();
            focusFirst();
            return;
        }

        document.body.style.overflow = '';
        document.removeEventListener('keydown', onKeydown);
        document.removeEventListener('keydown', onTabKeydown);
        previousFocus?.focus();
        previousFocus = null;
    },
);

onBeforeUnmount(() => {
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('keydown', onTabKeydown);
});
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="ui-modal-backdrop fixed inset-0 flex items-center justify-center bg-black/55 p-4"
            :style="{ zIndex }"
            role="dialog"
            aria-modal="true"
            :aria-label="ariaLabel || undefined"
            :aria-labelledby="title ? headingId : undefined"
            @click.self="onBackdrop"
        >
            <div
                ref="panelRef"
                tabindex="-1"
                class="ui-modal-panel w-full max-h-[min(90vh,760px)] overflow-hidden rounded-2xl border shadow-2xl flex flex-col outline-none"
                :class="[maxWidthClass[maxWidth], panelClass]"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border-strong)' }"
                @click.stop
            >
                <div
                    v-if="title || $slots.header || showClose"
                    class="ui-modal-header px-5 py-4 flex items-start justify-between gap-3 border-b shrink-0"
                    :style="{ borderColor: 'var(--wa-border)' }"
                >
                    <div class="min-w-0 flex-1">
                        <slot name="header">
                            <h3 :id="headingId" class="text-base font-semibold text-[var(--wa-text)] m-0">
                                {{ title }}
                            </h3>
                            <p v-if="subtitle" class="text-xs text-[var(--wa-text-secondary)] mt-0.5 mb-0">
                                {{ subtitle }}
                            </p>
                        </slot>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <slot name="header-actions" />
                        <button
                            v-if="showClose"
                            type="button"
                            class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)] text-[var(--wa-text-secondary)]"
                            aria-label="Закрыть"
                            @click="close"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="ui-modal-body flex-1 min-h-0 overflow-y-auto wa-scrollbar" :class="bodyClass">
                    <slot />
                </div>

                <div
                    v-if="$slots.footer"
                    class="ui-modal-footer px-5 py-4 flex justify-end gap-2 border-t shrink-0"
                    :style="{ borderColor: 'var(--wa-border)' }"
                >
                    <slot name="footer" />
                </div>
            </div>
        </div>
    </Teleport>
</template>
