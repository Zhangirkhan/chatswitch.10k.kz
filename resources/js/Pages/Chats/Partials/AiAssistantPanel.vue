<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { useToastStore } from '@/stores/toast';
import type { Message } from '@/types';

type AiStatus = {
    id: number;
    mode: string;
    status: string;
    label: string;
    message: string;
    hint: string | null;
    knowledge_context: {
        rules: number;
        products: number;
        services: number;
    } | null;
    tone_source: {
        source: string;
        label: string;
        hint: string;
    } | null;
    draft_reply: string | null;
    technical_error: string | null;
    updated_at: string | null;
    history?: Array<{
        id: number;
        mode: string;
        status: string;
        label: string;
        message: string;
        technical_error: string | null;
        message_id: number | null;
        trigger_message_id: number | null;
        updated_at: string | null;
    }>;
};

const props = defineProps<{
    chatId: number;
    chatName?: string | null;
    messages?: Message[];
    aiStatus?: AiStatus | null;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

type AiTurn = {
    role: 'user' | 'assistant';
    content: string;
    ts: number;
};

const { show: showToast } = useToastStore();

const turns = ref<AiTurn[]>([]);
const draft = ref<string>('');
const sending = ref<boolean>(false);
const autoDraft = ref<string>('');
const autoDraftLoading = ref<boolean>(false);
const autoDraftError = ref<string | null>(null);
const autoDraftMessageId = ref<number | null>(null);
const listEl = ref<HTMLDivElement | null>(null);
const textareaEl = ref<HTMLTextAreaElement | null>(null);
let autoDraftTimer: number | null = null;

/**
 * Локальная история переписки оператора с AI хранится в localStorage по chatId,
 * чтобы при повторном открытии панели не терять контекст «думаем над клиентом».
 * Чужой чат не должен видеть нашу подсказку — поэтому ключ привязан к id чата.
 */
const storageKey = computed(() => `chatswitch:ai-assistant:${props.chatId}`);

function loadFromStorage(): void {
    try {
        const raw = window.localStorage.getItem(storageKey.value);
        if (!raw) {
            turns.value = [];
            return;
        }
        const parsed = JSON.parse(raw);
        if (!Array.isArray(parsed)) {
            turns.value = [];
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
        window.localStorage.setItem(storageKey.value, JSON.stringify(turns.value.slice(-40)));
    } catch {
        /* localStorage может быть недоступен — игнорируем */
    }
}

watch(turns, persistToStorage, { deep: true });
watch(() => props.chatId, loadFromStorage);

const canSend = computed(() => !sending.value && draft.value.trim().length > 0);
const isEmpty = computed(() => turns.value.length === 0);
const clientMessages = computed(() =>
    (props.messages ?? [])
        .filter((message) => message.direction === 'inbound')
        .slice(-6),
);
const latestClientMessage = computed(() => clientMessages.value.at(-1) ?? null);
const hasClientMessages = computed(() => clientMessages.value.length > 0);
const aiStatusTone = computed(() => {
    if (!props.aiStatus) return 'idle';
    if (props.aiStatus.status === 'failed') return 'error';
    if (props.aiStatus.status === 'blocked') return 'warning';
    if (props.aiStatus.status === 'generating' || props.aiStatus.status === 'pending') return 'busy';
    if (props.aiStatus.status === 'sent' || props.aiStatus.status === 'drafted') return 'success';
    return 'idle';
});
const aiStatusUpdatedAt = computed(() => {
    if (!props.aiStatus?.updated_at) return null;
    return formatTime(new Date(props.aiStatus.updated_at).getTime());
});
const aiKnowledgeContextLabel = computed(() => {
    const context = props.aiStatus?.knowledge_context;
    if (!context) {
        return 'База знаний: компания не определена';
    }

    return `База знаний: правил ${context.rules}, товаров ${context.products}, услуг ${context.services}`;
});
const aiToneSourceLabel = computed(() => props.aiStatus?.tone_source?.label || 'Тон: не собран');
const aiStatusHistory = computed(() => props.aiStatus?.history ?? []);

const QUICK_ACTIONS: ReadonlyArray<{ label: string; prompt: string }> = [
    {
        label: 'Подскажи ответ клиенту',
        prompt: 'Проанализируй последнюю реплику клиента и предложи готовый ответ в стиле наших операторов из этого чата. Дай 1–2 варианта формулировки.',
    },
    {
        label: 'О чём этот диалог?',
        prompt: 'Кратко (3–5 пунктов) перескажи суть переписки: что хочет клиент, что уже ответили, какие есть открытые вопросы.',
    },
    {
        label: 'Возражения клиента',
        prompt: 'Выпиши все возражения и сомнения клиента в этом чате и предложи, как их закрыть в нашем стиле.',
    },
    {
        label: 'Календарь и договорённости',
        prompt:
            'По переписке: есть ли повод занести что-то в календарь (звонок, встреча, дедлайн, перезвон)? Учти уже запланированные события из контекста календаря. Предложи конкретную запись: заголовок, дата/время, ответственный, описание; отметь конфликты со слотами, если есть.',
    },
];

async function send(prompt?: string): Promise<void> {
    const text = (prompt ?? draft.value).trim();
    if (sending.value || text === '') {
        return;
    }

    const historySnapshot = turns.value.map((t) => ({ role: t.role, content: t.content }));

    turns.value.push({ role: 'user', content: text, ts: Date.now() });
    draft.value = '';
    sending.value = true;
    await scrollToBottom();

    try {
        const res = await axios.post(route('chats.ai.chat', { chat: props.chatId }), {
            message: text,
            history: historySnapshot,
        });
        const reply: string = String(res.data?.reply ?? '').trim();
        if (reply === '') {
            throw new Error('Пустой ответ');
        }
        turns.value.push({ role: 'assistant', content: reply, ts: Date.now() });
    } catch (e: any) {
        const msg: string =
            e?.response?.data?.message ||
            'Не удалось получить ответ AI.';
        turns.value.push({ role: 'assistant', content: `⚠ ${msg}`, ts: Date.now() });
        showToast({ message: msg });
    } finally {
        sending.value = false;
        await scrollToBottom();
        textareaEl.value?.focus();
    }
}

function scheduleAutoDraft(): void {
    const message = latestClientMessage.value;
    if (!message?.id) {
        autoDraft.value = '';
        autoDraftMessageId.value = null;
        autoDraftError.value = null;
        return;
    }

    if (autoDraftMessageId.value === message.id && autoDraft.value.trim() !== '') {
        return;
    }

    if (autoDraftTimer !== null) {
        window.clearTimeout(autoDraftTimer);
    }

    autoDraftTimer = window.setTimeout(() => {
        autoDraftTimer = null;
        void generateAutoDraft(message);
    }, 450);
}

async function generateAutoDraft(message = latestClientMessage.value): Promise<void> {
    if (!message?.id || autoDraftLoading.value) {
        return;
    }

    autoDraftLoading.value = true;
    autoDraftError.value = null;
    autoDraftMessageId.value = message.id;

    try {
        const body = normalizeMessageBody(message);
        const prompt = body
            ? `Клиент написал: "${body}". Подготовь один готовый черновик ответа клиенту в стиле операторов этого чата. Верни только текст ответа без пояснений.`
            : 'Подготовь один готовый черновик ответа на последнее сообщение клиента в стиле операторов этого чата. Верни только текст ответа без пояснений.';

        const res = await axios.post(route('chats.ai.chat', { chat: props.chatId }), {
            message: prompt,
            history: [],
        });
        const reply: string = String(res.data?.reply ?? '').trim();
        if (reply === '') {
            throw new Error('Пустой ответ');
        }
        autoDraft.value = reply;
    } catch (e: any) {
        autoDraft.value = '';
        autoDraftError.value =
            e?.response?.data?.message ||
            'Не удалось подготовить черновик.';
    } finally {
        autoDraftLoading.value = false;
        if ((latestClientMessage.value?.id ?? null) !== autoDraftMessageId.value) {
            scheduleAutoDraft();
        }
        await scrollToBottom();
    }
}

function clearConversation(): void {
    if (turns.value.length === 0) return;
    if (!window.confirm('Очистить переписку с AI по этому чату?')) return;
    turns.value = [];
    try {
        window.localStorage.removeItem(storageKey.value);
    } catch {
        /* noop */
    }
}

function copyToClipboard(text: string): void {
    if (!text) return;
    try {
        navigator.clipboard?.writeText(text);
        showToast({ message: 'Скопировано' });
    } catch {
        showToast({ message: 'Не удалось скопировать' });
    }
}

function normalizeMessageBody(message: Message): string {
    const body = String(message.body ?? '').trim();
    if (body !== '') {
        return body;
    }

    const type = String(message.type ?? 'chat');
    return type !== 'chat' ? `<сообщение типа "${type}" без текста>` : '';
}

function messageAuthor(message: Message): string {
    return message.sender_name || message.sender_phone || 'Клиент';
}

function onKeydown(e: KeyboardEvent): void {
    if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        if (canSend.value) {
            void send();
        }
    }
}

function onEscape(e: KeyboardEvent): void {
    if (e.key === 'Escape') emit('close');
}

async function scrollToBottom(): Promise<void> {
    await nextTick();
    const el = listEl.value;
    if (el) {
        el.scrollTop = el.scrollHeight;
    }
}

function formatTime(ts: number): string {
    try {
        return new Date(ts).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    } catch {
        return '';
    }
}

onMounted(() => {
    loadFromStorage();
    window.addEventListener('keydown', onEscape);
    scheduleAutoDraft();
    void scrollToBottom();
    nextTick(() => textareaEl.value?.focus());
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onEscape);
    if (autoDraftTimer !== null) {
        window.clearTimeout(autoDraftTimer);
        autoDraftTimer = null;
    }
});

watch(() => latestClientMessage.value?.id ?? null, scheduleAutoDraft);
watch(() => props.chatId, () => {
    autoDraft.value = '';
    autoDraftError.value = null;
    autoDraftMessageId.value = null;
    scheduleAutoDraft();
});
</script>

<template>
    <aside
        class="w-[420px] shrink-0 h-full flex flex-col border-l overflow-hidden"
        :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
    >
        <div
            class="min-h-[60px] py-2 px-4 flex items-center gap-3 shrink-0"
            :style="{ background: 'var(--wa-panel-header)' }"
        >
            <button
                type="button"
                class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                title="Закрыть"
                @click="emit('close')"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <div class="flex-1 min-w-0">
                <h2 class="text-base leading-tight truncate" :style="{ color: 'var(--wa-text)' }">
                    AI-ассистент
                </h2>
                <p
                    v-if="chatName"
                    class="text-[11px] leading-tight truncate opacity-80"
                    :style="{ color: 'var(--wa-text-secondary)' }"
                >
                    по чату с {{ chatName }}
                </p>
            </div>
            <button
                v-if="!isEmpty"
                type="button"
                class="text-xs px-2.5 py-1.5 rounded-md hover:bg-[var(--wa-panel-hover)]"
                :style="{ color: 'var(--wa-text-secondary)' }"
                title="Очистить переписку с AI"
                @click="clearConversation"
            >
                Очистить
            </button>
        </div>

        <div
            ref="listEl"
            class="flex-1 min-h-0 overflow-y-auto wa-scrollbar px-4 py-4 space-y-3"
        >
            <section
                class="ai-status-card"
                :class="`ai-status-card-${aiStatusTone}`"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide">
                            Последнее решение AI
                        </p>
                        <p class="mt-1 text-sm font-semibold">
                            {{ aiStatus?.label || 'AI ещё не отвечал автоматически' }}
                        </p>
                    </div>
                    <span v-if="aiStatusUpdatedAt" class="shrink-0 text-[11px] opacity-70">
                        {{ aiStatusUpdatedAt }}
                    </span>
                </div>

                <p class="mt-2 text-[12.5px] leading-5 opacity-90">
                    {{ aiStatus?.message || 'Когда AI обработает сообщение клиента, здесь появится причина и результат.' }}
                </p>
                <p v-if="aiStatus?.hint" class="mt-1 text-[12px] leading-5 opacity-75">
                    {{ aiStatus.hint }}
                </p>
                <p class="mt-2 rounded-lg px-2.5 py-1.5 text-[11.5px] opacity-80" :style="{ background: 'color-mix(in srgb, var(--wa-bg) 50%, transparent)' }">
                    {{ aiKnowledgeContextLabel }}
                </p>
                <p
                    class="mt-1 rounded-lg px-2.5 py-1.5 text-[11.5px] opacity-80"
                    :title="aiStatus?.tone_source?.hint || ''"
                    :style="{ background: 'color-mix(in srgb, var(--wa-bg) 50%, transparent)' }"
                >
                    {{ aiToneSourceLabel }}
                </p>

                <div
                    v-if="aiStatus?.draft_reply"
                    class="mt-3 rounded-lg px-3 py-2 text-[13px] leading-5"
                    :style="{ background: 'var(--wa-panel)', border: '1px solid var(--wa-border)' }"
                >
                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide opacity-70">
                        Системный AI-черновик
                    </p>
                    <div class="whitespace-pre-wrap break-words">{{ aiStatus.draft_reply }}</div>
                    <div class="mt-2 flex justify-end">
                        <button
                            type="button"
                            class="text-[11px] hover:underline"
                            @click="copyToClipboard(aiStatus.draft_reply || '')"
                        >
                            Копировать черновик
                        </button>
                    </div>
                </div>

                <details v-if="aiStatus?.technical_error" class="mt-2 text-[11.5px] opacity-80">
                    <summary class="cursor-pointer font-medium">Технические детали для администратора</summary>
                    <pre class="mt-1 whitespace-pre-wrap break-words">{{ aiStatus.technical_error }}</pre>
                </details>

                <details v-if="aiStatusHistory.length > 1" class="mt-3 text-[11.5px] opacity-85">
                    <summary class="cursor-pointer font-semibold">
                        История AI-решений: {{ aiStatusHistory.length }}
                    </summary>
                    <div class="mt-2 space-y-2">
                        <div
                            v-for="item in aiStatusHistory"
                            :key="item.id"
                            class="rounded-lg px-2.5 py-2"
                            :style="{ background: 'color-mix(in srgb, var(--wa-bg) 48%, transparent)' }"
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-semibold">{{ item.label }}</span>
                                <span v-if="item.updated_at" class="opacity-65">
                                    {{ formatTime(new Date(item.updated_at).getTime()) }}
                                </span>
                            </div>
                            <p class="mt-1 leading-4 opacity-80">{{ item.message }}</p>
                            <p class="mt-1 opacity-60">
                                Режим: {{ item.mode === 'draft' ? 'черновик' : 'автоответ' }}
                            </p>
                            <details v-if="item.technical_error" class="mt-1 opacity-80">
                                <summary class="cursor-pointer">Технически</summary>
                                <pre class="mt-1 whitespace-pre-wrap break-words">{{ item.technical_error }}</pre>
                            </details>
                        </div>
                    </div>
                </details>
            </section>

            <section
                class="rounded-xl border p-3 space-y-3"
                :style="{
                    background: 'color-mix(in srgb, var(--wa-accent) 8%, var(--wa-panel))',
                    borderColor: 'color-mix(in srgb, var(--wa-accent) 25%, var(--wa-border))',
                    color: 'var(--wa-text)',
                }"
            >
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--wa-accent)' }">
                            Live-черновик
                        </p>
                        <p class="text-[11px] opacity-70" :style="{ color: 'var(--wa-text-secondary)' }">
                            Работает даже когда автоответы AI выключены
                        </p>
                    </div>
                    <button
                        type="button"
                        class="ai-quick-chip ai-quick-chip-sm"
                        :disabled="autoDraftLoading || !latestClientMessage"
                        @click="generateAutoDraft()"
                    >
                        Обновить
                    </button>
                </div>

                <div v-if="hasClientMessages" class="space-y-2">
                    <p class="text-[11px] font-medium opacity-70" :style="{ color: 'var(--wa-text-secondary)' }">
                        Последние сообщения клиента
                    </p>
                    <div
                        v-for="message in clientMessages"
                        :key="message.id"
                        class="rounded-lg px-3 py-2 text-[12.5px] leading-4"
                        :style="{ background: 'var(--wa-panel)', border: '1px solid var(--wa-border)' }"
                    >
                        <div class="mb-1 flex items-center justify-between gap-2 text-[10.5px] opacity-65">
                            <span class="truncate">{{ messageAuthor(message) }}</span>
                            <span>{{ formatTime(new Date(message.message_timestamp || message.created_at || Date.now()).getTime()) }}</span>
                        </div>
                        <div class="whitespace-pre-wrap break-words">
                            {{ normalizeMessageBody(message) || 'Сообщение без текста' }}
                        </div>
                    </div>
                </div>
                <p v-else class="text-[12.5px] opacity-75" :style="{ color: 'var(--wa-text-secondary)' }">
                    В этом чате пока нет входящих сообщений клиента.
                </p>

                <div
                    class="rounded-lg px-3 py-2 text-[13px] leading-5"
                    :style="{ background: 'var(--wa-bubble-in)', color: 'var(--wa-bubble-text)' }"
                >
                    <template v-if="autoDraftLoading">
                        AI готовит черновик…
                    </template>
                    <template v-else-if="autoDraft">
                        <div class="whitespace-pre-wrap break-words">{{ autoDraft }}</div>
                        <div class="mt-2 flex justify-end">
                            <button
                                type="button"
                                class="text-[11px] hover:underline"
                                @click="copyToClipboard(autoDraft)"
                            >
                                Копировать черновик
                            </button>
                        </div>
                    </template>
                    <template v-else-if="autoDraftError">
                        {{ autoDraftError }}
                    </template>
                    <template v-else>
                        Черновик появится после сообщения клиента.
                    </template>
                </div>
            </section>

            <div
                v-if="isEmpty"
                class="text-[13px] leading-relaxed rounded-lg p-3"
                :style="{
                    background: 'color-mix(in srgb, var(--wa-accent) 10%, var(--wa-panel))',
                    color: 'var(--wa-text)',
                    border: '1px solid color-mix(in srgb, var(--wa-accent) 25%, var(--wa-border))',
                }"
            >
                <p class="font-medium mb-1">Привет! Я ассистент оператора.</p>
                <p class="opacity-80">
                    Я уже вижу всю переписку этого чата и стиль ваших ответов.
                    Спросите меня — подскажу, как лучше ответить клиенту, в каком тоне,
                    или разберу диалог по шагам.
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        v-for="(action, idx) in QUICK_ACTIONS"
                        :key="idx"
                        type="button"
                        class="ai-quick-chip"
                        :disabled="sending"
                        @click="send(action.prompt)"
                    >
                        {{ action.label }}
                    </button>
                </div>
            </div>

            <template v-for="(turn, idx) in turns" :key="idx">
                <div
                    class="max-w-[92%] text-[13.5px] rounded-2xl px-3 py-2 wa-shadow whitespace-pre-wrap break-words leading-[19px]"
                    :class="turn.role === 'user' ? 'ml-auto rounded-tr-md' : 'mr-auto rounded-tl-md'"
                    :style="{
                        background: turn.role === 'user' ? 'var(--wa-bubble-out)' : 'var(--wa-bubble-in)',
                        color: 'var(--wa-bubble-text)',
                    }"
                >
                    <div>{{ turn.content }}</div>
                    <div class="flex items-center justify-end gap-2 mt-1 text-[10px] opacity-60">
                        <button
                            v-if="turn.role === 'assistant'"
                            type="button"
                            class="hover:underline"
                            title="Скопировать ответ"
                            @click="copyToClipboard(turn.content)"
                        >
                            Копировать
                        </button>
                        <span>{{ formatTime(turn.ts) }}</span>
                    </div>
                </div>
            </template>

            <div
                v-if="sending"
                class="mr-auto max-w-[60%] text-[13px] rounded-2xl rounded-tl-md px-3 py-2 wa-shadow flex items-center gap-2"
                :style="{ background: 'var(--wa-bubble-in)', color: 'var(--wa-bubble-text)' }"
            >
                <span class="ai-typing-dot" />
                <span class="ai-typing-dot" />
                <span class="ai-typing-dot" />
                <span class="opacity-70 text-[12px] ml-1">AI думает…</span>
            </div>
        </div>

        <div
            class="shrink-0 border-t px-3 py-3"
            :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }"
        >
            <div
                v-if="!isEmpty"
                class="flex flex-wrap gap-1.5 mb-2"
            >
                <button
                    v-for="(action, idx) in QUICK_ACTIONS"
                    :key="idx"
                    type="button"
                    class="ai-quick-chip ai-quick-chip-sm"
                    :disabled="sending"
                    @click="send(action.prompt)"
                >
                    {{ action.label }}
                </button>
            </div>

            <div class="flex items-end gap-2">
                <textarea
                    ref="textareaEl"
                    v-model="draft"
                    rows="2"
                    placeholder="Спросите AI про этот диалог…"
                    class="flex-1 resize-none rounded-lg px-3 py-2 text-[13.5px] outline-none"
                    :style="{
                        background: 'var(--wa-panel)',
                        color: 'var(--wa-text)',
                        border: '1px solid var(--wa-border)',
                    }"
                    :disabled="sending"
                    @keydown="onKeydown"
                />
                <button
                    type="button"
                    class="h-10 px-4 rounded-lg text-sm font-medium transition disabled:opacity-50 disabled:cursor-not-allowed"
                    :style="{
                        background: 'var(--wa-accent)',
                        color: 'white',
                    }"
                    :disabled="!canSend"
                    title="Отправить (Enter)"
                    @click="send()"
                >
                    <svg v-if="!sending" class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" />
                    </svg>
                    <svg v-else class="w-4 h-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                        <path stroke-linecap="round" d="M21 12a9 9 0 11-9-9" />
                    </svg>
                </button>
            </div>
            <p class="mt-1.5 text-[10.5px] opacity-60" :style="{ color: 'var(--wa-text-secondary)' }">
                Enter — отправить, Shift+Enter — перенос строки. AI видит до 80 последних сообщений чата.
            </p>
        </div>
    </aside>
</template>

<style scoped>
.ai-status-card {
    border: 1px solid var(--wa-border);
    border-radius: 0.9rem;
    color: var(--wa-text);
    padding: 0.75rem;
}

.ai-status-card-idle {
    background: color-mix(in srgb, var(--wa-panel) 92%, var(--wa-bg) 8%);
}

.ai-status-card-success {
    background: color-mix(in srgb, var(--wa-green) 10%, var(--wa-panel));
    border-color: color-mix(in srgb, var(--wa-green) 32%, var(--wa-border));
}

.ai-status-card-busy {
    background: color-mix(in srgb, #2563eb 10%, var(--wa-panel));
    border-color: color-mix(in srgb, #2563eb 32%, var(--wa-border));
}

.ai-status-card-warning {
    background: color-mix(in srgb, #f59e0b 12%, var(--wa-panel));
    border-color: color-mix(in srgb, #f59e0b 36%, var(--wa-border));
}

.ai-status-card-error {
    background: color-mix(in srgb, var(--wa-danger) 11%, var(--wa-panel));
    border-color: color-mix(in srgb, var(--wa-danger) 34%, var(--wa-border));
}

.ai-quick-chip {
    font-size: 11.5px;
    line-height: 1;
    padding: 6px 10px;
    border-radius: 9999px;
    background: color-mix(in srgb, var(--wa-accent) 12%, var(--wa-panel));
    border: 1px solid color-mix(in srgb, var(--wa-accent) 35%, var(--wa-border));
    color: var(--wa-text);
    transition: background-color 0.15s ease, border-color 0.15s ease;
    cursor: pointer;
}
.ai-quick-chip:hover:not(:disabled) {
    background: color-mix(in srgb, var(--wa-accent) 22%, var(--wa-panel));
    border-color: color-mix(in srgb, var(--wa-accent) 50%, var(--wa-border));
}
.ai-quick-chip:disabled {
    opacity: 0.55;
    cursor: not-allowed;
}
.ai-quick-chip-sm {
    font-size: 11px;
    padding: 4px 9px;
}

.ai-typing-dot {
    width: 6px;
    height: 6px;
    border-radius: 9999px;
    background: var(--wa-text-secondary);
    opacity: 0.5;
    animation: ai-typing-bounce 1.2s infinite ease-in-out;
}
.ai-typing-dot:nth-child(2) {
    animation-delay: 0.15s;
}
.ai-typing-dot:nth-child(3) {
    animation-delay: 0.3s;
}
@keyframes ai-typing-bounce {
    0%, 80%, 100% {
        transform: translateY(0);
        opacity: 0.35;
    }
    40% {
        transform: translateY(-3px);
        opacity: 0.95;
    }
}
</style>
