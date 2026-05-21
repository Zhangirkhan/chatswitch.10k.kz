<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import { useTheme } from '@/composables/useTheme';
import { useChatBackground } from '@/composables/useChatBackground';
import {
    useTranslationLang,
    TRANSLATION_LANG_OPTIONS,
    type TranslationLang,
} from '@/composables/useTranslationLang';
import { wallpaperPreview } from '@/config/wallpapers';
import { computed, ref } from 'vue';

const { theme, set: setTheme } = useTheme();
const { wallpapers, currentWallpaperId, setWallpaper, getCurrent } = useChatBackground();
const { lang: translateLang } = useTranslationLang();

const wallpaperLabel = computed(() => getCurrent().label);
const wallpaperPickerOpen = ref(false);
const isDarkTheme = computed(() => theme.value === 'dark');

function pickWallpaper(id: string) {
    setWallpaper(id);
}

function previewStyle(id: string): string {
    const wp = wallpapers.find((w) => w.id === id);
    if (!wp) return '';
    return `background: ${wallpaperPreview(wp, theme.value)}; background-size: ${wp.tileSize || (wp.kind === 'default' ? '80px' : 'cover')};`;
}

const currentWallpaperPreview = computed(() => previewStyle(currentWallpaperId.value));

function isDefaultLang(value: TranslationLang): boolean {
    return value === 'ru' || value === 'kk';
}
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader title="Чаты" />

        <div class="flex-1 overflow-y-auto wa-scrollbar chats-settings">
            <section class="ui-settings-section chats-settings__section">
                <h3 class="ui-settings-block-title">Отображение</h3>

                <div class="chats-settings__field">
                    <span class="ui-settings-field-label" id="theme-label">Тема интерфейса</span>
                    <div
                        class="ui-theme-switch"
                        :class="{ 'is-dark': isDarkTheme }"
                        role="group"
                        aria-labelledby="theme-label"
                    >
                        <span class="ui-theme-switch__thumb" aria-hidden="true" />
                        <button
                            type="button"
                            class="ui-theme-switch__option"
                            :class="{ 'is-active': theme === 'light' }"
                            :aria-pressed="theme === 'light'"
                            @click="setTheme('light')"
                        >
                            <svg class="ui-theme-switch__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <circle cx="12" cy="12" r="4" />
                                <path stroke-linecap="round" d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
                            </svg>
                            Светлая
                        </button>
                        <button
                            type="button"
                            class="ui-theme-switch__option"
                            :class="{ 'is-active': theme === 'dark' }"
                            :aria-pressed="theme === 'dark'"
                            @click="setTheme('dark')"
                        >
                            <svg class="ui-theme-switch__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
                            </svg>
                            Тёмная
                        </button>
                    </div>
                </div>

                <div class="chats-settings__field">
                    <span class="ui-settings-field-label">Обои чата</span>
                    <button
                        type="button"
                        class="ui-settings-pick-card"
                        @click="wallpaperPickerOpen = true"
                    >
                        <div
                            class="ui-settings-pick-card__preview"
                            :style="currentWallpaperPreview"
                            aria-hidden="true"
                        />
                        <div class="flex-1 min-w-0">
                            <div class="text-[15px] text-[var(--wa-text)] truncate">{{ wallpaperLabel }}</div>
                            <div class="text-xs text-[var(--wa-text-secondary)] mt-0.5">Нажмите, чтобы выбрать фон</div>
                        </div>
                        <svg class="ui-settings-pick-card__chevron w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>
            </section>

            <section class="ui-settings-section chats-settings__section">
                <h3 class="ui-settings-block-title">Перевод сообщений</h3>
                <p class="ui-settings-block-hint">
                    Выберите язык — под входящими сообщениями появится кнопка «Перевести». «Выкл» отключает перевод.
                </p>

                <div class="ui-lang-grid" role="listbox" aria-label="Язык перевода">
                    <button
                        v-for="opt in TRANSLATION_LANG_OPTIONS"
                        :key="opt.value"
                        type="button"
                        role="option"
                        class="ui-lang-chip"
                        :class="{
                            'is-active': translateLang === opt.value,
                            'is-off': opt.value === 'off',
                        }"
                        :aria-selected="translateLang === opt.value"
                        @click="translateLang = opt.value"
                    >
                        <span class="ui-lang-chip__flag" aria-hidden="true">{{ opt.flag }}</span>
                        <span>{{ opt.label }}</span>
                        <span
                            v-if="isDefaultLang(opt.value)"
                            class="ui-lang-chip__badge"
                        >основной</span>
                    </button>
                </div>
            </section>
        </div>

        <!-- Wallpaper picker modal -->
        <UiModal
            :open="wallpaperPickerOpen"
            title="Выберите обои"
            subtitle="Применяется только для вашего устройства"
            max-width="lg"
            body-class="p-6"
            @close="wallpaperPickerOpen = false"
        >
            <div class="grid grid-cols-3 sm:grid-cols-4 gap-4">
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
                                : 'border-transparent group-hover:border-[var(--wa-control-rim)]'
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

            <template #footer>
                <button
                    type="button"
                    @click="wallpaperPickerOpen = false"
                    class="px-6 py-2 rounded-full text-white text-sm font-medium"
                    :style="{ background: 'var(--wa-accent)' }"
                >
                    Готово
                </button>
            </template>
        </UiModal>
    </div>
</template>

<style scoped>
.chats-settings {
    padding: 0 1rem 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.chats-settings__section {
    padding: 14px 16px;
}

.chats-settings__field + .chats-settings__field {
    margin-top: 16px;
}
</style>
