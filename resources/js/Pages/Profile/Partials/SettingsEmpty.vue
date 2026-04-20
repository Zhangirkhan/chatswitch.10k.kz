<script setup lang="ts">
// Main-area placeholder shown next to any settings sub-section,
// mirroring WhatsApp Web's "download WhatsApp for Mac" card layout.
</script>

<template>
    <div class="flex-1 flex items-center justify-center min-w-0 border-l border-[var(--wa-border)] bg-[var(--wa-empty-bg)] px-6">
        <div class="flex flex-col items-center">
            <div class="w-[320px] rounded-2xl bg-[var(--wa-panel)] shadow-md p-6 text-center">
                <svg class="w-28 h-20 mx-auto mb-4" viewBox="0 0 140 96" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect x="8" y="8" width="108" height="68" rx="6" fill="var(--wa-panel-header)" stroke="var(--wa-border-strong)" stroke-width="2"/>
                    <rect x="36" y="78" width="52" height="4" rx="2" fill="var(--wa-border-strong)"/>
                    <circle cx="98" cy="54" r="22" fill="var(--wa-accent-soft)"/>
                    <path d="M90 46c0-2.21 1.79-4 4-4h8c2.21 0 4 1.79 4 4v16c0 2.21-1.79 4-4 4h-8c-2.21 0-4-1.79-4-4v-16z" :fill="'var(--wa-accent)'"/>
                    <path d="M95 51c0-.55.45-1 1-1h4c.55 0 1 .45 1 1v6c0 .55-.45 1-1 1h-4c-.55 0-1-.45-1-1v-6z" fill="#fff"/>
                </svg>
                <h3 class="text-[17px] text-[var(--wa-text)] mb-2">
                    ChatSwitch работает в браузере
                </h3>
                <p class="text-xs text-[var(--wa-text-secondary)] mb-4 leading-relaxed">
                    Все ваши чаты синхронизируются автоматически. Держите вкладку открытой для получения сообщений.
                </p>
            </div>

            <div class="mt-8 flex items-center gap-8">
                <div class="flex flex-col items-center gap-2">
                    <div class="w-16 h-16 rounded-xl bg-[var(--wa-panel-header)] flex items-center justify-center">
                        <svg class="w-7 h-7 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <div class="text-xs text-[var(--wa-text-secondary)]">Отправить документ</div>
                </div>
                <div class="flex flex-col items-center gap-2">
                    <div class="w-16 h-16 rounded-xl bg-[var(--wa-panel-header)] flex items-center justify-center">
                        <svg class="w-7 h-7 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                    </div>
                    <div class="text-xs text-[var(--wa-text-secondary)]">Добавить контакт</div>
                </div>
            </div>
        </div>
    </div>
</template>
