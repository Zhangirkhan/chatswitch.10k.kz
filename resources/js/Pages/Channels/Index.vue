<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useI18n } from '@/composables/useI18n';
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

const { t } = useI18n();

const sampleChannels: SampleChannel[] = [
    { id: '1', name: 'AviaTravel.kz 🔥 Горящие туры', subscribers: '42 тыс. подписчиков', color: '#d43838', initial: 'A', verified: true },
    { id: '2', name: 'AviaTravel.kz 🏝 Горящие авиабилеты', subscribers: '16 тыс. подписчиков', color: '#d43838', initial: 'A', verified: true },
    { id: '3', name: 'Орыс тілін оңай үйрен!', subscribers: '106 тыс. подписчиков', color: '#01b964', initial: 'О', verified: true },
];

const showExamples = ref(false);
const subscribed = ref<Record<string, boolean>>({});

function toggleSubscribe(id: string): void {
    subscribed.value[id] = !subscribed.value[id];
}
</script>

<template>
    <Head :title="t('misc.channelsTitle')" />
    <AuthenticatedLayout>
        <div class="app-page flex-row">
            <aside
                class="flex h-full w-[400px] shrink-0 flex-col border-r bg-[var(--wa-panel)]"
                :style="{ borderColor: 'var(--wa-sidebar-divider)' }"
            >
                <div class="flex h-[60px] shrink-0 items-center justify-between px-4 pl-6">
                    <h1 class="text-xl font-normal text-[var(--wa-text)]">{{ t('misc.channelsTitle') }}</h1>
                </div>

                <div class="wa-scrollbar flex-1 overflow-y-auto">
                    <div v-if="!showExamples" class="px-6 py-8 text-center">
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-[var(--wa-panel-header)]">
                            <svg class="h-8 w-8 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 12a8 8 0 10-14.93 4L4 20l4.07-1.07A8 8 0 0020 12z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-medium text-[var(--wa-text)]">{{ t('misc.channelsNotConnected') }}</h2>
                        <p class="mt-2 text-sm text-[var(--wa-text-secondary)]">
                            {{ t('misc.channelsNotConnectedDesc') }}
                        </p>
                        <div class="mt-6 flex flex-col gap-2">
                            <Link
                                :href="route('chats.index')"
                                class="ui-btn ui-btn--primary ui-btn--pill justify-center"
                            >
                                {{ t('misc.openChats') }}
                            </Link>
                            <Link
                                v-if="canManageConnections"
                                :href="route('settings.connections')"
                                class="ui-btn ui-btn--ghost ui-btn--pill justify-center"
                            >
                                {{ t('misc.channelsConnectWhatsapp') }}
                            </Link>
                            <button type="button" class="text-sm text-[var(--wa-accent)] hover:underline" @click="showExamples = true">
                                {{ t('misc.channelsShowDemo') }}
                            </button>
                        </div>
                    </div>

                    <template v-else>
                        <div class="px-6 pt-4 pb-2">
                            <p class="ui-alert ui-alert--warn text-center text-xs">{{ t('misc.channelsDemoWarning') }}</p>
                            <button type="button" class="mx-auto mt-2 block text-sm text-[var(--wa-accent)] hover:underline" @click="showExamples = false">
                                {{ t('misc.channelsHideDemo') }}
                            </button>
                        </div>
                        <div class="space-y-1 px-3 py-2">
                            <div
                                v-for="channel in sampleChannels"
                                :key="channel.id"
                                class="flex items-center gap-3 px-3 py-2"
                            >
                                <div
                                    class="flex h-[52px] w-[52px] shrink-0 items-center justify-center rounded-full text-xl font-semibold text-white"
                                    :style="{ background: channel.color }"
                                >
                                    {{ channel.initial }}
                                </div>
                                <div class="min-w-0 flex-1">
                                    <div class="truncate text-[15px] text-[var(--wa-text)]">{{ channel.name }}</div>
                                    <div class="truncate text-xs text-[var(--wa-text-secondary)]">{{ channel.subscribers }}</div>
                                </div>
                                <button
                                    type="button"
                                    class="ui-btn ui-btn--sm ui-btn--pill shrink-0"
                                    :class="subscribed[channel.id] ? 'ui-btn--secondary' : 'ui-btn--accent-soft'"
                                    @click="toggleSubscribe(channel.id)"
                                >
                                    {{ subscribed[channel.id] ? t('misc.channelsSubscribed') : t('misc.channelsSubscribe') }}
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </aside>

            <div class="flex min-w-0 flex-1 items-center justify-center bg-[var(--wa-empty-bg)]">
                <div class="max-w-md px-6 text-center">
                    <div class="mx-auto mb-4 flex h-24 w-24 items-center justify-center rounded-full bg-[var(--wa-panel-header)]">
                        <svg class="h-12 w-12 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M20 12a8 8 0 10-14.93 4L4 20l4.07-1.07A8 8 0 0020 12z" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-[17px] text-[var(--wa-text)]">{{ t('misc.channelsSelect') }}</h3>
                    <p class="text-sm text-[var(--wa-text-secondary)]">
                        {{ showExamples ? t('misc.channelsSelect') : t('misc.channelsNotConnectedDesc') }}
                    </p>
                    <Link
                        v-if="!showExamples"
                        :href="route('chats.index')"
                        class="ui-btn ui-btn--primary ui-btn--pill mt-5"
                    >
                        {{ t('misc.openChats') }}
                    </Link>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
