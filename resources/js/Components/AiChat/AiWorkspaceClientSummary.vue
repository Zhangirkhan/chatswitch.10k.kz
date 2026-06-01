<script setup lang="ts">
import UserAvatar from '@/Components/UserAvatar.vue';
import type { ClientSummary, WorkspaceContact } from '@/Components/AiChat/aiWorkspaceTypes';
import { Link } from '@inertiajs/vue3';
import { formatPhone } from '@/utils/phone';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        summary: ClientSummary | null;
        loading: boolean;
        contacts?: WorkspaceContact[];
        focusedContactId?: number | null;
        variant?: 'workspace' | 'chat';
        hideOpenChat?: boolean;
        emptyHint?: string | null;
        expanded?: boolean;
    }>(),
    {
        contacts: () => [],
        focusedContactId: null,
        variant: 'workspace',
        hideOpenChat: false,
        emptyHint: null,
        expanded: false,
    },
);

const emptyMessage = computed(() => {
    if (props.emptyHint) {
        return props.emptyHint;
    }
    if (props.variant === 'chat') {
        return 'Сводка появится, когда к чату привязан контакт CRM.';
    }
    return 'Спросите про клиента или выберите контакт во вкладке «Контакты».';
});

const emit = defineEmits<{
    selectContact: [contactId: number];
}>();

const confidenceLabel = computed(() => {
    const level = props.summary?.ai.confidence;
    if (level === 'high') {
        return 'Много данных';
    }
    if (level === 'medium') {
        return 'Частичные данные';
    }
    return 'Мало данных';
});

function onPickContact(event: Event): void {
    const value = Number((event.target as HTMLSelectElement).value);
    if (value > 0) {
        emit('selectContact', value);
    }
}

function sectionLayout(body: string): 'inline' | 'half' | 'full' {
    const text = body.trim();
    if (text.length === 0 || /^нет данных\.?$/i.test(text)) {
        return 'inline';
    }
    if (text.length <= 48) {
        return 'inline';
    }
    if (text.length <= 130) {
        return 'half';
    }
    return 'full';
}

type SectionSemantic = 'who' | 'preferences' | 'context' | 'agreements' | 'deal' | 'neutral';

function sectionSemantic(title: string): SectionSemantic {
    const t = title.toLowerCase();

    if (/кто|профил|клиент|identity|who/.test(t)) {
        return 'who';
    }
    if (/предпочт|preferen|вкус|стиль/.test(t)) {
        return 'preferences';
    }
    if (/контекст|локац|context|location|ситуац/.test(t)) {
        return 'context';
    }
    if (/договор|согласован|agreement|обещан|обязатель/.test(t)) {
        return 'agreements';
    }
    if (/сделк|этап|следующ|шаг|deal|stage|next|воронк/.test(t)) {
        return 'deal';
    }

    return 'neutral';
}
</script>

<template>
    <section
        class="ai-client-summary"
        :class="[
            `ai-client-summary--${variant}`,
            { 'ai-client-summary--expanded': expanded },
        ]"
    >
        <header class="ai-client-summary__head">
            <h2 class="ai-client-summary__title">Сводка клиента</h2>
            <span
                v-if="summary && !loading"
                class="ai-client-summary__badge"
                :class="`ai-client-summary__badge--${summary.ai.confidence}`"
            >
                {{ confidenceLabel }}
            </span>
        </header>

        <div v-if="loading" class="ai-client-summary__loading" role="status" aria-live="polite">
            <div class="ai-client-summary__loading-visual" aria-hidden="true">
                <span class="ai-client-summary__loading-ring ai-client-summary__loading-ring--outer" />
                <span class="ai-client-summary__loading-ring ai-client-summary__loading-ring--inner" />
                <span class="ai-client-summary__loading-core" />
            </div>
            <p class="ai-client-summary__loading-text">
                Собираем профиль
                <span class="ai-client-summary__loading-dots">
                    <span>.</span><span>.</span><span>.</span>
                </span>
            </p>
        </div>

        <div v-else-if="!summary" class="ai-client-summary__empty">
            <p>{{ emptyMessage }}</p>
        </div>

        <div v-else class="ai-client-summary__body wa-scrollbar">
            <div class="ai-client-summary__identity">
                <UserAvatar
                    :name="summary.identity.display_name"
                    :src="summary.identity.avatar"
                    :size="34"
                />
                <div class="min-w-0">
                    <div class="ai-client-summary__name">{{ summary.identity.display_name }}</div>
                    <p v-if="summary.identity.phone" class="ai-client-summary__meta">
                        {{ formatPhone(summary.identity.phone) || summary.identity.phone }}
                    </p>
                    <p v-if="summary.identity.companies.length" class="ai-client-summary__meta truncate">
                        {{ summary.identity.companies.join(', ') }}
                    </p>
                </div>
            </div>

            <select
                v-if="contacts.length > 1"
                class="ai-client-summary__picker"
                :value="focusedContactId ?? summary.contact_id"
                @change="onPickContact"
            >
                <option v-for="c in contacts" :key="c.id" :value="c.id">
                    {{ c.name }}
                </option>
            </select>

            <p class="ai-client-summary__headline">{{ summary.ai.headline }}</p>

            <div
                v-if="summary.crm.deal?.funnel?.name || summary.crm.deal?.stage?.name || summary.crm.upcoming_events_count || summary.crm.open_tasks_count"
                class="ai-client-summary__chips"
            >
                <span v-if="summary.crm.deal?.funnel?.name" class="ai-client-summary__chip ai-client-summary__chip--who">
                    {{ summary.crm.deal.funnel.name }}
                </span>
                <span v-if="summary.crm.deal?.stage?.name" class="ai-client-summary__chip ai-client-summary__chip--context">
                    {{ summary.crm.deal.stage.name }}
                </span>
                <span v-if="summary.crm.upcoming_events_count" class="ai-client-summary__chip ai-client-summary__chip--prefs">
                    Записей: {{ summary.crm.upcoming_events_count }}
                </span>
                <span v-if="summary.crm.open_tasks_count" class="ai-client-summary__chip ai-client-summary__chip--agreements">
                    Задач: {{ summary.crm.open_tasks_count }}
                </span>
            </div>

            <div class="ai-client-summary__mosaic">
                <article
                    v-for="(section, index) in summary.ai.sections"
                    :key="section.title"
                    class="ai-client-summary__section"
                    :class="[
                        `ai-client-summary__section--${sectionLayout(section.body)}`,
                        `ai-client-summary__section--semantic-${sectionSemantic(section.title)}`,
                    ]"
                >
                    <h3>{{ section.title }}</h3>
                    <p>{{ section.body }}</p>
                </article>
            </div>

            <div v-if="!hideOpenChat && summary.primary_chat_id" class="ai-client-summary__actions">
                <Link
                    :href="route('chats.show', summary.primary_chat_id)"
                    class="ai-client-summary__btn ai-client-summary__btn--primary"
                >
                    Открыть чат
                </Link>
            </div>
        </div>
    </section>
</template>

<style scoped>
.ai-client-summary {
    --sem-who: #9b8fb8;
    --sem-who-bg: color-mix(in srgb, var(--sem-who) 9%, var(--wa-panel));
    --sem-who-border: color-mix(in srgb, var(--sem-who) 28%, var(--wa-border));
    --sem-prefs: #7a92b0;
    --sem-prefs-bg: color-mix(in srgb, var(--sem-prefs) 9%, var(--wa-panel));
    --sem-prefs-border: color-mix(in srgb, var(--sem-prefs) 28%, var(--wa-border));
    --sem-context: #b8945f;
    --sem-context-bg: color-mix(in srgb, var(--sem-context) 10%, var(--wa-panel));
    --sem-context-border: color-mix(in srgb, var(--sem-context) 30%, var(--wa-border));
    --sem-agreements: #6fa384;
    --sem-agreements-bg: color-mix(in srgb, var(--sem-agreements) 10%, var(--wa-panel));
    --sem-agreements-border: color-mix(in srgb, var(--sem-agreements) 30%, var(--wa-border));
    --sem-deal: #8890b5;
    --sem-deal-bg: color-mix(in srgb, var(--sem-deal) 9%, var(--wa-panel));
    --sem-deal-border: color-mix(in srgb, var(--sem-deal) 28%, var(--wa-border));

    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    max-height: min(42vh, 22rem);
    border-bottom: 1px solid var(--wa-sidebar-divider);
    background: var(--wa-panel);
    color: var(--wa-text);
}

.ai-client-summary--chat {
    max-height: min(36vh, 19rem);
}

.ai-client-summary--expanded {
    flex: 1 1 auto;
    min-height: 0;
    max-height: none;
    flex-shrink: 1;
}

.ai-client-summary--expanded .ai-client-summary__loading,
.ai-client-summary--expanded .ai-client-summary__empty {
    flex: 1;
    min-height: 0;
}

.ai-client-summary__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 14px 16px 10px;
    flex-shrink: 0;
    border-bottom: 1px solid color-mix(in srgb, var(--wa-sidebar-divider) 70%, transparent);
}

.ai-client-summary__title {
    margin: 0;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--wa-text-secondary);
}

.ai-client-summary__badge {
    font-size: 0.625rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 3px 8px;
    border-radius: 6px;
    border: 1px solid var(--wa-border);
    color: var(--wa-text-secondary);
    background: transparent;
}

.ai-client-summary__badge--high,
.ai-client-summary__badge--medium,
.ai-client-summary__badge--low {
    color: var(--wa-text-secondary);
    background: color-mix(in srgb, var(--wa-text) 4%, var(--wa-panel));
    border-color: var(--wa-border);
}

.ai-client-summary__loading,
.ai-client-summary__empty {
    font-size: 0.8125rem;
    line-height: 1.5;
    color: var(--wa-text-secondary);
}

.ai-client-summary__empty {
    padding: 16px;
}

.ai-client-summary__loading {
    flex: 1;
    min-height: 8rem;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 14px;
    padding: 24px 16px;
    text-align: center;
    animation: ai-summary-loading-in 0.45s ease both;
}

.ai-client-summary__loading-visual {
    position: relative;
    width: 2.75rem;
    height: 2.75rem;
}

.ai-client-summary__loading-ring {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    border: 1px solid color-mix(in srgb, var(--wa-text-secondary) 28%, transparent);
    opacity: 0;
}

.ai-client-summary__loading-ring--outer {
    animation: ai-summary-pulse-ring 2.4s ease-out infinite;
}

.ai-client-summary__loading-ring--inner {
    inset: 6px;
    animation: ai-summary-pulse-ring 2.4s ease-out 0.9s infinite;
}

.ai-client-summary__loading-core {
    position: absolute;
    inset: 11px;
    border-radius: 50%;
    border: 2px solid color-mix(in srgb, var(--wa-text-secondary) 18%, transparent);
    border-top-color: color-mix(in srgb, var(--wa-text-secondary) 72%, transparent);
    animation: ai-summary-spin 0.85s linear infinite;
}

.ai-client-summary__loading-text {
    margin: 0;
    font-size: 0.8125rem;
    font-weight: 500;
    letter-spacing: 0.01em;
    color: color-mix(in srgb, var(--wa-text) 78%, var(--wa-text-secondary));
    animation: ai-summary-text-breathe 2.4s ease-in-out infinite;
}

.ai-client-summary__loading-dots span {
    display: inline-block;
    opacity: 0.25;
    animation: ai-summary-dot 1.2s ease-in-out infinite;
}

.ai-client-summary__loading-dots span:nth-child(2) {
    animation-delay: 0.15s;
}

.ai-client-summary__loading-dots span:nth-child(3) {
    animation-delay: 0.3s;
}

@keyframes ai-summary-loading-in {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes ai-summary-pulse-ring {
    0% {
        opacity: 0.55;
        transform: scale(0.72);
    }
    70% {
        opacity: 0;
        transform: scale(1.15);
    }
    100% {
        opacity: 0;
        transform: scale(1.15);
    }
}

@keyframes ai-summary-text-breathe {
    0%, 100% {
        opacity: 0.72;
    }
    50% {
        opacity: 1;
    }
}

@keyframes ai-summary-dot {
    0%, 60%, 100% {
        opacity: 0.2;
        transform: translateY(0);
    }
    30% {
        opacity: 1;
        transform: translateY(-2px);
    }
}

.ai-client-summary__body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 10px 16px 16px;
}

.ai-client-summary__identity {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
    padding-bottom: 12px;
    border-bottom: 1px solid color-mix(in srgb, var(--wa-border) 80%, transparent);
}

.ai-client-summary__name {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.25;
    color: var(--wa-text);
}

.ai-client-summary__meta {
    margin: 3px 0 0;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
    font-variant-numeric: tabular-nums;
}

.ai-client-summary__picker {
    width: 100%;
    margin-bottom: 12px;
    padding: 8px 10px;
    border-radius: 10px;
    border: 1px solid var(--wa-control-rim);
    background: var(--wa-control-surface);
    color: var(--wa-text);
    font-size: 0.8125rem;
}

.ai-client-summary__headline {
    margin: 0 0 12px;
    padding: 0 0 0 10px;
    font-size: 0.9375rem;
    line-height: 1.45;
    font-weight: 500;
    color: var(--wa-text);
    border-left: 3px solid var(--sem-context);
}

.ai-client-summary__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 14px;
}

.ai-client-summary__chip {
    font-size: 0.6875rem;
    font-weight: 500;
    padding: 3px 9px;
    border-radius: 999px;
    border: 1px solid var(--wa-border);
    background: transparent;
}

.ai-client-summary__chip--who {
    color: var(--sem-who);
    border-color: var(--sem-who-border);
    background: var(--sem-who-bg);
}

.ai-client-summary__chip--prefs {
    color: var(--sem-prefs);
    border-color: var(--sem-prefs-border);
    background: var(--sem-prefs-bg);
}

.ai-client-summary__chip--context {
    color: var(--sem-context);
    border-color: var(--sem-context-border);
    background: var(--sem-context-bg);
}

.ai-client-summary__chip--agreements {
    color: var(--sem-agreements);
    border-color: var(--sem-agreements-border);
    background: var(--sem-agreements-bg);
}

.ai-client-summary__mosaic {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 10px;
    align-items: flex-start;
    align-content: flex-start;
}

.ai-client-summary__section {
    min-width: 0;
}

.ai-client-summary__section--inline,
.ai-client-summary__section--half,
.ai-client-summary__section--full {
    border-left-style: solid;
}

.ai-client-summary__section--inline {
    flex: 0 1 auto;
    max-width: calc(50% - 5px);
    padding: 7px 10px 7px 9px;
    border-radius: 10px;
    border-width: 1px 1px 1px 3px;
    border-style: solid;
}

.ai-client-summary__section--half {
    flex: 1 1 calc(50% - 6px);
    min-width: min(100%, 9.5rem);
    padding: 8px 10px 9px 9px;
    border-radius: 10px;
    border-width: 1px 1px 1px 3px;
    border-style: solid;
}

.ai-client-summary__section--full {
    flex: 1 1 100%;
    padding: 6px 0 10px 9px;
    border-radius: 0;
    border-top: none;
    border-right: none;
    border-bottom: 1px solid color-mix(in srgb, var(--wa-border) 70%, transparent);
    border-left-width: 3px;
}

.ai-client-summary__section--full:last-child {
    border-bottom: none;
    padding-bottom: 0;
}

.ai-client-summary__section--semantic-who {
    border-color: var(--sem-who-border) var(--sem-who-border) var(--sem-who-border) var(--sem-who);
    background: var(--sem-who-bg);
}

.ai-client-summary__section--semantic-who h3 {
    color: var(--sem-who);
}

.ai-client-summary__section--semantic-preferences {
    border-color: var(--sem-prefs-border) var(--sem-prefs-border) var(--sem-prefs-border) var(--sem-prefs);
    background: var(--sem-prefs-bg);
}

.ai-client-summary__section--semantic-preferences h3 {
    color: var(--sem-prefs);
}

.ai-client-summary__section--semantic-context {
    border-color: var(--sem-context-border) var(--sem-context-border) var(--sem-context-border) var(--sem-context);
    background: var(--sem-context-bg);
}

.ai-client-summary__section--semantic-context h3 {
    color: var(--sem-context);
}

.ai-client-summary__section--semantic-agreements {
    border-color: var(--sem-agreements-border) var(--sem-agreements-border) var(--sem-agreements-border) var(--sem-agreements);
    background: var(--sem-agreements-bg);
}

.ai-client-summary__section--semantic-agreements h3 {
    color: var(--sem-agreements);
}

.ai-client-summary__section--semantic-deal {
    border-color: var(--sem-deal-border) var(--sem-deal-border) var(--sem-deal-border) var(--sem-deal);
    background: var(--sem-deal-bg);
}

.ai-client-summary__section--semantic-deal h3 {
    color: var(--sem-deal);
}

.ai-client-summary__section--semantic-neutral {
    border-color: color-mix(in srgb, var(--wa-border) 85%, transparent);
    border-left-color: color-mix(in srgb, var(--wa-text-secondary) 35%, var(--wa-border));
    background: color-mix(in srgb, var(--wa-text) 3%, var(--wa-panel));
}

.ai-client-summary__section--semantic-neutral h3 {
    color: var(--wa-text-secondary);
}

.ai-client-summary__section--full.ai-client-summary__section--semantic-who,
.ai-client-summary__section--full.ai-client-summary__section--semantic-preferences,
.ai-client-summary__section--full.ai-client-summary__section--semantic-context,
.ai-client-summary__section--full.ai-client-summary__section--semantic-agreements,
.ai-client-summary__section--full.ai-client-summary__section--semantic-deal,
.ai-client-summary__section--full.ai-client-summary__section--semantic-neutral {
    border-bottom-color: color-mix(in srgb, var(--wa-border) 55%, transparent);
}

.ai-client-summary__section h3 {
    margin: 0 0 3px;
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
}

.ai-client-summary__section--inline h3 {
    margin-bottom: 2px;
}

.ai-client-summary__section p {
    margin: 0;
    font-size: 0.8125rem;
    line-height: 1.45;
    color: color-mix(in srgb, var(--wa-text) 90%, var(--wa-text-secondary));
}

.ai-client-summary__section--inline p {
    font-size: 0.75rem;
    line-height: 1.35;
}

.ai-client-summary__actions {
    display: flex;
    gap: 8px;
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid var(--wa-sidebar-divider);
}

.ai-client-summary__btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 2rem;
    padding: 0 12px;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 600;
    text-decoration: none;
}

.ai-client-summary__btn--primary {
    background: var(--wa-accent);
    color: var(--wa-accent-on, #fff);
}

@keyframes ai-summary-spin {
    to {
        transform: rotate(360deg);
    }
}
</style>
