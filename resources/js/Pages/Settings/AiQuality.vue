<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';

type FailedLog = {
    id: number;
    created_at: string | null;
    status: string;
    mode: string | null;
    error: string | null;
    chat: string;
    company: string | null;
};

type GuardrailEvent = {
    id: number;
    chat: string;
    reason: string | null;
    status: string;
    created_at: string | null;
};

type AttentionItem = {
    id: number;
    chat_name: string;
    reason: string;
    severity: 'critical' | 'danger' | 'warning' | 'normal';
    wait_minutes: number | null;
    ai_status: string | null;
    last_message_at: string | null;
    unread_count: number;
    funnel?: string | null;
    stage?: string | null;
};

type ConfigurationAuditItem = {
    key: string;
    severity: 'critical' | 'warning' | 'info';
    category: string;
    title: string;
    description: string;
    action: string;
};

type ImprovementSuggestion = {
    key: string;
    label: string;
    count: number;
    action: string;
    examples: Array<{
        chat: string;
        preview: string;
        created_at: string | null;
    }>;
};

type ProblemRating = {
    id: number;
    rating: string;
    created_at: string | null;
    user: string | null;
    chat: string;
    body_preview: string;
};

type ReadinessCheck = {
    key: string;
    label: string;
    ok: boolean;
    value: string;
    hint: string;
};

type Readiness = {
    score: number;
    status: 'ready' | 'partial' | 'risk';
    label: string;
    summary: string;
    checks: ReadinessCheck[];
    next_actions: string[];
};

type SimulationResult = {
    customer_reply: string;
    funnel_name: string | null;
    stage_name: string | null;
    confidence: number;
    actions: string[];
    manager_needed: boolean;
    reason: string;
    risks: string[];
    missing_data: string[];
};

const props = defineProps<{
    readiness: Readiness;
    configuration_audit: ConfigurationAuditItem[];
    improvement_suggestions: ImprovementSuggestion[];
    attention_queue: AttentionItem[];
    guardrail_events: GuardrailEvent[];
    failed_logs: FailedLog[];
    problem_ratings: ProblemRating[];
}>();

const ratingLabels: Record<string, string> = {
    style: 'Стиль / тон',
    facts: 'Факты',
    long: 'Слишком длинно',
    context: 'Нет в базе знаний',
};

const simulationMessage = ref('Здравствуйте, хочу заказать кухню, можно завтра на замер?');
const simulationHistory = ref('');
const simulationLoading = ref(false);
const simulationError = ref<string | null>(null);
const simulationResult = ref<SimulationResult | null>(null);
const simulationConfidence = computed(() => Math.round((simulationResult.value?.confidence ?? 0) * 100));

function formatWhen(iso: string | null): string {
    if (!iso) {
        return '—';
    }
    try {
        return new Intl.DateTimeFormat('ru-RU', {
            dateStyle: 'short',
            timeStyle: 'short',
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function readinessColor(status: Readiness['status']): string {
    if (status === 'ready') return '#16a34a';
    if (status === 'partial') return '#d97706';
    return '#dc2626';
}

function attentionBadgeColor(severity: AttentionItem['severity']): string {
    if (severity === 'critical') return '#dc2626';
    if (severity === 'danger') return '#b91c1c';
    if (severity === 'warning') return '#d97706';
    return '#2563eb';
}

function auditColor(severity: ConfigurationAuditItem['severity']): string {
    if (severity === 'critical') return '#dc2626';
    if (severity === 'warning') return '#d97706';
    return '#2563eb';
}

function waitLabel(minutes: number | null): string {
    if (minutes === null) return '—';
    if (minutes < 60) return `${minutes} мин`;
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return rest > 0 ? `${hours} ч ${rest} мин` : `${hours} ч`;
}

async function runSimulation(): Promise<void> {
    const message = simulationMessage.value.trim();
    if (!message || simulationLoading.value) {
        return;
    }

    simulationLoading.value = true;
    simulationError.value = null;
    simulationResult.value = null;

    try {
        const { data } = await axios.post(route('settings.ai-quality.simulate'), {
            message,
            history: simulationHistory.value.trim(),
        });
        simulationResult.value = data.result as SimulationResult;
    } catch (e: any) {
        simulationError.value = e?.response?.data?.message || 'Не удалось запустить симуляцию.';
    } finally {
        simulationLoading.value = false;
    }
}
</script>

<template>
    <Head title="AI и качество" />

    <SettingsLayout title="AI и качество" subtitle="Сбои генерации и негативные оценки ответов операторов">
        <div class="w-full space-y-8 px-6 py-6">
            <section
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide" :style="{ color: readinessColor(readiness.status) }">
                            Готовность AI · {{ readiness.score }}%
                        </div>
                        <h2 class="mt-1 text-lg font-semibold" :style="{ color: 'var(--wa-text)' }">{{ readiness.label }}</h2>
                        <p class="mt-1 max-w-2xl text-sm" :style="{ color: 'var(--wa-text-secondary)' }">{{ readiness.summary }}</p>
                    </div>
                    <div class="h-20 w-20 rounded-full p-1" :style="{ background: `conic-gradient(${readinessColor(readiness.status)} ${readiness.score}%, var(--wa-panel-header) 0)` }">
                        <div class="flex h-full w-full items-center justify-center rounded-full text-lg font-semibold" :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }">
                            {{ readiness.score }}
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <article
                        v-for="check in readiness.checks"
                        :key="check.key"
                        class="rounded-lg border px-3 py-3"
                        :style="{ borderColor: check.ok ? 'rgba(22, 163, 74, .35)' : 'rgba(220, 38, 38, .35)', background: 'var(--wa-panel-header)' }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium" :style="{ color: 'var(--wa-text)' }">{{ check.label }}</div>
                                <p class="mt-1 text-xs leading-relaxed" :style="{ color: 'var(--wa-text-secondary)' }">{{ check.hint }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :style="{ color: check.ok ? '#15803d' : '#b91c1c', background: check.ok ? 'rgba(22, 163, 74, .12)' : 'rgba(220, 38, 38, .12)' }">
                                {{ check.ok ? 'OK' : check.value }}
                            </span>
                        </div>
                    </article>
                </div>

                <div v-if="readiness.next_actions.length" class="mt-5 rounded-lg border px-4 py-3" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }">
                    <div class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">Что сделать дальше</div>
                    <ul class="mt-2 space-y-1.5 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                        <li v-for="action in readiness.next_actions" :key="action">• {{ action }}</li>
                    </ul>
                </div>
            </section>

            <section
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">Предложения улучшений AI</h2>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                            Сводка по оценкам операторов: какие правила, факты или стиль стоит улучшить.
                        </p>
                    </div>
                    <div class="text-xs font-semibold" :style="{ color: 'var(--wa-text-secondary)' }">
                        {{ improvement_suggestions.length }} направлений
                    </div>
                </div>

                <div v-if="improvement_suggestions.length === 0" class="mt-4 rounded-lg border border-dashed px-4 py-6 text-center text-sm" :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }">
                    Пока нет негативных оценок AI-ответов. Когда операторы начнут отмечать проблемы, здесь появятся рекомендации.
                </div>

                <div v-else class="mt-4 grid gap-3 lg:grid-cols-2">
                    <article
                        v-for="item in improvement_suggestions"
                        :key="item.key"
                        class="rounded-lg border px-3 py-3"
                        :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">{{ item.label }}</h3>
                                <p class="mt-1 text-xs leading-relaxed" :style="{ color: 'var(--wa-text-secondary)' }">{{ item.action }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :style="{ color: 'var(--wa-accent)', background: 'color-mix(in srgb, var(--wa-accent) 12%, transparent)' }">
                                {{ item.count }}
                            </span>
                        </div>

                        <div v-if="item.examples.length" class="mt-3 space-y-2">
                            <div
                                v-for="example in item.examples"
                                :key="`${item.key}-${example.chat}-${example.created_at}`"
                                class="rounded-lg px-2.5 py-2 text-xs"
                                :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text-secondary)' }"
                            >
                                <div class="mb-1 flex items-center justify-between gap-2">
                                    <span class="font-medium" :style="{ color: 'var(--wa-text)' }">{{ example.chat }}</span>
                                    <span>{{ formatWhen(example.created_at) }}</span>
                                </div>
                                <div class="line-clamp-2">{{ example.preview || 'Без текста' }}</div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">Автоаудит конфигурации</h2>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                            Проверяет воронки, правила, отделы и базу знаний на слабые места до того, как они ломают диалоги.
                        </p>
                    </div>
                    <div class="text-xs font-semibold" :style="{ color: 'var(--wa-text-secondary)' }">
                        {{ configuration_audit.length }} рекомендаций
                    </div>
                </div>

                <div v-if="configuration_audit.length === 0" class="mt-4 rounded-lg border border-dashed px-4 py-6 text-center text-sm" :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }">
                    Критичных слабых мест не найдено.
                </div>

                <div v-else class="mt-4 grid gap-3 lg:grid-cols-2">
                    <article
                        v-for="item in configuration_audit"
                        :key="item.key"
                        class="rounded-lg border px-3 py-3"
                        :style="{ borderColor: `${auditColor(item.severity)}55`, background: 'var(--wa-panel-header)' }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :style="{ color: auditColor(item.severity), background: `${auditColor(item.severity)}1A` }">
                                        {{ item.category }}
                                    </span>
                                    <span class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">{{ item.title }}</span>
                                </div>
                                <p class="mt-2 text-xs leading-relaxed" :style="{ color: 'var(--wa-text-secondary)' }">{{ item.description }}</p>
                                <p class="mt-2 text-xs font-medium" :style="{ color: 'var(--wa-text)' }">{{ item.action }}</p>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">Очередь внимания</h2>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                            Чаты, где клиент ждёт, AI просит менеджера или оркестратор упал.
                        </p>
                    </div>
                    <div class="text-xs font-semibold" :style="{ color: 'var(--wa-text-secondary)' }">
                        {{ attention_queue.length }} активных
                    </div>
                </div>

                <div v-if="attention_queue.length === 0" class="mt-4 rounded-lg border border-dashed px-4 py-6 text-center text-sm" :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }">
                    Очередь пустая. Критичных чатов сейчас нет.
                </div>

                <div v-else class="mt-4 grid gap-3 xl:grid-cols-2">
                    <Link
                        v-for="item in attention_queue"
                        :key="item.id"
                        :href="route('chats.show', item.id)"
                        class="rounded-lg border px-3 py-3 transition hover:-translate-y-0.5"
                        :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)', color: 'var(--wa-text)' }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold">{{ item.chat_name }}</div>
                                <p class="mt-1 line-clamp-2 text-xs leading-relaxed" :style="{ color: 'var(--wa-text-secondary)' }">{{ item.reason }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :style="{ color: attentionBadgeColor(item.severity), background: `${attentionBadgeColor(item.severity)}1A` }">
                                {{ waitLabel(item.wait_minutes) }}
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-1.5 text-[11px]">
                            <span v-if="item.unread_count > 0" class="rounded-full px-2 py-0.5" :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text-secondary)' }">
                                непрочитано: {{ item.unread_count }}
                            </span>
                            <span v-if="item.ai_status" class="rounded-full px-2 py-0.5" :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text-secondary)' }">
                                AI: {{ item.ai_status }}
                            </span>
                            <span v-if="item.stage" class="rounded-full px-2 py-0.5" :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text-secondary)' }">
                                {{ item.stage }}
                            </span>
                        </div>
                    </Link>
                </div>
            </section>

            <section
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">Guardrails AI</h2>
                <p class="mt-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                    Срабатывания защит: повторы вопросов, остановки оркестратора и сценарии, где лучше вмешаться менеджеру.
                </p>

                <div v-if="guardrail_events.length === 0" class="mt-4 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                    Защитные остановки пока не срабатывали.
                </div>

                <ul v-else class="mt-4 grid gap-3 lg:grid-cols-2">
                    <li
                        v-for="row in guardrail_events"
                        :key="row.id"
                        class="rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text)' }"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium">{{ row.chat }}</span>
                            <span class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">{{ formatWhen(row.created_at) }}</span>
                        </div>
                        <div class="mt-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ row.status }}
                        </div>
                        <p v-if="row.reason" class="mt-2 text-xs leading-relaxed" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ row.reason }}
                        </p>
                    </li>
                </ul>
            </section>

            <section
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div class="flex flex-col gap-1">
                    <h2 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">Симулятор клиента</h2>
                    <p class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                        Dry-run: AI покажет предполагаемый ответ, этап, действия и риски, но ничего не запишет в чат и воронку.
                    </p>
                </div>

                <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.9fr)]">
                    <div class="space-y-3">
                        <label class="block">
                            <span class="mb-1 block text-xs font-medium" :style="{ color: 'var(--wa-text-secondary)' }">Тестовое сообщение клиента</span>
                            <textarea
                                v-model="simulationMessage"
                                rows="4"
                                class="w-full rounded-xl border px-3 py-2 text-sm focus:outline-none focus:ring-2"
                                :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)', color: 'var(--wa-text)', '--tw-ring-color': 'var(--wa-accent)' }"
                                placeholder="Например: хочу шкаф, когда можно замер?"
                            />
                        </label>

                        <label class="block">
                            <span class="mb-1 block text-xs font-medium" :style="{ color: 'var(--wa-text-secondary)' }">История до сообщения <small>(необязательно)</small></span>
                            <textarea
                                v-model="simulationHistory"
                                rows="3"
                                class="w-full rounded-xl border px-3 py-2 text-sm focus:outline-none focus:ring-2"
                                :style="{ background: 'var(--wa-panel-header)', borderColor: 'var(--wa-border)', color: 'var(--wa-text)', '--tw-ring-color': 'var(--wa-accent)' }"
                                placeholder="Клиент: интересует кухня&#10;Менеджер: уточните размеры"
                            />
                        </label>

                        <button
                            type="button"
                            class="rounded-xl px-4 py-2 text-sm font-semibold disabled:opacity-60"
                            :disabled="simulationLoading || simulationMessage.trim().length === 0"
                            :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                            @click="runSimulation"
                        >
                            {{ simulationLoading ? 'AI думает…' : 'Запустить симуляцию' }}
                        </button>
                    </div>

                    <div class="rounded-xl border p-4" :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }">
                        <div v-if="simulationError" class="text-sm" :style="{ color: 'var(--wa-danger)' }">{{ simulationError }}</div>
                        <div v-else-if="!simulationResult" class="text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                            Результат появится здесь: ответ клиенту, выбранная воронка, этап, действия и предупреждения.
                        </div>
                        <div v-else class="space-y-4">
                            <div>
                                <div class="mb-1 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--wa-accent)' }">Ответ клиенту</div>
                                <div class="rounded-lg px-3 py-2 text-sm leading-relaxed whitespace-pre-wrap" :style="{ background: 'var(--wa-panel)', color: 'var(--wa-text)' }">
                                    {{ simulationResult.customer_reply }}
                                </div>
                            </div>

                            <div class="grid gap-2 text-xs sm:grid-cols-2" :style="{ color: 'var(--wa-text-secondary)' }">
                                <div><span :style="{ color: 'var(--wa-text)' }">Воронка:</span> {{ simulationResult.funnel_name || '—' }}</div>
                                <div><span :style="{ color: 'var(--wa-text)' }">Этап:</span> {{ simulationResult.stage_name || '—' }}</div>
                                <div><span :style="{ color: 'var(--wa-text)' }">Уверенность:</span> {{ simulationConfidence }}%</div>
                                <div><span :style="{ color: 'var(--wa-text)' }">Менеджер:</span> {{ simulationResult.manager_needed ? 'нужен' : 'не нужен' }}</div>
                            </div>

                            <div>
                                <div class="mb-1 text-xs font-semibold" :style="{ color: 'var(--wa-text)' }">Почему так</div>
                                <p class="text-sm leading-relaxed" :style="{ color: 'var(--wa-text-secondary)' }">{{ simulationResult.reason }}</p>
                            </div>

                            <div v-if="simulationResult.actions.length" class="flex flex-wrap gap-2">
                                <span v-for="action in simulationResult.actions" :key="action" class="rounded-full px-2.5 py-1 text-xs" :style="{ background: 'color-mix(in srgb, var(--wa-accent) 12%, transparent)', color: 'var(--wa-text)' }">
                                    {{ action }}
                                </span>
                            </div>

                            <div v-if="simulationResult.missing_data.length || simulationResult.risks.length" class="grid gap-3 md:grid-cols-2">
                                <div v-if="simulationResult.missing_data.length">
                                    <div class="mb-1 text-xs font-semibold" :style="{ color: 'var(--wa-text)' }">Не хватает данных</div>
                                    <ul class="space-y-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                                        <li v-for="item in simulationResult.missing_data" :key="item">• {{ item }}</li>
                                    </ul>
                                </div>
                                <div v-if="simulationResult.risks.length">
                                    <div class="mb-1 text-xs font-semibold" :style="{ color: 'var(--wa-text)' }">Риски</div>
                                    <ul class="space-y-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                                        <li v-for="item in simulationResult.risks" :key="item">• {{ item }}</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">Сбои и блокировки AI</h2>
                <p class="mt-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                    Последние записи со статусом «failed» или «blocked» в журнале ответов AI.
                </p>

                <div v-if="failed_logs.length === 0" class="mt-4 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                    Записей пока нет — это хороший знак.
                </div>

                <ul v-else class="mt-4 space-y-3">
                    <li
                        v-for="row in failed_logs"
                        :key="row.id"
                        class="rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text)' }"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium">{{ row.chat }}</span>
                            <span class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">{{ formatWhen(row.created_at) }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-2 text-xs">
                            <span class="rounded bg-black/5 px-1.5 py-0.5">{{ row.status }}</span>
                            <span v-if="row.mode" class="rounded bg-black/5 px-1.5 py-0.5">{{ row.mode }}</span>
                            <span v-if="row.company">{{ row.company }}</span>
                        </div>
                        <p v-if="row.error" class="mt-2 whitespace-pre-wrap break-words text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ row.error }}
                        </p>
                    </li>
                </ul>
            </section>

            <section
                class="rounded-xl border p-5"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--wa-text)' }">Оценки «нужно улучшить»</h2>
                <p class="mt-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                    Операторы отмечают AI-сообщения в чате; здесь собраны не «Ок», а проблемные категории.
                </p>

                <div v-if="problem_ratings.length === 0" class="mt-4 text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                    Пока нет таких оценок.
                </div>

                <ul v-else class="mt-4 space-y-3">
                    <li
                        v-for="row in problem_ratings"
                        :key="row.id"
                        class="rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text)' }"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium">{{ ratingLabels[row.rating] ?? row.rating }}</span>
                            <span class="text-xs" :style="{ color: 'var(--wa-text-secondary)' }">{{ formatWhen(row.created_at) }}</span>
                        </div>
                        <div class="mt-1 text-xs" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ row.chat }} · {{ row.user ?? 'Оператор' }}
                        </div>
                        <p class="mt-2 whitespace-pre-wrap break-words text-xs opacity-90">{{ row.body_preview }}</p>
                    </li>
                </ul>
            </section>
        </div>
    </SettingsLayout>
</template>
