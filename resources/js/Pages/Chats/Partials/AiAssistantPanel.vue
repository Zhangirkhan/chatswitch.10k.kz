<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import axios from 'axios';
import { useToastStore } from '@/stores/toast';

const props = defineProps<{
    chatId: number;
    chatName?: string | null;
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
const listEl = ref<HTMLDivElement | null>(null);
const textareaEl = ref<HTMLTextAreaElement | null>(null);

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
            e?.message ||
            'Не удалось получить ответ AI.';
        turns.value.push({ role: 'assistant', content: `⚠ ${msg}`, ts: Date.now() });
        showToast({ message: msg });
    } finally {
        sending.value = false;
        await scrollToBottom();
        textareaEl.value?.focus();
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
    void scrollToBottom();
    nextTick(() => textareaEl.value?.focus());
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onEscape);
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
