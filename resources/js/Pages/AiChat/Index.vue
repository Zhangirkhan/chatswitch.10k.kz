<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatPhone } from '@/utils/phone';

type AiTurn = {
    role: 'user' | 'assistant';
    content: string;
    ts: number;
};

type WorkspaceContact = {
    id: number;
    name: string;
    phone_number: string | null;
    companies: string[];
    chat_id: number | null;
    last_message_at: string | null;
    unread_count?: number;
};

type WorkspaceMedia = {
    id: number;
    filename: string | null;
    mime_type: string | null;
    url: string;
    chat_id: number | null;
    chat_name: string | null;
    contact_name: string | null;
    message_at: string | null;
};

const props = defineProps<{
    suggestions: string[];
}>();

const turns = ref<AiTurn[]>([]);
const draft = ref('');
const sending = ref(false);
const error = ref<string | null>(null);
const contacts = ref<WorkspaceContact[]>([]);
const media = ref<WorkspaceMedia[]>([]);
const listEl = ref<HTMLDivElement | null>(null);
const textareaEl = ref<HTMLTextAreaElement | null>(null);

const storageKey = 'chatswitch:ai-workspace:v1';

const hasResults = computed(() => contacts.value.length > 0 || media.value.length > 0);

function loadFromStorage(): void {
    try {
        const raw = window.localStorage.getItem(storageKey);
        if (!raw) {
            return;
        }
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            return;
        }
        turns.value = parsed
            .filter((t) => t && (t.role === 'user' || t.role === 'assistant') && typeof t.content === 'string')
            .map((t) => ({
                role: t.role,
                content: String(t.content),
                ts: typeof t.ts === 'number' ? t.ts : Date.now(),
            }));
    } catch {
        turns.value = [];
    }
}

function persistToStorage(): void {
    try {
        window.localStorage.setItem(storageKey, JSON.stringify(turns.value.slice(-30)));
    } catch {
        /* ignore quota */
    }
}

function scrollToBottom(): void {
    nextTick(() => {
        if (listEl.value) {
            listEl.value.scrollTop = listEl.value.scrollHeight;
        }
    });
}

function resizeTextarea(): void {
    const el = textareaEl.value;
    if (!el) {
        return;
    }
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 160)}px`;
}

function applySuggestion(text: string): void {
    draft.value = text;
    resizeTextarea();
    textareaEl.value?.focus();
}

async function send(): Promise<void> {
    const text = draft.value.trim();
    if (!text || sending.value) {
        return;
    }

    error.value = null;
    sending.value = true;
    draft.value = '';
    resizeTextarea();

    turns.value.push({ role: 'user', content: text, ts: Date.now() });
    persistToStorage();
    scrollToBottom();

    const history = turns.value
        .slice(0, -1)
        .slice(-20)
        .map((t) => ({ role: t.role, content: t.content }));

    try {
        const { data } = await axios.post(route('ai-chat.query'), {
            message: text,
            history,
        });

        turns.value.push({
            role: 'assistant',
            content: String(data.reply ?? ''),
            ts: Date.now(),
        });
        contacts.value = Array.isArray(data.contacts) ? data.contacts : [];
        media.value = Array.isArray(data.media) ? data.media : [];
        persistToStorage();
    } catch (e: unknown) {
        const msg =
            axios.isAxiosError(e) && e.response?.data && typeof e.response.data.message === 'string'
                ? e.response.data.message
                : 'Не удалось выполнить запрос.';
        error.value = msg;
        turns.value.push({
            role: 'assistant',
            content: msg,
            ts: Date.now(),
        });
        persistToStorage();
    } finally {
        sending.value = false;
        scrollToBottom();
    }
}

function clearDialog(): void {
    turns.value = [];
    contacts.value = [];
    media.value = [];
    error.value = null;
    window.localStorage.removeItem(storageKey);
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        void send();
    }
}

function mimeLabel(mime: string | null): string {
    if (!mime) {
        return 'Файл';
    }
    if (mime.startsWith('image/')) {
        return 'Фото';
    }
    if (mime.startsWith('video/')) {
        return 'Видео';
    }
    if (mime.startsWith('audio/')) {
        return 'Аудио';
    }
    return 'Документ';
}

onMounted(() => {
    loadFromStorage();
    scrollToBottom();
});
</script>

<template>
    <AuthenticatedLayout>
        <Head title="ИИ чат" />

        <div class="flex h-full min-h-0 flex-1">
            <div class="flex min-h-0 min-w-0 flex-1 flex-col border-r" :style="{ borderColor: 'var(--wa-sidebar-divider)' }">
                <header
                    class="shrink-0 flex items-center justify-between gap-3 px-4 py-3 border-b"
                    :style="{ borderColor: 'var(--wa-sidebar-divider)', background: 'var(--wa-panel-header)' }"
                >
                    <div>
                        <h1 class="text-lg font-semibold text-[var(--wa-text)]">ИИ чат</h1>
                        <p class="text-xs text-[var(--wa-text-secondary)] mt-0.5">
                            Поиск клиентов и контактов по фильтрам, файлов в переписках
                        </p>
                    </div>
                    <button
                        type="button"
                        class="text-xs px-3 py-1.5 rounded-lg border transition hover:opacity-80"
                        :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
                        @click="clearDialog"
                    >
                        Очистить
                    </button>
                </header>

                <div
                    ref="listEl"
                    class="flex-1 min-h-0 overflow-y-auto px-4 py-4 space-y-3"
                >
                    <div
                        v-if="turns.length === 0"
                        class="rounded-xl border p-4 text-sm"
                        :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-composer-bg)', color: 'var(--wa-text-secondary)' }"
                    >
                        <p class="mb-3">Примеры запросов:</p>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="(s, i) in suggestions"
                                :key="i"
                                type="button"
                                class="text-left text-xs px-3 py-2 rounded-full border transition hover:opacity-90"
                                :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text)' }"
                                @click="applySuggestion(s)"
                            >
                                {{ s }}
                            </button>
                        </div>
                    </div>

                    <div
                        v-for="(turn, idx) in turns"
                        :key="idx"
                        class="flex"
                        :class="turn.role === 'user' ? 'justify-end' : 'justify-start'"
                    >
                        <div
                            class="max-w-[85%] rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap break-words"
                            :style="turn.role === 'user'
                                ? { background: 'var(--wa-outbound-bg)', color: 'var(--wa-outbound-text)' }
                                : { background: 'var(--wa-inbound-bg)', color: 'var(--wa-text)' }"
                        >
                            {{ turn.content }}
                        </div>
                    </div>

                    <div
                        v-if="sending"
                        class="text-xs text-[var(--wa-text-secondary)] animate-pulse"
                    >
                        Ищу…
                    </div>
                </div>

                <footer
                    class="shrink-0 p-3 border-t"
                    :style="{ borderColor: 'var(--wa-sidebar-divider)', background: 'var(--wa-composer-bg)' }"
                >
                    <p v-if="error" class="text-xs text-red-500 mb-2">{{ error }}</p>
                    <div class="flex gap-2 items-end">
                        <textarea
                            ref="textareaEl"
                            v-model="draft"
                            rows="1"
                            class="flex-1 resize-none rounded-xl px-4 py-2.5 text-sm outline-none border min-h-[44px] max-h-[160px]"
                            :style="{
                                background: 'var(--wa-input-bg)',
                                borderColor: 'var(--wa-border)',
                                color: 'var(--wa-text)',
                            }"
                            placeholder="Например: клиенты с непрочитанными или pdf договор за май"
                            :disabled="sending"
                            @keydown="onKeydown"
                            @input="resizeTextarea"
                        />
                        <button
                            type="button"
                            class="shrink-0 h-11 px-4 rounded-xl text-sm font-medium transition disabled:opacity-50"
                            :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                            :disabled="sending || !draft.trim()"
                            @click="send"
                        >
                            Отправить
                        </button>
                    </div>
                </footer>

                <div
                    v-if="hasResults"
                    class="lg:hidden shrink-0 max-h-[40vh] overflow-y-auto border-t px-3 py-3 space-y-3"
                    :style="{ borderColor: 'var(--wa-sidebar-divider)', background: 'var(--wa-sidebar-bg)' }"
                >
                    <section v-if="contacts.length">
                        <h2 class="text-xs font-semibold uppercase text-[var(--wa-text-secondary)] mb-2">Контакты</h2>
                        <ul class="space-y-2">
                            <li
                                v-for="c in contacts"
                                :key="'m-c-' + c.id"
                                class="rounded-lg border p-2 text-sm"
                                :style="{ borderColor: 'var(--wa-border)' }"
                            >
                                <div class="font-medium">{{ c.name }}</div>
                                <Link
                                    v-if="c.chat_id"
                                    :href="route('chats.show', c.chat_id)"
                                    class="text-xs mt-1 inline-block"
                                    :style="{ color: 'var(--wa-accent)' }"
                                >
                                    Открыть чат
                                </Link>
                            </li>
                        </ul>
                    </section>
                    <section v-if="media.length">
                        <h2 class="text-xs font-semibold uppercase text-[var(--wa-text-secondary)] mb-2">Файлы</h2>
                        <ul class="space-y-2">
                            <li v-for="m in media" :key="'m-m-' + m.id" class="text-sm truncate">
                                <a :href="m.url" target="_blank" rel="noopener" class="underline">{{ m.filename || 'Файл' }}</a>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>

            <aside
                class="hidden lg:flex w-[340px] xl:w-[380px] shrink-0 flex-col min-h-0"
                :style="{ background: 'var(--wa-sidebar-bg)' }"
            >
                <div
                    class="shrink-0 px-4 py-3 border-b text-sm font-medium"
                    :style="{ borderColor: 'var(--wa-sidebar-divider)', color: 'var(--wa-text)' }"
                >
                    Результаты
                    <span v-if="hasResults" class="font-normal text-[var(--wa-text-secondary)]">
                        · {{ contacts.length }} контактов, {{ media.length }} файлов
                    </span>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto px-3 py-3 space-y-4">
                    <p
                        v-if="!hasResults"
                        class="text-xs text-[var(--wa-text-secondary)] px-1"
                    >
                        Здесь появятся контакты и файлы после запроса.
                    </p>

                    <section v-if="contacts.length">
                        <h2 class="text-xs font-semibold uppercase tracking-wide text-[var(--wa-text-secondary)] mb-2 px-1">
                            Контакты
                        </h2>
                        <ul class="space-y-2">
                            <li
                                v-for="c in contacts"
                                :key="c.id"
                                class="rounded-xl border p-3 text-sm"
                                :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-composer-bg)' }"
                            >
                                <div class="font-medium text-[var(--wa-text)]">{{ c.name }}</div>
                                <p v-if="c.phone_number" class="text-xs text-[var(--wa-text-secondary)] mt-0.5">
                                    {{ formatPhone(c.phone_number) || c.phone_number }}
                                </p>
                                <p v-if="c.companies?.length" class="text-xs text-[var(--wa-text-secondary)] mt-1 truncate">
                                    {{ c.companies.join(', ') }}
                                </p>
                                <p v-if="c.unread_count" class="text-xs mt-1" :style="{ color: 'var(--wa-accent)' }">
                                    Непрочитанных: {{ c.unread_count }}
                                </p>
                                <Link
                                    v-if="c.chat_id"
                                    :href="route('chats.show', c.chat_id)"
                                    class="inline-block mt-2 text-xs font-medium"
                                    :style="{ color: 'var(--wa-accent)' }"
                                >
                                    Открыть чат →
                                </Link>
                            </li>
                        </ul>
                    </section>

                    <section v-if="media.length">
                        <h2 class="text-xs font-semibold uppercase tracking-wide text-[var(--wa-text-secondary)] mb-2 px-1">
                            Файлы
                        </h2>
                        <ul class="space-y-2">
                            <li
                                v-for="m in media"
                                :key="m.id"
                                class="rounded-xl border p-3 text-sm"
                                :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-composer-bg)' }"
                            >
                                <div class="flex gap-2 items-start">
                                    <a
                                        :href="m.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="shrink-0 block w-12 h-12 rounded-lg overflow-hidden bg-[var(--wa-rail-btn-hover)]"
                                    >
                                        <img
                                            v-if="m.mime_type?.startsWith('image/')"
                                            :src="m.url"
                                            :alt="m.filename || ''"
                                            class="w-full h-full object-cover"
                                        />
                                        <span
                                            v-else
                                            class="w-full h-full flex items-center justify-center text-[10px] text-[var(--wa-text-secondary)]"
                                        >
                                            {{ mimeLabel(m.mime_type) }}
                                        </span>
                                    </a>
                                    <div class="min-w-0 flex-1">
                                        <a
                                            :href="m.url"
                                            target="_blank"
                                            rel="noopener"
                                            class="font-medium text-[var(--wa-text)] truncate block hover:underline"
                                        >
                                            {{ m.filename || 'Без имени' }}
                                        </a>
                                        <p class="text-xs text-[var(--wa-text-secondary)] mt-0.5 truncate">
                                            {{ m.contact_name || m.chat_name || 'Чат' }}
                                        </p>
                                        <Link
                                            v-if="m.chat_id"
                                            :href="route('chats.show', m.chat_id)"
                                            class="inline-block mt-1 text-xs"
                                            :style="{ color: 'var(--wa-accent)' }"
                                        >
                                            В диалог →
                                        </Link>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </section>
                </div>
            </aside>
        </div>
    </AuthenticatedLayout>
</template>
