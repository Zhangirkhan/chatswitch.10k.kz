<script setup lang="ts">
import { onBeforeUnmount, onMounted } from 'vue';

const emit = defineEmits<{ close: [] }>();

type Shortcut = {
    label: string;
    keys: string[];
};

const isMac = typeof navigator !== 'undefined' && /Mac|iPod|iPhone|iPad/.test(navigator.platform);
const modKey = isMac ? 'Cmd' : 'Ctrl';

const shortcuts: Shortcut[] = [
    { label: 'Пометить как непрочитанное', keys: [modKey, 'Shift', 'U'] },
    { label: 'Без звука', keys: [modKey, 'Shift', 'M'] },
    { label: 'Архивировать чат', keys: [modKey, 'Shift', 'E'] },
    { label: 'Закрепить чат', keys: [modKey, 'Shift', 'P'] },
    { label: 'Поиск', keys: [modKey, '/'] },
    { label: 'Поиск в чате', keys: [modKey, 'Shift', 'F'] },
    { label: 'Новый чат', keys: [modKey, 'N'] },
    { label: 'Следующий чат', keys: [modKey, 'Tab'] },
    { label: 'Предыдущий чат', keys: [modKey, 'Shift', 'Tab'] },
    { label: 'Добавить ярлык к чату', keys: [modKey, 'Shift', 'L'] },
    { label: 'Закрыть чат', keys: ['Escape'] },
    { label: 'Новая группа', keys: [modKey, 'Shift', 'N'] },
    { label: 'Профиль и информация', keys: [modKey, 'P'] },
    { label: 'Увеличить скорость голосового сообщения', keys: ['Shift', '.'] },
    { label: 'Уменьшить скорость голосового сообщения', keys: ['Shift', ','] },
    { label: 'Настройки', keys: [modKey, ','] },
    { label: 'Панель смайликов', keys: [modKey, 'E'] },
    { label: 'Панель GIF', keys: [modKey, 'G'] },
];

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        e.preventDefault();
        emit('close');
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
        @click.self="emit('close')"
    >
        <div class="rounded-xl p-6 shadow-2xl w-[700px] max-w-[95vw] max-h-[85vh] flex flex-col" :style="{ background: 'var(--wa-panel)' }">
            <h3 class="text-[17px] text-[var(--wa-text)] mb-4 shrink-0">Сочетания клавиш</h3>

            <div class="flex-1 overflow-y-auto wa-scrollbar grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3 pr-1">
                <div
                    v-for="shortcut in shortcuts"
                    :key="shortcut.label"
                    class="flex items-center justify-between gap-3"
                >
                    <span class="text-[13px] text-[var(--wa-text)] leading-tight">{{ shortcut.label }}</span>
                    <div class="flex items-center gap-1 shrink-0">
                        <kbd
                            v-for="key in shortcut.keys"
                            :key="key"
                            class="key"
                        >{{ key }}</kbd>
                    </div>
                </div>
            </div>

            <div class="flex justify-end mt-6 shrink-0">
                <button
                    type="button"
                    @click="emit('close')"
                    class="px-6 py-2 rounded-full text-white text-sm font-medium transition"
                    :style="{ background: 'var(--wa-accent)' }"
                >
                    OK
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.key {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 26px;
    padding: 0 8px;
    border-radius: 6px;
    background-color: var(--wa-panel-header);
    border: 1px solid var(--wa-border-strong);
    font-size: 11px;
    font-family: -apple-system, 'Segoe UI', sans-serif;
    color: var(--wa-text);
}
</style>
