<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

type SampleChannel = {
    id: string;
    name: string;
    subscribers: string;
    color: string;
    initial: string;
    verified: boolean;
};

const sampleChannels: SampleChannel[] = [
    { id: '1', name: 'AviaTravel.kz 🔥 Горящие туры', subscribers: '42 тыс. подписчиков', color: '#d43838', initial: 'A', verified: true },
    { id: '2', name: 'AviaTravel.kz 🏝 Горящие авиабилеты', subscribers: '16 тыс. подписчиков', color: '#d43838', initial: 'A', verified: true },
    { id: '3', name: 'Орыс тілін оңай үйрен!', subscribers: '106 тыс. подписчиков', color: '#3b82f6', initial: 'О', verified: true },
    { id: '4', name: 'Azattyq — Азаттық', subscribers: '92 тыс. подписчиков', color: '#f97316', initial: 'A', verified: true },
    { id: '5', name: 'Гатауллина Бакыт "GLOW"', subscribers: '160 подписчиков', color: '#8b5cf6', initial: 'Г', verified: true },
];

const subscribed = ref<Record<string, boolean>>({});

function toggleSubscribe(id: string) {
    subscribed.value[id] = !subscribed.value[id];
}
</script>

<template>
    <Head title="Каналы" />
    <AuthenticatedLayout>
        <div class="flex h-full w-full bg-[var(--wa-bg)]">
            <!-- Channels sidebar -->
            <aside class="w-[400px] h-full flex flex-col bg-[var(--wa-panel)] shrink-0">
                <!-- Header -->
                <div class="h-[60px] px-4 pl-6 flex items-center justify-between shrink-0">
                    <h1 class="text-[var(--wa-text)] text-xl font-normal">Каналы</h1>
                    <button class="wa-icon-btn" title="Создать канал" type="button">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="9" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v8M8 12h8" />
                        </svg>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto wa-scrollbar">
                    <!-- Intro -->
                    <div class="px-6 pt-4 pb-2">
                        <h2 class="text-[22px] leading-tight text-[var(--wa-text)] font-semibold text-center mb-3">
                            Не пропускайте новости на интересные темы
                        </h2>
                        <p class="text-sm text-[var(--wa-text-secondary)] text-center">
                            Примеры каналов, на которые вы можете подписаться
                        </p>
                    </div>

                    <!-- Channel list -->
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
                                <div class="flex items-center gap-1.5">
                                    <div class="text-[15px] text-[var(--wa-text)] truncate">
                                        {{ channel.name }}
                                    </div>
                                    <svg
                                        v-if="channel.verified"
                                        class="w-3.5 h-3.5 shrink-0"
                                        viewBox="0 0 24 24"
                                        fill="#3b82f6"
                                    >
                                        <path d="M12 2l2.39 2.32 3.32-.39.56 3.3 2.93 1.61-1.36 3.06 1.36 3.05-2.93 1.61-.56 3.3-3.32-.39L12 22l-2.39-2.32-3.32.39-.56-3.3-2.93-1.61 1.36-3.05-1.36-3.06 2.93-1.61.56-3.3 3.32.39L12 2z"/>
                                        <path d="M10.5 14.5l-2.8-2.8 1.4-1.4 1.4 1.4 3.5-3.5 1.4 1.4-4.9 4.9z" fill="white"/>
                                    </svg>
                                </div>
                                <div class="text-xs text-[var(--wa-text-secondary)] truncate">
                                    {{ channel.subscribers }}
                                </div>
                            </div>
                            <button
                                type="button"
                                @click="toggleSubscribe(channel.id)"
                                class="subscribe-btn shrink-0"
                                :class="{ 'subscribe-btn-active': subscribed[channel.id] }"
                            >
                                {{ subscribed[channel.id] ? 'Подписаны' : 'Подписаться' }}
                            </button>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="px-4 py-3 space-y-2">
                        <button type="button" class="outline-btn">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h5v5H4zM15 6h5v5h-5zM4 13h5v5H4zM15 13h5v5h-5z" />
                            </svg>
                            <span>Другие каналы</span>
                        </button>
                        <button type="button" class="outline-btn">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                            </svg>
                            <span>Создать канал</span>
                        </button>
                    </div>
                </div>
            </aside>

            <!-- Empty main area -->
            <div class="flex-1 flex items-center justify-center min-w-0 border-l border-[var(--wa-border)] bg-[var(--wa-empty-bg)]">
                <div class="text-center max-w-sm px-6">
                    <div class="w-24 h-24 rounded-full bg-[var(--wa-panel-header)] flex items-center justify-center mx-auto mb-4">
                        <svg class="w-12 h-12 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 12a8 8 0 10-14.93 4L4 20l4.07-1.07A8 8 0 0020 12z" />
                            <circle cx="8.5" cy="12" r="1" fill="currentColor" stroke="none" />
                            <circle cx="12" cy="12" r="1" fill="currentColor" stroke="none" />
                            <circle cx="15.5" cy="12" r="1" fill="currentColor" stroke="none" />
                        </svg>
                    </div>
                    <h3 class="text-[17px] text-[var(--wa-text)] mb-2">Каналы</h3>
                    <p class="text-sm text-[var(--wa-text-secondary)]">
                        Выберите канал слева, чтобы прочитать обновления.
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
.outline-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.625rem 1rem;
    border-radius: 9999px;
    font-size: 0.875rem;
    color: var(--wa-accent);
    background: transparent;
    border: 1px solid var(--wa-border-strong);
    transition: background-color 0.15s ease;
}
.outline-btn:hover {
    background-color: var(--wa-panel-hover);
}
</style>
