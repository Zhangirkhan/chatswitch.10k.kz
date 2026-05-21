<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    canManageConnections?: boolean;
}>();

const showDemoSidebar = ref(false);
</script>

<template>
    <Head title="Статус" />
    <AuthenticatedLayout>
        <div class="app-page flex-row">
            <aside
                class="flex h-full w-[400px] shrink-0 flex-col border-r bg-[var(--wa-panel)]"
                :style="{ borderColor: 'var(--wa-sidebar-divider)' }"
            >
                <div class="flex h-[60px] shrink-0 items-center justify-between px-4 pl-6">
                    <h1 class="text-xl font-normal text-[var(--wa-text)]">Статус</h1>
                </div>

                <div class="wa-scrollbar flex-1 overflow-y-auto">
                    <div v-if="!showDemoSidebar" class="px-6 py-8 text-center">
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-[var(--wa-panel-header)]">
                            <svg class="h-8 w-8 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke-dasharray="3 2" />
                                <circle cx="12" cy="12" r="3" fill="currentColor" stroke="none" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-medium text-[var(--wa-text)]">Статусы в разработке</h2>
                        <p class="mt-2 text-sm text-[var(--wa-text-secondary)]">
                            Как в WhatsApp: текстовые статусы и просмотр обновлений контактов. Пока используйте чаты и задачи в организации.
                        </p>
                        <div class="mt-6 flex flex-col gap-2">
                            <Link
                                :href="route('chats.index')"
                                class="ui-btn ui-btn--primary ui-btn--pill justify-center"
                            >
                                Открыть чаты
                            </Link>
                            <Link
                                v-if="canManageConnections"
                                :href="route('settings.connections')"
                                class="ui-btn ui-btn--ghost ui-btn--pill justify-center"
                            >
                                Подключения WhatsApp
                            </Link>
                            <button type="button" class="text-sm text-[var(--wa-accent)] hover:underline" @click="showDemoSidebar = true">
                                Показать макет боковой панели
                            </button>
                        </div>
                    </div>

                    <div v-else class="px-4 py-4">
                        <p class="ui-alert ui-alert--warn mb-3 text-center text-xs">Демо: макет интерфейса, не рабочие статусы.</p>
                        <button type="button" class="mx-auto mb-4 block text-sm text-[var(--wa-accent)] hover:underline" @click="showDemoSidebar = false">
                            Скрыть демо
                        </button>
                        <div class="ui-result-card text-left text-sm text-[var(--wa-text-secondary)]">
                            Здесь позже появится список статусов контактов и ваш черновик статуса.
                        </div>
                    </div>
                </div>
            </aside>

            <div class="flex min-w-0 flex-1 items-center justify-center border-l border-[var(--wa-border)] bg-[var(--wa-empty-bg)]">
                <div class="max-w-md px-6 text-center">
                    <div class="mx-auto mb-4 flex h-24 w-24 items-center justify-center rounded-full bg-[var(--wa-panel-header)]">
                        <svg class="h-12 w-12 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l2.5 2.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-[17px] text-[var(--wa-text)]">Нет выбранного статуса</h3>
                    <p class="text-sm text-[var(--wa-text-secondary)]">
                        {{ showDemoSidebar ? 'Это заглушка области просмотра.' : 'Когда функция появится, здесь будет предпросмотр и ответы на статусы.' }}
                    </p>
                    <Link
                        v-if="!showDemoSidebar"
                        :href="route('chats.index')"
                        class="ui-btn ui-btn--primary ui-btn--pill mt-5"
                    >
                        Перейти в чаты
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
