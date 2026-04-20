<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import SettingRow from './SettingRow.vue';
import SettingToggle from './SettingToggle.vue';
import { useLocalSetting } from '@/composables/useLocalSetting';
import { useTheme, type Theme } from '@/composables/useTheme';
import { computed, ref } from 'vue';

const { theme, set: setTheme } = useTheme();

const themeLabel = computed(() => (theme.value === 'dark' ? 'Тёмный режим' : 'Дневной режим'));

const themePickerOpen = ref(false);

const mediaQuality = useLocalSetting<'best' | 'data-saver'>('chats.mediaQuality', 'best');
const autoDownload = useLocalSetting<'always' | 'wifi' | 'never'>('chats.autoDownload', 'wifi');
const spellCheck = useLocalSetting('chats.spellCheck', true);
const replaceEmoji = useLocalSetting('chats.replaceEmoji', true);
const enterToSend = useLocalSetting('chats.enterToSend', true);

const mediaQualityLabel = computed(() => (mediaQuality.value === 'best' ? 'Наилучшее' : 'Экономия трафика'));
const autoDownloadLabel = computed(() => ({ always: 'Всегда', wifi: 'Только Wi-Fi', never: 'Никогда' }[autoDownload.value]));

function pickTheme(t: Theme) {
    setTheme(t);
    themePickerOpen.value = false;
}
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader title="Чаты" />

        <div class="flex-1 overflow-y-auto wa-scrollbar">
            <!-- Display -->
            <div class="group-title">Отображение</div>

            <SettingRow
                title="Тема"
                :subtitle="themeLabel"
                @click="themePickerOpen = true"
            />
            <SettingRow
                title="Обои"
                subtitle="По умолчанию"
            />

            <div class="section-divider" />

            <!-- Chat settings -->
            <div class="group-title">Настройки чата</div>

            <SettingRow
                title="Качество загрузки медиафайлов"
                :subtitle="mediaQualityLabel"
                @click="mediaQuality = mediaQuality === 'best' ? 'data-saver' : 'best'"
            />
            <SettingRow
                title="Автозагрузка медиа"
                :subtitle="autoDownloadLabel"
                @click="autoDownload = autoDownload === 'always' ? 'wifi' : autoDownload === 'wifi' ? 'never' : 'always'"
            />

            <SettingToggle
                v-model="spellCheck"
                title="Проверка орфографии"
                description="Проверка правописания при вводе"
            />
            <SettingToggle
                v-model="replaceEmoji"
                title="Замена текста на смайлики"
                description="При вводе определённого текста он будет заменён на соответствующий смайлик."
            />
            <SettingToggle
                v-model="enterToSend"
                title="Отправка клавишей &quot;Ввод&quot;"
                description="Клавиша ВВОД отправляет сообщение"
            />
        </div>

        <!-- Theme picker modal -->
        <div
            v-if="themePickerOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            @click.self="themePickerOpen = false"
        >
            <div class="w-[440px] max-w-[90vw] rounded-xl p-6 shadow-2xl" :style="{ background: 'var(--wa-panel)' }">
                <h3 class="text-[17px] text-[var(--wa-text)] mb-4">Выберите тему</h3>
                <div class="space-y-2">
                    <label class="flex items-center gap-3 px-2 py-2 rounded-lg cursor-pointer hover:bg-[var(--wa-panel-hover)]">
                        <input
                            type="radio"
                            name="theme"
                            :checked="theme === 'light'"
                            @change="pickTheme('light')"
                            class="accent-[var(--wa-accent)]"
                        />
                        <span class="text-[15px] text-[var(--wa-text)]">Дневной режим</span>
                    </label>
                    <label class="flex items-center gap-3 px-2 py-2 rounded-lg cursor-pointer hover:bg-[var(--wa-panel-hover)]">
                        <input
                            type="radio"
                            name="theme"
                            :checked="theme === 'dark'"
                            @change="pickTheme('dark')"
                            class="accent-[var(--wa-accent)]"
                        />
                        <span class="text-[15px] text-[var(--wa-text)]">Тёмный режим</span>
                    </label>
                </div>
                <div class="flex justify-end mt-4">
                    <button
                        type="button"
                        @click="themePickerOpen = false"
                        class="px-6 py-2 rounded-full text-white text-sm font-medium"
                        :style="{ background: 'var(--wa-accent)' }"
                    >
                        OK
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.group-title {
    padding: 1rem 1.5rem 0.5rem;
    font-size: 0.875rem;
    color: var(--wa-text-secondary);
}
.section-divider {
    height: 10px;
    background-color: var(--wa-bg);
}
</style>
