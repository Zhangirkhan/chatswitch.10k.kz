<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const { t, locale } = useI18n();

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

type AiUsageRow = {
    scenario: string;
    kind: string;
    events_count: number;
    tokens_input: number;
    tokens_output: number;
    audio_seconds: number;
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

const ratingLabels = computed<Record<string, string>>(() => ({
    style: t('settings.aiQuality.ratingStyle'),
    facts: t('settings.aiQuality.ratingFacts'),
    long: t('settings.aiQuality.ratingLong'),
    context: t('settings.aiQuality.ratingContext'),
}));

const simulationMessage = ref(t('settings.aiQuality.defaultTestMessage'));
const simulationHistory = ref('');
const simulationLoading = ref(false);
const simulationError = ref<string | null>(null);
const simulationResult = ref<SimulationResult | null>(null);
const simulationConfidence = computed(() => Math.round((simulationResult.value?.confidence ?? 0) * 100));

const usagePeriodDays = ref(30);
const usageLoading = ref(false);
const usageError = ref<string | null>(null);
const usageRows = ref<AiUsageRow[]>([]);
const dictationSeconds = ref(0);

const dictationMinutes = computed(() => Math.max(0, Math.round(dictationSeconds.value / 60)));

async function loadAiUsage(): Promise<void> {
    usageLoading.value = true;
    usageError.value = null;

    try {
        const { data } = await axios.get(route('settings.ai-usage'), {
            params: { period: usagePeriodDays.value },
        });
        usageRows.value = (data.scenarios ?? []) as AiUsageRow[];
        dictationSeconds.value = Number(data.operator_dictation_seconds ?? 0);
    } catch {
        usageError.value = t('settings.aiQuality.usageLoadError');
    } finally {
        usageLoading.value = false;
    }
}

onMounted(() => {
    void loadAiUsage();
});

const conflictSimulationPresets = computed(() => [
    { id: 'angry', label: t('settings.aiQuality.conflictPresetAngry'), message: t('settings.aiQuality.conflictPresetAngryMessage') },
    { id: 'refund', label: t('settings.aiQuality.conflictPresetRefund'), message: t('settings.aiQuality.conflictPresetRefundMessage') },
    { id: 'repeat', label: t('settings.aiQuality.conflictPresetRepeat'), message: t('settings.aiQuality.conflictPresetRepeatMessage') },
]);

function applyConflictSimulationPreset(message: string): void {
    simulationMessage.value = message;
    simulationResult.value = null;
    simulationError.value = null;
}

const dateLocale = computed(() => (locale.value === 'kk' ? 'kk-KZ' : locale.value === 'en' ? 'en-GB' : 'ru-RU'));

function formatWhen(iso: string | null): string {
    if (!iso) {
        return '—';
    }
    try {
        return new Intl.DateTimeFormat(dateLocale.value, {
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
    if (minutes < 60) return t('settings.aiQuality.waitMinutes', { minutes });
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    return rest > 0
        ? t('settings.aiQuality.waitHoursMinutes', { hours, minutes: rest })
        : t('settings.aiQuality.waitHours', { hours });
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
        simulationError.value = e?.response?.data?.message || t('settings.aiQuality.errorSimulation');
    } finally {
        simulationLoading.value = false;
    }
}
</script>

<template>
    <Head :title="t('settings.aiQuality.title')" />

    <SettingsLayout :title="t('settings.aiQuality.title')" :subtitle="t('settings.aiQuality.subtitle')">
        <div class="w-full space-y-8 px-6 py-6">
            <section
                class="ui-settings-section"
            >
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide" :style="{ color: readinessColor(readiness.status) }">
                            {{ t('settings.aiQuality.readiness', { score: readiness.score }) }}
                        </div>
                        <h2 class="mt-1 text-lg font-semibold" :style="{ color: 'var(--ui-text)' }">{{ readiness.label }}</h2>
                        <p class="mt-1 max-w-2xl text-sm" :style="{ color: 'var(--ui-text-secondary)' }">{{ readiness.summary }}</p>
                    </div>
                    <div class="h-20 w-20 rounded-full p-1" :style="{ background: `conic-gradient(${readinessColor(readiness.status)} ${readiness.score}%, var(--ui-surface-muted) 0)` }">
                        <div class="flex h-full w-full items-center justify-center rounded-full text-lg font-semibold" :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text)' }">
                            {{ readiness.score }}
                        </div>
                    </div>
                </div>

                <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <article
                        v-for="check in readiness.checks"
                        :key="check.key"
                        class="rounded-lg border px-3 py-3"
                        :style="{ borderColor: check.ok ? 'rgba(22, 163, 74, .35)' : 'rgba(220, 38, 38, .35)', background: 'var(--ui-surface-muted)' }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium" :style="{ color: 'var(--ui-text)' }">{{ check.label }}</div>
                                <p class="mt-1 text-xs leading-relaxed" :style="{ color: 'var(--ui-text-secondary)' }">{{ check.hint }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :style="{ color: check.ok ? '#15803d' : '#b91c1c', background: check.ok ? 'rgba(22, 163, 74, .12)' : 'rgba(220, 38, 38, .12)' }">
                                {{ check.ok ? 'OK' : check.value }}
                            </span>
                        </div>
                    </article>
                </div>

                <div v-if="readiness.next_actions.length" class="mt-5 rounded-lg border px-4 py-3" :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }">
                    <div class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.nextActions') }}</div>
                    <ul class="mt-2 space-y-1.5 text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                        <li v-for="action in readiness.next_actions" :key="action">• {{ action }}</li>
                    </ul>
                </div>
            </section>

            <section
                class="ui-settings-section"
            >
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.improvementsTitle') }}</h2>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ t('settings.aiQuality.improvementsDesc') }}
                        </p>
                    </div>
                    <div class="text-xs font-semibold" :style="{ color: 'var(--ui-text-secondary)' }">
                        {{ t('settings.aiQuality.directionsCount', { count: improvement_suggestions.length }) }}
                    </div>
                </div>

                <div v-if="improvement_suggestions.length === 0" class="ui-empty-state ui-empty-state--dashed mt-4">
                    {{ t('settings.aiQuality.improvementsEmpty') }}
                </div>

                <div v-else class="mt-4 grid gap-3 lg:grid-cols-2">
                    <article
                        v-for="item in improvement_suggestions"
                        :key="item.key"
                        class="rounded-lg border px-3 py-3"
                        :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ item.label }}</h3>
                                <p class="mt-1 text-xs leading-relaxed" :style="{ color: 'var(--ui-text-secondary)' }">{{ item.action }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :style="{ color: 'var(--ui-accent)', background: 'color-mix(in srgb, var(--ui-accent) 12%, transparent)' }">
                                {{ item.count }}
                            </span>
                        </div>

                        <div v-if="item.examples.length" class="mt-3 space-y-2">
                            <div
                                v-for="example in item.examples"
                                :key="`${item.key}-${example.chat}-${example.created_at}`"
                                class="rounded-lg px-2.5 py-2 text-xs"
                                :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text-secondary)' }"
                            >
                                <div class="mb-1 flex items-center justify-between gap-2">
                                    <span class="font-medium" :style="{ color: 'var(--ui-text)' }">{{ example.chat }}</span>
                                    <span>{{ formatWhen(example.created_at) }}</span>
                                </div>
                                <div class="line-clamp-2">{{ example.preview || t('settings.aiQuality.noText') }}</div>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section
                class="ui-settings-section"
            >
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.auditTitle') }}</h2>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ t('settings.aiQuality.auditDesc') }}
                        </p>
                    </div>
                    <div class="text-xs font-semibold" :style="{ color: 'var(--ui-text-secondary)' }">
                        {{ t('settings.aiQuality.recommendationsCount', { count: configuration_audit.length }) }}
                    </div>
                </div>

                <div v-if="configuration_audit.length === 0" class="ui-empty-state ui-empty-state--dashed mt-4">
                    {{ t('settings.aiQuality.auditEmpty') }}
                </div>

                <div v-else class="mt-4 grid gap-3 lg:grid-cols-2">
                    <article
                        v-for="item in configuration_audit"
                        :key="item.key"
                        class="rounded-lg border px-3 py-3"
                        :style="{ borderColor: `${auditColor(item.severity)}55`, background: 'var(--ui-surface-muted)' }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :style="{ color: auditColor(item.severity), background: `${auditColor(item.severity)}1A` }">
                                        {{ item.category }}
                                    </span>
                                    <span class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ item.title }}</span>
                                </div>
                                <p class="mt-2 text-xs leading-relaxed" :style="{ color: 'var(--ui-text-secondary)' }">{{ item.description }}</p>
                                <p class="mt-2 text-xs font-medium" :style="{ color: 'var(--ui-text)' }">{{ item.action }}</p>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <section
                class="ui-settings-section"
            >
                <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.attentionTitle') }}</h2>
                        <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ t('settings.aiQuality.attentionDesc') }}
                        </p>
                    </div>
                    <div class="text-xs font-semibold" :style="{ color: 'var(--ui-text-secondary)' }">
                        {{ t('settings.aiQuality.attentionActive', { count: attention_queue.length }) }}
                    </div>
                </div>

                <div v-if="attention_queue.length === 0" class="ui-empty-state ui-empty-state--dashed mt-4">
                    {{ t('settings.aiQuality.attentionEmpty') }}
                </div>

                <div v-else class="mt-4 grid gap-3 xl:grid-cols-2">
                    <Link
                        v-for="item in attention_queue"
                        :key="item.id"
                        :href="route('chats.show', item.id)"
                        class="rounded-lg border px-3 py-3 transition hover:-translate-y-0.5"
                        :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold">{{ item.chat_name }}</div>
                                <p class="mt-1 line-clamp-2 text-xs leading-relaxed" :style="{ color: 'var(--ui-text-secondary)' }">{{ item.reason }}</p>
                            </div>
                            <span class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold" :style="{ color: attentionBadgeColor(item.severity), background: `${attentionBadgeColor(item.severity)}1A` }">
                                {{ waitLabel(item.wait_minutes) }}
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap gap-1.5 text-[11px]">
                            <span v-if="item.unread_count > 0" class="rounded-full px-2 py-0.5" :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text-secondary)' }">
                                {{ t('settings.aiQuality.unread', { count: item.unread_count }) }}
                            </span>
                            <span v-if="item.ai_status" class="rounded-full px-2 py-0.5" :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text-secondary)' }">
                                AI: {{ item.ai_status }}
                            </span>
                            <span v-if="item.stage" class="rounded-full px-2 py-0.5" :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text-secondary)' }">
                                {{ item.stage }}
                            </span>
                        </div>
                    </Link>
                </div>
            </section>

            <section
                class="ui-settings-section"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.guardrailsTitle') }}</h2>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.aiQuality.guardrailsDesc') }}
                </p>

                <div v-if="guardrail_events.length === 0" class="mt-4 text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.aiQuality.guardrailsEmpty') }}
                </div>

                <ul v-else class="mt-4 grid gap-3 lg:grid-cols-2">
                    <li
                        v-for="row in guardrail_events"
                        :key="row.id"
                        class="rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--ui-border)', color: 'var(--ui-text)' }"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium">{{ row.chat }}</span>
                            <span class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ formatWhen(row.created_at) }}</span>
                        </div>
                        <div class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ row.status }}
                        </div>
                        <p v-if="row.reason" class="mt-2 text-xs leading-relaxed" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ row.reason }}
                        </p>
                    </li>
                </ul>
            </section>

            <section
                class="ui-settings-section"
            >
                <div class="flex flex-col gap-1">
                    <h2 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.simulatorTitle') }}</h2>
                    <p class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                        {{ t('settings.aiQuality.simulatorDesc') }}
                    </p>
                </div>

                <div class="mt-4 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(340px,0.9fr)]">
                    <div class="space-y-3">
                        <div>
                            <span class="mb-1 block text-xs font-medium" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.aiQuality.conflictPresetsLabel') }}</span>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="preset in conflictSimulationPresets"
                                    :key="preset.id"
                                    type="button"
                                    class="ui-btn ui-btn--ghost ui-btn--sm"
                                    @click="applyConflictSimulationPreset(preset.message)"
                                >
                                    {{ preset.label }}
                                </button>
                            </div>
                        </div>

                        <label class="block">
                            <span class="mb-1 block text-xs font-medium" :style="{ color: 'var(--ui-text-secondary)' }">{{ t('settings.aiQuality.testMessage') }}</span>
                            <textarea
                                v-model="simulationMessage"
                                rows="4"
                                class="settings-input w-full"
                                :placeholder="t('settings.aiQuality.testMessagePlaceholder')"
                            />
                        </label>

                        <label class="block">
                            <span class="mb-1 block text-xs font-medium" :style="{ color: 'var(--ui-text-secondary)' }">
                                {{ t('settings.aiQuality.historyLabel') }} <small>{{ t('settings.aiQuality.historyOptional') }}</small>
                            </span>
                            <textarea
                                v-model="simulationHistory"
                                rows="3"
                                class="settings-input w-full"
                                :placeholder="t('settings.aiQuality.historyPlaceholder')"
                            />
                        </label>

                        <button
                            type="button"
                            class="ui-btn ui-btn--primary"
                            :disabled="simulationLoading || simulationMessage.trim().length === 0"
                            @click="runSimulation"
                        >
                            {{ simulationLoading ? t('settings.aiQuality.aiThinking') : t('settings.aiQuality.runSimulation') }}
                        </button>
                    </div>

                    <div class="ui-panel p-4" :style="{ background: 'var(--ui-surface-muted)' }">
                        <div v-if="simulationError" class="text-sm" :style="{ color: 'var(--ui-danger)' }">{{ simulationError }}</div>
                        <div v-else-if="!simulationResult" class="text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ t('settings.aiQuality.simulatorEmpty') }}
                        </div>
                        <div v-else class="space-y-4">
                            <div>
                                <div class="mb-1 text-xs font-semibold uppercase tracking-wide" :style="{ color: 'var(--ui-accent)' }">{{ t('settings.aiQuality.customerReply') }}</div>
                                <div class="rounded-lg px-3 py-2 text-sm leading-relaxed whitespace-pre-wrap" :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text)' }">
                                    {{ simulationResult.customer_reply }}
                                </div>
                            </div>

                            <div class="grid gap-2 text-xs sm:grid-cols-2" :style="{ color: 'var(--ui-text-secondary)' }">
                                <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.funnel') }}</span> {{ simulationResult.funnel_name || '—' }}</div>
                                <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.stage') }}</span> {{ simulationResult.stage_name || '—' }}</div>
                                <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.confidence') }}</span> {{ simulationConfidence }}%</div>
                                <div><span :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.manager') }}</span> {{ simulationResult.manager_needed ? t('settings.aiQuality.managerNeeded') : t('settings.aiQuality.managerNotNeeded') }}</div>
                            </div>

                            <div>
                                <div class="mb-1 text-xs font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.whySo') }}</div>
                                <p class="text-sm leading-relaxed" :style="{ color: 'var(--ui-text-secondary)' }">{{ simulationResult.reason }}</p>
                            </div>

                            <div v-if="simulationResult.actions.length" class="flex flex-wrap gap-2">
                                <span v-for="action in simulationResult.actions" :key="action" class="rounded-full px-2.5 py-1 text-xs" :style="{ background: 'color-mix(in srgb, var(--ui-accent) 12%, transparent)', color: 'var(--ui-text)' }">
                                    {{ action }}
                                </span>
                            </div>

                            <div v-if="simulationResult.missing_data.length || simulationResult.risks.length" class="grid gap-3 md:grid-cols-2">
                                <div v-if="simulationResult.missing_data.length">
                                    <div class="mb-1 text-xs font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.missingData') }}</div>
                                    <ul class="space-y-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                                        <li v-for="item in simulationResult.missing_data" :key="item">• {{ item }}</li>
                                    </ul>
                                </div>
                                <div v-if="simulationResult.risks.length">
                                    <div class="mb-1 text-xs font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.risks') }}</div>
                                    <ul class="space-y-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                                        <li v-for="item in simulationResult.risks" :key="item">• {{ item }}</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ui-settings-section">
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.usageTitle') }}</h2>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.aiQuality.usageDesc') }}
                </p>
                <p class="mt-2 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.aiQuality.usagePeriodDays', { days: usagePeriodDays }) }}
                    · {{ t('settings.aiQuality.usageDictation', { minutes: dictationMinutes }) }}
                </p>

                <div v-if="usageLoading" class="mt-4 text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.aiQuality.aiThinking') }}
                </div>
                <div v-else-if="usageError" class="mt-4 text-sm text-red-600">
                    {{ usageError }}
                </div>
                <div v-else-if="usageRows.length === 0" class="mt-4 text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.aiQuality.usageEmpty') }}
                </div>
                <div v-else class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead>
                            <tr :style="{ color: 'var(--ui-text-secondary)' }">
                                <th class="px-2 py-1 font-medium">{{ t('settings.aiQuality.usageScenario') }}</th>
                                <th class="px-2 py-1 font-medium">{{ t('settings.aiQuality.usageKind') }}</th>
                                <th class="px-2 py-1 font-medium">{{ t('settings.aiQuality.usageEvents') }}</th>
                                <th class="px-2 py-1 font-medium">{{ t('settings.aiQuality.usageAudioSeconds') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in usageRows"
                                :key="`${row.scenario}-${row.kind}`"
                                class="border-t"
                                :style="{ borderColor: 'var(--ui-border)', color: 'var(--ui-text)' }"
                            >
                                <td class="px-2 py-2 font-medium">{{ row.scenario }}</td>
                                <td class="px-2 py-2">{{ row.kind }}</td>
                                <td class="px-2 py-2">{{ row.events_count }}</td>
                                <td class="px-2 py-2">{{ row.audio_seconds }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section
                class="ui-settings-section"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.failuresTitle') }}</h2>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.aiQuality.failuresDesc') }}
                </p>

                <div v-if="failed_logs.length === 0" class="mt-4 text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.aiQuality.failuresEmpty') }}
                </div>

                <ul v-else class="mt-4 space-y-3">
                    <li
                        v-for="row in failed_logs"
                        :key="row.id"
                        class="rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--ui-border)', color: 'var(--ui-text)' }"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium">{{ row.chat }}</span>
                            <span class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ formatWhen(row.created_at) }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-2 text-xs">
                            <span class="rounded bg-black/5 px-1.5 py-0.5">{{ row.status }}</span>
                            <span v-if="row.mode" class="rounded bg-black/5 px-1.5 py-0.5">{{ row.mode }}</span>
                            <span v-if="row.company">{{ row.company }}</span>
                        </div>
                        <p v-if="row.error" class="mt-2 whitespace-pre-wrap break-words text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ row.error }}
                        </p>
                    </li>
                </ul>
            </section>

            <section
                class="ui-settings-section"
            >
                <h2 class="text-sm font-semibold" :style="{ color: 'var(--ui-text)' }">{{ t('settings.aiQuality.ratingsTitle') }}</h2>
                <p class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.aiQuality.ratingsDesc') }}
                </p>

                <div v-if="problem_ratings.length === 0" class="mt-4 text-sm" :style="{ color: 'var(--ui-text-secondary)' }">
                    {{ t('settings.aiQuality.ratingsEmpty') }}
                </div>

                <ul v-else class="mt-4 space-y-3">
                    <li
                        v-for="row in problem_ratings"
                        :key="row.id"
                        class="rounded-lg border px-3 py-2 text-sm"
                        :style="{ borderColor: 'var(--ui-border)', color: 'var(--ui-text)' }"
                    >
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <span class="font-medium">{{ ratingLabels[row.rating] ?? row.rating }}</span>
                            <span class="text-xs" :style="{ color: 'var(--ui-text-secondary)' }">{{ formatWhen(row.created_at) }}</span>
                        </div>
                        <div class="mt-1 text-xs" :style="{ color: 'var(--ui-text-secondary)' }">
                            {{ row.chat }} · {{ row.user ?? t('settings.aiQuality.operator') }}
                        </div>
                        <p class="mt-2 whitespace-pre-wrap break-words text-xs opacity-90">{{ row.body_preview }}</p>
                    </li>
                </ul>
            </section>
        </div>
    </SettingsLayout>
</template>
