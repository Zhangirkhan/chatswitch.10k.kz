<script setup lang="ts">
import UiModal from '@/Components/Ui/UiModal.vue';

const emit = defineEmits<{ close: [] }>();

type Shortcut = {
    label: string;
    keys: string[];
};

const isMac = typeof navigator !== 'undefined' && /Mac|iPod|iPhone|iPad/.test(navigator.platform);
const modKey = isMac ? 'Cmd' : 'Ctrl';

const shortcuts: Shortcut[] = [
    { label: 'Поиск чатов', keys: [modKey, '/'] },
    { label: 'Поиск чатов (альтернатива)', keys: [modKey, 'K'] },
    { label: 'Следующий чат', keys: ['Alt', '↓'] },
    { label: 'Предыдущий чат', keys: ['Alt', '↑'] },
    { label: 'Закрыть чат', keys: ['Escape'] },
    { label: 'Новый чат', keys: [modKey, 'Shift', 'C'] },
    { label: 'Новая группа', keys: [modKey, 'Shift', 'G'] },
    { label: 'Поиск в чате', keys: [modKey, 'Shift', 'F'] },
    { label: 'Информация о контакте', keys: [modKey, 'I'] },
    { label: 'Панель смайликов', keys: [modKey, 'E'] },
    { label: 'Закрепить чат', keys: [modKey, 'Shift', 'P'] },
    { label: 'Без звука', keys: [modKey, 'Shift', 'M'] },
    { label: 'Архивировать чат', keys: [modKey, 'Shift', 'E'] },
    { label: 'Пометить как непрочитанное', keys: [modKey, 'Shift', 'U'] },
    { label: 'Настройки', keys: [modKey, ','] },
];
</script>

<template>
    <UiModal
        :open="true"
        title="Сочетания клавиш"
        max-width="2xl"
        body-class="px-5 py-4"
        @close="emit('close')"
    >
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3">
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

        <template #footer>
            <button
                type="button"
                class="ui-btn ui-btn--primary ui-btn--pill"
                @click="emit('close')"
            >
                OK
            </button>
        </template>
    </UiModal>
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
    border: 1px solid var(--wa-control-rim);
    box-shadow: var(--wa-control-rim-shadow);
    font-size: 11px;
    font-family: -apple-system, 'Segoe UI', sans-serif;
    color: var(--wa-text);
}
</style>
