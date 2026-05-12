<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import SettingRow from './SettingRow.vue';
import { useTheme, type Theme } from '@/composables/useTheme';
import { useChatBackground } from '@/composables/useChatBackground';
import { useTranslationLang, TRANSLATION_LANG_OPTIONS, type TranslationLang } from '@/composables/useTranslationLang';
import { wallpaperPreview } from '@/config/wallpapers';
import { computed, ref } from 'vue';

const { theme, set: setTheme } = useTheme();
const { wallpapers, currentWallpaperId, setWallpaper, getCurrent } = useChatBackground();
const { lang: translateLang, currentOption: translateCurrent } = useTranslationLang();

const themeLabel = computed(() => (theme.value === 'dark' ? 'Тёмный режим' : 'Дневной режим'));
const wallpaperLabel = computed(() => getCurrent().label);

const themePickerOpen = ref(false);
const wallpaperPickerOpen = ref(false);
const translatePickerOpen = ref(false);

function pickTheme(t: Theme) {
    setTheme(t);
    themePickerOpen.value = false;
}

function pickWallpaper(id: string) {
    setWallpaper(id);
}

function previewStyle(id: string): string {
    const wp = wallpapers.find((w) => w.id === id);
    if (!wp) return '';
    return `background: ${wallpaperPreview(wp, theme.value)}; background-size: ${wp.tileSize || (wp.kind === 'default' ? '80px' : 'cover')};`;
}
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader title="Чаты" />

        <div class="flex-1 overflow-y-auto wa-scrollbar">
            <div class="group-title">Отображение</div>

            <SettingRow
                title="Тема"
                :subtitle="themeLabel"
                @click="themePickerOpen = true"
            />
            <SettingRow
                title="Обои"
                :subtitle="wallpaperLabel"
                @click="wallpaperPickerOpen = true"
            />

            <div class="group-title">Перевод сообщений</div>
            <SettingRow
                title="Язык перевода"
                :subtitle="`${translateCurrent().flag} ${translateCurrent().label}`"
                @click="translatePickerOpen = true"
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

        <!-- Translation language picker modal -->
        <div
            v-if="translatePickerOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
            @click.self="translatePickerOpen = false"
        >
            <div class="w-[380px] max-w-[90vw] rounded-xl p-6 shadow-2xl" :style="{ background: 'var(--wa-panel)' }">
                <h3 class="text-[17px] text-[var(--wa-text)] mb-1">Язык перевода сообщений</h3>
                <p class="text-xs text-[var(--wa-text-secondary)] mb-4">
                    Под каждым сообщением появится кнопка «Перевести».<br>
                    Стандартно — русский и казахский.
                </p>
                <div class="space-y-1">
                    <label
                        v-for="opt in TRANSLATION_LANG_OPTIONS"
                        :key="opt.value"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-lg cursor-pointer hover:bg-[var(--wa-panel-hover)] transition"
                    >
                        <input
                            type="radio"
                            name="translate_lang"
                            :value="opt.value"
                            v-model="translateLang"
                            class="accent-[var(--wa-accent)]"
                        />
                        <span class="text-lg leading-none">{{ opt.flag }}</span>
                        <span class="text-[15px] text-[var(--wa-text)]">{{ opt.label }}</span>
                        <span v-if="opt.value === 'ru' || opt.value === 'kk'" class="ml-auto text-[11px] px-1.5 py-0.5 rounded" :style="{ background: 'var(--wa-accent)', color: 'var(--wa-unread-text, #0b0d0e)' }">
                            по умолчанию
                        </span>
                    </label>
                </div>
                <div class="flex justify-end mt-5">
                    <button
                        type="button"
                        @click="translatePickerOpen = false"
                        class="px-6 py-2 rounded-full text-white text-sm font-medium"
                        :style="{ background: 'var(--wa-accent)' }"
                    >
                        Готово
                    </button>
                </div>
            </div>
        </div>

        <!-- Wallpaper picker modal -->
        <div
            v-if="wallpaperPickerOpen"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
            @click.self="wallpaperPickerOpen = false"
        >
            <div
                class="w-[560px] max-w-full rounded-xl shadow-2xl overflow-hidden flex flex-col"
                :style="{ background: 'var(--wa-panel)', maxHeight: '85vh' }"
            >
                <div
                    class="px-6 py-4 flex items-center justify-between border-b"
                    :style="{ borderColor: 'var(--wa-border)' }"
                >
                    <div>
                        <h3 class="text-[17px] font-medium text-[var(--wa-text)]">Выберите обои</h3>
                        <p class="text-xs text-[var(--wa-text-secondary)] mt-0.5">
                            Применяется только для вашего устройства
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="wallpaperPickerOpen = false"
                        class="p-1.5 rounded text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)]"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-6 grid grid-cols-3 sm:grid-cols-4 gap-4 overflow-y-auto wa-scrollbar">
                    <button
                        v-for="wp in wallpapers"
                        :key="wp.id"
                        type="button"
                        @click="pickWallpaper(wp.id)"
                        class="group flex flex-col items-center gap-2 focus:outline-none"
                    >
                        <div
                            class="w-full aspect-square rounded-lg border-2 relative overflow-hidden transition"
                            :class="
                                currentWallpaperId === wp.id
                                    ? 'border-[var(--wa-accent)] shadow-lg'
                                    : 'border-transparent group-hover:border-[var(--wa-border-strong)]'
                            "
                            :style="previewStyle(wp.id)"
                        >
                            <div
                                v-if="currentWallpaperId === wp.id"
                                class="absolute inset-0 flex items-center justify-center"
                                style="background: rgba(0,0,0,0.25)"
                            >
                                <div
                                    class="w-9 h-9 rounded-full flex items-center justify-center"
                                    :style="{ background: 'var(--wa-accent)' }"
                                >
                                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                        <span class="text-xs text-[var(--wa-text)] truncate max-w-full">{{ wp.label }}</span>
                    </button>
                </div>

                <div
                    class="px-6 py-4 flex justify-end border-t"
                    :style="{ borderColor: 'var(--wa-border)' }"
                >
                    <button
                        type="button"
                        @click="wallpaperPickerOpen = false"
                        class="px-6 py-2 rounded-full text-white text-sm font-medium"
                        :style="{ background: 'var(--wa-accent)' }"
                    >
                        Готово
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
</style>
