<script setup lang="ts">
import SectionHeader from './SectionHeader.vue';
import { computed } from 'vue';

const appVersion = '1.0.0';
const buildYear = computed(() => new Date().getFullYear());
</script>

<template>
    <div class="h-full flex flex-col">
        <SectionHeader title="Помощь и отзывы" />

        <div class="flex-1 overflow-y-auto wa-scrollbar py-2">
            <a href="#" class="help-item">
                <div class="help-icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[15px] text-[var(--wa-text)]">Справочный центр</div>
                    <div class="text-xs text-[var(--wa-text-secondary)]">Ответы на популярные вопросы</div>
                </div>
            </a>

            <a href="mailto:support@accel.kz" class="help-item">
                <div class="help-icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8m-18 0v10a2 2 0 002 2h14a2 2 0 002-2V8m-18 0a2 2 0 012-2h14a2 2 0 012 2" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[15px] text-[var(--wa-text)]">Связаться с нами</div>
                    <div class="text-xs text-[var(--wa-text-secondary)]">support@accel.kz</div>
                </div>
            </a>

            <a href="#" class="help-item">
                <div class="help-icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[15px] text-[var(--wa-text)]">Политика конфиденциальности</div>
                    <div class="text-xs text-[var(--wa-text-secondary)]">Как мы защищаем ваши данные</div>
                </div>
            </a>

            <a href="#" class="help-item">
                <div class="help-icon">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-[15px] text-[var(--wa-text)]">Условия использования</div>
                    <div class="text-xs text-[var(--wa-text-secondary)]">Правила сервиса</div>
                </div>
            </a>

            <div class="px-6 py-6 text-xs text-[var(--wa-text-secondary)] text-center">
                Accel · версия {{ appVersion }}
                <br />
                © {{ buildYear }} Все права защищены.
            </div>
        </div>
    </div>
</template>

<style scoped>
.help-item {
    display: flex;
    align-items: center;
    gap: 1.25rem;
    padding: 0.875rem 1.5rem;
    width: 100%;
    transition: background-color 0.15s ease;
}
.help-item:hover {
    background-color: var(--wa-panel-hover);
}
.help-icon {
    width: 2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-icon);
    flex-shrink: 0;
}
</style>
