<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<any>();
const user = computed(() => page.props.auth.user);

function initial(name?: string): string {
    return (name || '?').charAt(0).toUpperCase();
}
</script>

<template>
    <Head title="Статус" />
    <AuthenticatedLayout>
        <div class="flex h-full w-full bg-[var(--wa-bg)]">
            <!-- Status sidebar -->
            <aside class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)] shrink-0">
                <!-- Header -->
                <div class="h-[60px] px-4 pl-6 flex items-center justify-between shrink-0">
                    <h1 class="text-[var(--wa-text)] text-xl font-normal">Статус</h1>
                    <div class="flex items-center gap-1">
                        <button class="wa-icon-btn" title="Добавить статус" type="button">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="9" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v8M8 12h8" />
                            </svg>
                        </button>
                        <button class="wa-icon-btn" title="Меню" type="button">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                                <circle cx="12" cy="5" r="2"/>
                                <circle cx="12" cy="12" r="2"/>
                                <circle cx="12" cy="19" r="2"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto wa-scrollbar">
                    <!-- My status entry -->
                    <button
                        type="button"
                        class="w-full flex items-center px-4 py-3 gap-4 transition my-status"
                    >
                        <div class="relative shrink-0">
                            <div class="w-[52px] h-[52px] rounded-full bg-[#6b7c85] flex items-center justify-center text-white text-lg font-medium">
                                {{ initial(user?.name) }}
                            </div>
                            <div
                                class="absolute -bottom-0.5 -right-0.5 w-[22px] h-[22px] rounded-full flex items-center justify-center border-2"
                                :style="{ background: 'var(--wa-accent)', borderColor: 'var(--wa-panel)' }"
                            >
                                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                                </svg>
                            </div>
                        </div>
                        <div class="text-left min-w-0 flex-1">
                            <div class="text-[15px] text-[var(--wa-text)] truncate">Мой статус</div>
                            <div class="text-xs text-[var(--wa-text-secondary)] truncate">
                                Нажмите, чтобы добавить обновление статуса
                            </div>
                        </div>
                    </button>

                    <!-- E2E note -->
                    <div class="flex items-start gap-2 px-4 py-3 text-xs text-[var(--wa-text-secondary)]">
                        <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                        <span>
                            Обновления ваших статусов
                            <span :style="{ color: 'var(--wa-accent)' }" class="font-medium">защищены сквозным шифрованием</span>
                        </span>
                    </div>
                </div>
            </aside>

            <!-- Empty main area -->
            <div class="flex-1 flex items-center justify-center min-w-0 border-l border-[var(--wa-border)] bg-[var(--wa-empty-bg)]">
                <div class="text-center max-w-sm px-6">
                    <div class="w-24 h-24 rounded-full bg-[var(--wa-panel-header)] flex items-center justify-center mx-auto mb-4">
                        <svg class="w-12 h-12 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9" stroke-dasharray="3 2" />
                            <circle cx="12" cy="12" r="4" fill="currentColor" stroke="none" />
                        </svg>
                    </div>
                    <h3 class="text-[17px] text-[var(--wa-text)] mb-2">Статусы</h3>
                    <p class="text-sm text-[var(--wa-text-secondary)]">
                        Нажмите на свой статус слева, чтобы добавить новое обновление.
                    </p>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.wa-icon-btn {
    width: 2.5rem;
    height: 2.5rem;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-icon);
    transition: background-color 0.15s ease;
}
.wa-icon-btn:hover {
    background-color: var(--wa-panel-hover);
}
.my-status {
    transition: background-color 0.15s ease;
}
.my-status:hover {
    background-color: var(--wa-panel-hover);
}
</style>
