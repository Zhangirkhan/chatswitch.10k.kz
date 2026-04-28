<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue';

const props = defineProps<{
    show: boolean;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'confirm', duration: '8h' | '1w' | 'always'): void;
}>();

const selected = ref<'8h' | '1w' | 'always'>('8h');

watch(
    () => props.show,
    (val) => {
        if (val) {
            selected.value = '8h';
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }
    },
);

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape' && props.show) {
        e.preventDefault();
        emit('close');
    }
}

window.addEventListener('keydown', onEscape);
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onEscape);
    document.body.style.overflow = '';
});

function confirm() {
    emit('confirm', selected.value);
}
</script>

<template>
    <teleport to="body">
        <Transition
            enter-active-class="ease-out duration-150"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="ease-in duration-100"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-[100] flex items-center justify-center px-4"
                @click.self="emit('close')"
            >
                <div
                    class="absolute inset-0"
                    style="background: rgba(0, 0, 0, 0.7)"
                    @click="emit('close')"
                ></div>

                <Transition
                    enter-active-class="ease-out duration-150"
                    enter-from-class="opacity-0 scale-95 translate-y-2"
                    enter-to-class="opacity-100 scale-100 translate-y-0"
                    leave-active-class="ease-in duration-100"
                    leave-from-class="opacity-100 scale-100 translate-y-0"
                    leave-to-class="opacity-0 scale-95 translate-y-2"
                >
                    <div
                        v-if="show"
                        class="relative w-full max-w-[440px] rounded-xl shadow-2xl px-6 py-6"
                        :style="{
                            background: 'var(--wa-panel-header)',
                            color: 'var(--wa-text)',
                        }"
                        @click.stop
                    >
                        <h2 class="text-[20px] font-semibold mb-3">Без звука</h2>
                        <p
                            class="text-sm mb-5 leading-snug"
                            :style="{ color: 'var(--wa-text-secondary)' }"
                        >
                            Никто из участников чата не увидит, что вы отключили звук чата.
                            Если вас упомянут, вы получите уведомление.
                        </p>

                        <div class="flex flex-col gap-1 mb-6">
                            <label
                                v-for="opt in [
                                    { value: '8h', label: '8 часов' },
                                    { value: '1w', label: '1 неделю' },
                                    { value: 'always', label: 'Всегда' },
                                ]"
                                :key="opt.value"
                                class="mute-option"
                            >
                                <input
                                    type="radio"
                                    name="mute-duration"
                                    :value="opt.value"
                                    v-model="selected"
                                    class="sr-only"
                                />
                                <span
                                    class="radio-dot"
                                    :class="{ 'is-checked': selected === opt.value }"
                                >
                                    <span v-if="selected === opt.value" class="radio-inner"></span>
                                </span>
                                <span class="text-[15px]">{{ opt.label }}</span>
                            </label>
                        </div>

                        <div class="flex items-center justify-end gap-2">
                            <button
                                type="button"
                                class="btn-text"
                                @click="emit('close')"
                            >
                                Отмена
                            </button>
                            <button
                                type="button"
                                class="btn-primary"
                                @click="confirm"
                            >
                                Без звука
                            </button>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </teleport>
</template>

<style scoped>
.mute-option {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.625rem 0.25rem;
    border-radius: 0.375rem;
    cursor: pointer;
    transition: background-color 0.12s ease;
}
.mute-option:hover {
    background-color: var(--wa-panel-hover);
}
.radio-dot {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 9999px;
    border: 2px solid var(--wa-text-secondary);
    transition: border-color 0.12s ease;
    flex-shrink: 0;
}
.radio-dot.is-checked {
    border-color: var(--wa-accent);
}
.radio-inner {
    width: 10px;
    height: 10px;
    border-radius: 9999px;
    background: var(--wa-accent);
}
.btn-text {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--wa-accent);
    background: transparent;
    border-radius: 9999px;
    transition: background-color 0.12s ease;
}
.btn-text:hover {
    background-color: var(--wa-accent-soft);
}
.btn-primary {
    padding: 0.5rem 1.25rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--wa-unread-text, #0b0d0e);
    background: var(--wa-accent);
    border-radius: 9999px;
    transition: filter 0.12s ease;
}
.btn-primary:hover {
    filter: brightness(1.05);
}
</style>
