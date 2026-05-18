<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';

type SampleChannel = {
    id: string;
    name: string;
    subscribers: string;
    color: string;
    initial: string;
    verified: boolean;
};

defineProps<{
    canManageConnections?: boolean;
}>();

const sampleChannels: SampleChannel[] = [
    { id: '1', name: 'AviaTravel.kz 🔥 Горящие туры', subscribers: '42 тыс. подписчиков', color: '#d43838', initial: 'A', verified: true },
    { id: '2', name: 'AviaTravel.kz 🏝 Горящие авиабилеты', subscribers: '16 тыс. подписчиков', color: '#d43838', initial: 'A', verified: true },
    { id: '3', name: 'Орыс тілін оңай үйрен!', subscribers: '106 тыс. подписчиков', color: '#01b964', initial: 'О', verified: true },
];

const showExamples = ref(false);
const subscribed = ref<Record<string, boolean>>({});

function toggleSubscribe(id: string) {
    subscribed.value[id] = !subscribed.value[id];
}
</script>

<template>
    <Head title="Каналы" />
    <AuthenticatedLayout>
        <div class="flex h-full w-full bg-[var(--wa-bg)]">
            <aside
                class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)] shrink-0 border-r"
                :style="{ borderColor: 'var(--wa-sidebar-divider)' }"
            >
                <div class="h-[60px] px-4 pl-6 flex items-center justify-between shrink-0">
                    <h1 class="text-[var(--wa-text)] text-xl font-normal">Каналы</h1>
                </div>

                <div class="flex-1 overflow-y-auto wa-scrollbar">
                    <div v-if="!showExamples" class="px-6 py-8 text-center">
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-[var(--wa-panel-header)]">
                            <svg class="h-8 w-8 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 12a8 8 0 10-14.93 4L4 20l4.07-1.07A8 8 0 0020 12z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-medium text-[var(--wa-text)]">Каналы пока не подключены</h2>
                        <p class="mt-2 text-sm text-[var(--wa-text-secondary)]">
                            Раздел готовится к интеграции с WhatsApp Channels. Сейчас работайте с диалогами через подключённые номера.
                        </p>
                        <div class="mt-6 flex flex-col gap-2">
                            <Link
                                :href="route('chats.index')"
                                class="inline-flex justify-center rounded-full bg-[var(--wa-accent)] px-4 py-2.5 text-sm font-medium text-white hover:opacity-95"
                            >
                                Открыть чаты
                            </Link>
                            <Link
                                v-if="canManageConnections"
                                :href="route('settings.connections')"
                                class="inline-flex justify-center rounded-full border border-[var(--wa-border-strong)] px-4 py-2.5 text-sm text-[var(--wa-text)] hover:bg-[var(--wa-panel-hover)]"
                            >
                                Подключить WhatsApp
                            </Link>
                            <button type="button" class="text-sm text-[var(--wa-accent)] hover:underline" @click="showExamples = true">
                                Показать пример интерфейса
                            </button>
                        </div>
                    </div>

                    <template v-else>
                        <div class="px-6 pt-4 pb-2">
                            <p class="text-center text-xs text-amber-500">Демо: примеры каналов, не реальные подписки.</p>
                            <button type="button" class="mx-auto mt-2 block text-sm text-[var(--wa-accent)] hover:underline" @click="showExamples = false">
                                Скрыть примеры
                            </button>
                        </div>
                        <div class="px-3 py-2 space-y-1">
                            <div
                                v-for="channel in sampleChannels"
                                :key="channel.id"
                                class="flex items-center px-3 py-2 gap-3"
                            >
                                <div
                                    class="w-[52px] h-[52px] rounded-full flex items-center justify-center text-white text-xl font-semibold shrink-0"
                                    :style="{ background: channel.color }"
                                >
                                    {{ channel.initial }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="text-[15px] text-[var(--wa-text)] truncate">{{ channel.name }}</div>
                                    <div class="text-xs text-[var(--wa-text-secondary)] truncate">{{ channel.subscribers }}</div>
                                </div>
                                <button
                                    type="button"
                                    class="subscribe-btn shrink-0"
                                    :class="{ 'subscribe-btn-active': subscribed[channel.id] }"
                                    @click="toggleSubscribe(channel.id)"
                                >
                                    {{ subscribed[channel.id] ? 'Подписаны' : 'Подписаться' }}
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </aside>

            <div class="flex-1 flex items-center justify-center min-w-0 bg-[var(--wa-empty-bg)]">
                <div class="text-center max-w-md px-6">
                    <div class="w-24 h-24 rounded-full bg-[var(--wa-panel-header)] flex items-center justify-center mx-auto mb-4">
                        <svg class="w-12 h-12 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 12a8 8 0 10-14.93 4L4 20l4.07-1.07A8 8 0 0020 12z" />
                        </svg>
                    </div>
                    <h3 class="text-[17px] text-[var(--wa-text)] mb-2">Новости каналов</h3>
                    <p class="text-sm text-[var(--wa-text-secondary)]">
                        {{ showExamples ? 'Выберите пример канала слева.' : 'Подключите WhatsApp и ведите клиентов в чатах — ответы и AI уже доступны там.' }}
                    </p>
                    <Link
                        v-if="!showExamples"
                        :href="route('chats.index')"
                        class="mt-5 inline-flex rounded-full bg-[var(--wa-accent)] px-5 py-2.5 text-sm font-medium text-white hover:opacity-95"
                    >
                        Перейти в чаты
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.subscribe-btn {
    padding: 0.375rem 1rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--wa-accent);
    background-color: var(--wa-accent-soft);
    transition: filter 0.15s ease;
}
.subscribe-btn:hover {
    filter: brightness(0.95);
}
.subscribe-btn-active {
    color: var(--wa-text-secondary);
    background-color: var(--wa-panel-header);
}
</style>
