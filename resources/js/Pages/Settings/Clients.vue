<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head, router, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { formatPhone } from '@/utils/phone';
import { useToastStore } from '@/stores/toast';

type ClientItem = {
    id: number;
    whatsapp_id: string | null;
    phone_number: string | null;
    name: string | null;
    push_name: string | null;
    profile_picture_url: string | null;
    chats_count: number;
    last_chat_name: string | null;
    last_chat_at: string | null;
    channels: Array<{
        chat_id: number;
        session_id: number | null;
        session_label: string;
        session_phone: string | null;
        chat_name: string | null;
        last_message_at: string | null;
    }>;
};

const props = defineProps<{
    search: string;
    clients: ClientItem[];
}>();
const { show: showToast } = useToastStore();

const search = ref(props.search || '');
const clients = ref<ClientItem[]>([...props.clients]);
const openClientId = ref<number | null>(null);
const editingName = ref('');
const saving = ref(false);

watch(
    () => props.clients,
    (next) => {
        clients.value = [...next];
    },
);

let timer: ReturnType<typeof setTimeout> | null = null;
watch(search, (q) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        router.get(route('settings.clients'), { search: q || undefined }, { preserveState: true, replace: true });
    }, 250);
});

function displayName(c: ClientItem): string {
    return (
        (c.name || '').trim() ||
        (c.push_name || '').trim() ||
        (c.last_chat_name || '').trim() ||
        formatPhone(c.phone_number) ||
        'Без имени'
    );
}

function waIdLabel(c: ClientItem): string {
    const wa = (c.whatsapp_id || '').trim();
    return wa !== '' ? wa : '—';
}

function lastChatLabel(c: ClientItem): string {
    const n = (c.last_chat_name || '').trim();
    return n !== '' ? n : '—';
}

function dateLabel(v: string | null): string {
    if (!v) return '—';
    const d = new Date(v);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

const total = computed(() => clients.value.length);
const openedClient = computed(() => clients.value.find((c) => c.id === openClientId.value) || null);

function openClient(c: ClientItem): void {
    openClientId.value = c.id;
    editingName.value = (c.name || '').trim();
}

function closeClient(): void {
    openClientId.value = null;
    editingName.value = '';
}

async function saveClientName(): Promise<void> {
    if (!openedClient.value || saving.value) return;
    saving.value = true;
    try {
        const name = editingName.value.trim();
        const { data } = await axios.patch(route('contacts.update', openedClient.value.id), { name });
        if (data?.success) {
            const newChatName =
                name !== ''
                    ? name
                    : (data?.contact?.push_name ?? openedClient.value.push_name ?? openedClient.value.phone_number ?? null);

            const idx = clients.value.findIndex((c) => c.id === openedClient.value!.id);
            if (idx !== -1) {
                clients.value[idx] = {
                    ...clients.value[idx],
                    name: name !== '' ? name : null,
                };
            }

            // Update modal channels immediately: UI shows `ch.chat_name || displayName(openedClient)`.
            openedClient.value.channels = (openedClient.value.channels || []).map((ch) => ({
                ...ch,
                chat_name: newChatName,
            }));

            showToast({ message: 'Имя клиента обновлено' });
            return;
        }
        showToast({ message: data?.error || 'Не удалось обновить имя' });
    } catch (e: any) {
        const msg = e?.response?.data?.message || e?.message || 'Не удалось обновить имя';
        showToast({ message: msg });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Head title="Клиенты" />

    <SettingsLayout title="Клиенты" subtitle="Список клиентов и их сведения">
        <div class="p-6">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div class="text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                    Найдено: {{ total }}
                </div>
                <input
                    v-model="search"
                    type="text"
                    placeholder="Поиск по имени, номеру, WhatsApp ID"
                    class="w-full max-w-[360px] rounded-full border-0 px-4 py-2 text-sm focus:ring-0 focus:outline-none"
                    :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                />
            </div>

            <div class="space-y-3">
                <div
                    v-for="c in clients"
                    :key="c.id"
                    class="rounded-2xl border p-4"
                    :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
                >
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="truncate text-[15px]" :style="{ color: 'var(--wa-text)' }">
                                {{ displayName(c) }}
                            </div>
                            <div class="truncate text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                                {{ formatPhone(c.phone_number) || '—' }}
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                                Чатов: {{ c.chats_count }}
                            </div>
                            <button
                                type="button"
                                class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                                :style="{ color: 'var(--wa-text)' }"
                                title="Редактировать"
                                aria-label="Редактировать"
                                @click.stop="openClient(c)"
                            >
                                ✎
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2">
                        <div :style="{ color: 'var(--wa-text-secondary)' }">
                            <span :style="{ color: 'var(--wa-text)' }">Сохранённое имя:</span> {{ c.name || '—' }}
                        </div>
                        <div :style="{ color: 'var(--wa-text-secondary)' }">
                            <span :style="{ color: 'var(--wa-text)' }">WhatsApp ник:</span> {{ c.push_name || '—' }}
                        </div>
                        <div class="sm:col-span-2 truncate" :style="{ color: 'var(--wa-text-secondary)' }">
                            <span :style="{ color: 'var(--wa-text)' }">WhatsApp ID:</span> {{ waIdLabel(c) }}
                        </div>
                        <div class="truncate" :style="{ color: 'var(--wa-text-secondary)' }">
                            <span :style="{ color: 'var(--wa-text)' }">Последний чат:</span> {{ lastChatLabel(c) }}
                        </div>
                        <div :style="{ color: 'var(--wa-text-secondary)' }">
                            <span :style="{ color: 'var(--wa-text)' }">Последняя активность:</span> {{ dateLabel(c.last_chat_at) }}
                        </div>
                    </div>

                    <div v-if="c.channels.length" class="mt-3 border-t pt-3 text-xs" :style="{ borderColor: 'var(--wa-border)' }">
                        <div class="mb-1.5" :style="{ color: 'var(--wa-text-secondary)' }">Писал на номера:</div>
                        <div class="space-y-1.5">
                            <div
                                v-for="ch in c.channels"
                                :key="`ch-${c.id}-${ch.chat_id}`"
                                class="flex items-center justify-between gap-2 rounded-md px-2 py-1"
                                :style="{ background: 'var(--wa-panel-header)' }"
                            >
                                <div class="min-w-0">
                                    <div class="truncate" :style="{ color: 'var(--wa-text)' }">
                                        {{ ch.session_label }}<span v-if="ch.session_phone"> · {{ formatPhone(ch.session_phone) }}</span>
                                    </div>
                                    <div class="truncate" :style="{ color: 'var(--wa-text-secondary)' }">
                                        {{ ch.chat_name || displayName(c) }}
                                    </div>
                                </div>
                                <div class="shrink-0" :style="{ color: 'var(--wa-text-secondary)' }">
                                    {{ dateLabel(ch.last_message_at) }}
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <div v-if="clients.length === 0" class="py-10 text-center text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                    Клиенты не найдены
                </div>
            </div>
        </div>

        <teleport to="body">
            <div
                v-if="openedClient"
                class="fixed inset-0 z-[450] flex items-center justify-center px-4"
                :style="{ background: 'rgba(0,0,0,.45)' }"
            >
                <div class="w-full max-w-[520px] rounded-2xl border overflow-hidden" :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }">
                    <div class="px-5 py-4 flex items-center justify-between" :style="{ background: 'var(--wa-panel-header)' }">
                        <div class="text-sm font-medium" :style="{ color: 'var(--wa-text)' }">
                            Клиент: {{ displayName(openedClient) }}
                        </div>
                        <button type="button" class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]" @click="closeClient">
                            ✕
                        </button>
                    </div>

                    <div class="p-5 space-y-4 text-sm">
                        <div>
                            <div class="mb-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">Сохранённое имя</div>
                            <input
                                v-model="editingName"
                                type="text"
                                class="w-full rounded-lg border-0 px-3 py-2 focus:ring-0 focus:outline-none"
                                :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                                placeholder="Введите имя клиента"
                            />
                        </div>

                        <div class="grid grid-cols-1 gap-2 text-xs sm:grid-cols-2" :style="{ color: 'var(--wa-text-secondary)' }">
                            <div><span :style="{ color: 'var(--wa-text)' }">WhatsApp ник:</span> {{ openedClient.push_name || '—' }}</div>
                            <div><span :style="{ color: 'var(--wa-text)' }">Телефон:</span> {{ formatPhone(openedClient.phone_number) || '—' }}</div>
                            <div class="sm:col-span-2 truncate"><span :style="{ color: 'var(--wa-text)' }">WhatsApp ID:</span> {{ waIdLabel(openedClient) }}</div>
                            <div><span :style="{ color: 'var(--wa-text)' }">Последний чат:</span> {{ lastChatLabel(openedClient) }}</div>
                            <div><span :style="{ color: 'var(--wa-text)' }">Последняя активность:</span> {{ dateLabel(openedClient.last_chat_at) }}</div>
                        </div>

                        <div v-if="openedClient.channels.length" class="border-t pt-3" :style="{ borderColor: 'var(--wa-border)' }">
                            <div class="mb-2 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">Каналы общения (на какой номер писал):</div>
                            <div class="space-y-2 text-xs">
                                <div
                                    v-for="ch in openedClient.channels"
                                    :key="`open-${openedClient.id}-${ch.chat_id}`"
                                    class="rounded-md px-2 py-1.5 flex items-center justify-between gap-3"
                                    :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text-secondary)' }"
                                >
                                    <div class="min-w-0">
                                        <div :style="{ color: 'var(--wa-text)' }">
                                            {{ ch.session_label }}<span v-if="ch.session_phone"> · {{ formatPhone(ch.session_phone) }}</span>
                                        </div>
                                        <div class="truncate">
                                            Имя в чате: {{ ch.chat_name || displayName(openedClient) }}
                                        </div>
                                    </div>
                                    <div class="flex flex-col items-end gap-1 shrink-0">
                                        <div :style="{ color: 'var(--wa-text-secondary)', fontSize: '11px' }">
                                            {{ dateLabel(ch.last_message_at) }}
                                        </div>
                                        <Link
                                            :href="route('chats.show', ch.chat_id)"
                                            class="rounded-lg px-2 py-1 text-[11px] hover:bg-[var(--wa-panel-hover)]"
                                            :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }"
                                        >
                                            Перейти к чату
                                        </Link>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-2">
                            <button type="button" class="px-4 py-2 rounded-xl hover:bg-[var(--wa-panel-hover)]" :style="{ color: 'var(--wa-text)' }" @click="closeClient">
                                Закрыть
                            </button>
                            <button
                                type="button"
                                class="px-4 py-2 rounded-xl"
                                :disabled="saving"
                                :style="{ background: 'var(--wa-accent)', color: '#fff', opacity: saving ? 0.6 : 1 }"
                                @click="saveClientName"
                            >
                                Сохранить
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </teleport>
    </SettingsLayout>
</template>
