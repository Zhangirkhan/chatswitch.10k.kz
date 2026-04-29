<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import type { Contact, Message } from '@/types';
import { formatPhone } from '@/utils/phone';

const props = defineProps<{
    open: boolean;
    message?: Message;
    messageIds?: number[];
    whatsappSessionId: number;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
    (e: 'sent', count: number): void;
}>();

const q = ref('');
const loading = ref(false);
const contacts = ref<Contact[]>([]);
const selected = ref<Set<number>>(new Set());
const sending = ref(false);
const error = ref<string | null>(null);

function contactLabel(c: Contact): string {
    return c.name || (c.push_name ? `~ ${c.push_name}` : '') || formatPhone(c.phone_number) || '';
}

async function loadContacts(): Promise<void> {
    loading.value = true;
    try {
        const { data } = await axios.get(route('chats.contacts'), { params: { search: q.value || undefined } });
        contacts.value = (data.contacts || []) as Contact[];
    } finally {
        loading.value = false;
    }
}

let t: ReturnType<typeof setTimeout> | null = null;
watch(q, () => {
    if (t) clearTimeout(t);
    t = setTimeout(() => void loadContacts(), 250);
});

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        q.value = '';
        selected.value = new Set();
        error.value = null;
        void loadContacts();
    },
);

const selectedCount = computed(() => selected.value.size);

function toggleContact(id: number) {
    const next = new Set(selected.value);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    selected.value = next;
}

async function send(): Promise<void> {
    if (sending.value || selectedCount.value === 0) return;
    sending.value = true;
    error.value = null;
    try {
        const contact_ids = Array.from(selected.value);
        const bulkIds = (props.messageIds || []).filter((n) => typeof n === 'number');
        const isBulk = bulkIds.length > 0;
        let url = '';
        try {
            url = isBulk ? route('messages.forward-bulk') : route('messages.forward', props.message!.id);
        } catch (routeErr: any) {
            // Fallback: if Ziggy is outdated, post to the known Laravel routes directly.
            if (isBulk) {
                url = '/messages/forward-bulk';
            } else {
                const mid = props.message?.id;
                url = typeof mid === 'number' ? `/messages/${mid}/forward` : '';
            }
            if (!url) {
                error.value = String(routeErr?.message || routeErr || 'Не удалось построить URL для пересылки');
                return;
            }
        }
        // Ensure absolute path to avoid resolving relative to current chat URL.
        if (url && !url.startsWith('/') && !/^https?:\/\//i.test(url)) {
            url = `/${url}`;
        }

        const { data } = await axios.post(
            url,
            isBulk
                ? { message_ids: bulkIds, contact_ids, whatsapp_session_id: props.whatsappSessionId }
                : { contact_ids, whatsapp_session_id: props.whatsappSessionId },
        );
        if (data?.success) {
            emit('sent', Number(data.sent || selectedCount.value));
            emit('close');
            return;
        }
        error.value = data?.error || 'Не удалось переслать';
    } catch (e: any) {
        const status = e?.response?.status;
        const resp = e?.response?.data;
        const msg =
            resp?.message ||
            resp?.error ||
            (typeof resp === 'string' ? resp.slice(0, 180) : '') ||
            e?.message ||
            'Не удалось переслать';
        error.value = status ? `[${status}] ${msg}` : msg;
    } finally {
        sending.value = false;
    }
}

onMounted(() => {
    if (props.open) void loadContacts();
});
</script>

<template>
    <teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[300] flex items-center justify-center px-4" :style="{ background: 'rgba(0,0,0,.45)' }">
            <div class="w-full max-w-[520px] rounded-2xl border overflow-hidden"
                 :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div class="px-5 py-4 flex items-center justify-between" :style="{ background: 'var(--wa-panel-header)' }">
                    <div class="text-sm font-medium" :style="{ color: 'var(--wa-text)' }">
                        Переслать сообщение
                    </div>
                    <button type="button" class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]" @click="emit('close')">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="p-5">
                    <div class="relative">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[var(--wa-icon)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input
                            v-model="q"
                            type="text"
                            placeholder="Поиск контактов…"
                            class="w-full pl-10 pr-3 py-2 rounded-full text-sm border-0 focus:ring-0 focus:outline-none"
                            :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                        />
                    </div>

                    <div v-if="error" class="text-xs mt-3" style="color:#ff6b6b;">
                        {{ error }}
                    </div>

                    <div class="mt-4 max-h-[360px] overflow-y-auto wa-scrollbar">
                        <div v-if="loading" class="text-sm px-2 py-3" :style="{ color: 'var(--wa-text-secondary)' }">Загрузка…</div>
                        <button
                            v-for="c in contacts"
                            :key="c.id"
                            type="button"
                            class="w-full flex items-center gap-3 px-3 py-2 rounded-xl hover:bg-[var(--wa-panel-hover)] transition"
                            @click="toggleContact(c.id)"
                        >
                            <span
                                class="w-5 h-5 rounded-md border flex items-center justify-center"
                                :style="{ borderColor: 'var(--wa-border)', background: selected.has(c.id) ? 'var(--wa-accent)' : 'transparent', color: selected.has(c.id) ? '#fff' : 'transparent' }"
                            >
                                ✓
                            </span>
                            <div class="flex-1 min-w-0 text-left">
                                <div class="text-sm truncate" :style="{ color: 'var(--wa-text)' }">
                                    {{ contactLabel(c) || 'Без имени' }}
                                </div>
                                <div class="text-xs truncate" :style="{ color: 'var(--wa-text-secondary)' }">
                                    {{ formatPhone(c.phone_number) || '' }}
                                </div>
                            </div>
                        </button>
                        <div v-if="!loading && contacts.length === 0" class="text-sm px-2 py-3" :style="{ color: 'var(--wa-text-secondary)' }">
                            Контакты не найдены
                        </div>
                    </div>

                    <div class="mt-4 flex items-center justify-between gap-3">
                        <div class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                            Выбрано: {{ selectedCount }}
                        </div>
                        <div class="flex gap-2">
                            <button type="button" class="px-4 py-2 rounded-xl hover:bg-[var(--wa-panel-hover)]" :style="{ color: 'var(--wa-text)' }" @click="emit('close')">
                                Отмена
                            </button>
                            <button
                                type="button"
                                class="px-4 py-2 rounded-xl"
                                :disabled="sending || selectedCount === 0"
                                :style="{ background: 'var(--wa-accent)', color: '#fff', opacity: sending || selectedCount === 0 ? 0.6 : 1 }"
                                @click="send"
                            >
                                Переслать
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </teleport>
</template>

