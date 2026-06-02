<script setup lang="ts">
import UiModal from '@/Components/Ui/UiModal.vue';
import { useI18n } from '@/composables/useI18n';
import { computed } from 'vue';

const emit = defineEmits<{ close: [] }>();
const { t } = useI18n();

type Shortcut = {
    labelKey: string;
    keys: string[];
};

const isMac = typeof navigator !== 'undefined' && /Mac|iPod|iPhone|iPad/.test(navigator.platform);
const modKey = isMac ? 'Cmd' : 'Ctrl';

const shortcuts = computed<Shortcut[]>(() => [
    { labelKey: 'profile.shortcuts.searchChats', keys: [modKey, '/'] },
    { labelKey: 'profile.shortcuts.searchChatsAlt', keys: [modKey, 'K'] },
    { labelKey: 'profile.shortcuts.nextChat', keys: ['Alt', '↓'] },
    { labelKey: 'profile.shortcuts.prevChat', keys: ['Alt', '↑'] },
    { labelKey: 'profile.shortcuts.closeChat', keys: ['Escape'] },
    { labelKey: 'profile.shortcuts.newChat', keys: [modKey, 'Shift', 'C'] },
    { labelKey: 'profile.shortcuts.newGroup', keys: [modKey, 'Shift', 'G'] },
    { labelKey: 'profile.shortcuts.searchInChat', keys: [modKey, 'Shift', 'F'] },
    { labelKey: 'profile.shortcuts.contactInfo', keys: [modKey, 'I'] },
    { labelKey: 'profile.shortcuts.emojiPanel', keys: [modKey, 'E'] },
    { labelKey: 'profile.shortcuts.pinChat', keys: [modKey, 'Shift', 'P'] },
    { labelKey: 'profile.shortcuts.mute', keys: [modKey, 'Shift', 'M'] },
    { labelKey: 'profile.shortcuts.archiveChat', keys: [modKey, 'Shift', 'E'] },
    { labelKey: 'profile.shortcuts.markUnread', keys: [modKey, 'Shift', 'U'] },
    { labelKey: 'profile.shortcuts.settings', keys: [modKey, ','] },
]);
</script>

<template>
    <UiModal
        :open="true"
        :title="t('profile.shortcuts.title')"
        max-width="2xl"
        body-class="px-5 py-4"
        @close="emit('close')"
    >
        <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-3">
            <div
                v-for="shortcut in shortcuts"
                :key="shortcut.labelKey"
                class="flex items-center justify-between gap-3"
            >
                <span class="text-[13px] text-[var(--wa-text)] leading-tight">{{ t(shortcut.labelKey) }}</span>
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
                {{ t('profile.shortcuts.ok') }}
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
