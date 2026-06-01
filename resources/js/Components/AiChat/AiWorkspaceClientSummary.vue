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

        <div v-if="loading" class="ai-client-summary__loading">
            <span class="ai-client-summary__spinner" aria-hidden="true"></span>
            <span>Собираем профиль…</span>
        </div>

        <div v-else-if="!summary" class="ai-client-summary__empty">
            <p>{{ emptyMessage }}</p>
        </div>

        <div v-else class="ai-client-summary__body wa-scrollbar">
            <div class="ai-client-summary__identity-card">
                <div class="ai-client-summary__identity">
                    <UserAvatar
                        :name="summary.identity.display_name"
                        :src="summary.identity.avatar"
                        :size="36"
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
                <span v-if="summary.crm.deal?.funnel?.name" class="ai-client-summary__chip ai-client-summary__chip--violet">
                    {{ summary.crm.deal.funnel.name }}
                </span>
                <span v-if="summary.crm.deal?.stage?.name" class="ai-client-summary__chip ai-client-summary__chip--amber">
                    {{ summary.crm.deal.stage.name }}
                </span>
                <span v-if="summary.crm.upcoming_events_count" class="ai-client-summary__chip ai-client-summary__chip--sky">
                    Записей: {{ summary.crm.upcoming_events_count }}
                </span>
                <span v-if="summary.crm.open_tasks_count" class="ai-client-summary__chip ai-client-summary__chip--rose">
                    Задач: {{ summary.crm.open_tasks_count }}
                </span>
            </div>

            <div class="ai-client-summary__sections">
                <article
                    v-for="(section, index) in summary.ai.sections"
                    :key="section.title"
                    class="ai-client-summary__section"
                    :class="`ai-client-summary__section--tone-${(index % 5) + 1}`"
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
    --summary-violet-bg: var(--wa-chroma-violet-bg-18, color-mix(in srgb, #8b5cf6 14%, var(--wa-panel)));
    --summary-violet-fg: var(--wa-chroma-violet-fg, #a78bfa);
    --summary-violet-border: var(--wa-chroma-violet-border-38, color-mix(in srgb, #8b5cf6 32%, var(--wa-border)));
    --summary-amber-bg: var(--wa-chroma-amber-bg-12, color-mix(in srgb, #d97706 12%, var(--wa-panel)));
    --summary-amber-fg: #d4a054;
    --summary-amber-border: var(--wa-chroma-amber-border-45, color-mix(in srgb, #d97706 38%, var(--wa-border)));
    --summary-sky-bg: color-mix(in srgb, #5b8def 13%, var(--wa-panel));
    --summary-sky-fg: #8eb0e8;
    --summary-sky-border: color-mix(in srgb, #5b8def 34%, var(--wa-border));
    --summary-rose-bg: color-mix(in srgb, #c97b8e 12%, var(--wa-panel));
    --summary-rose-fg: #d4a0ad;
    --summary-rose-border: color-mix(in srgb, #c97b8e 32%, var(--wa-border));
    --summary-teal-bg: color-mix(in srgb, #5aaeb8 12%, var(--wa-panel));
    --summary-teal-fg: #7ec4cc;
    --summary-teal-border: color-mix(in srgb, #5aaeb8 32%, var(--wa-border));

    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    max-height: min(42vh, 22rem);
    border-bottom: 1px solid var(--wa-sidebar-divider);
    background:
        radial-gradient(ellipse 120% 80% at 0% 0%, color-mix(in srgb, #8b5cf6 7%, transparent) 0%, transparent 55%),
        radial-gradient(ellipse 90% 70% at 100% 100%, color-mix(in srgb, #d97706 6%, transparent) 0%, transparent 50%),
        var(--wa-panel);
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
    color: var(--summary-violet-fg);
}

.ai-client-summary__badge {
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 3px 8px;
    border-radius: 6px;
    border: 1px solid var(--wa-control-rim);
    color: var(--wa-text-secondary);
    background: var(--wa-control-surface);
}

.ai-client-summary__badge--high {
    color: var(--summary-sky-fg);
    background: var(--summary-sky-bg);
    border-color: var(--summary-sky-border);
}

.ai-client-summary__badge--medium {
    color: var(--summary-amber-fg);
    background: var(--summary-amber-bg);
    border-color: var(--summary-amber-border);
}

.ai-client-summary__badge--low {
    color: color-mix(in srgb, var(--wa-text-secondary) 90%, var(--summary-rose-fg));
    background: var(--summary-rose-bg);
    border-color: var(--summary-rose-border);
}

.ai-client-summary__loading,
.ai-client-summary__empty {
    padding: 16px;
    font-size: 0.8125rem;
    line-height: 1.5;
    color: var(--wa-text-secondary);
}

.ai-client-summary__loading {
    display: flex;
    align-items: center;
    gap: 10px;
}

.ai-client-summary__spinner {
    width: 0.875rem;
    height: 0.875rem;
    border-radius: 50%;
    border: 2px solid color-mix(in srgb, var(--summary-violet-fg) 25%, transparent);
    border-top-color: var(--summary-violet-fg);
    animation: ai-summary-spin 0.7s linear infinite;
}

.ai-client-summary__body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 12px 16px 16px;
}

.ai-client-summary__identity-card {
    padding: 12px;
    margin-bottom: 12px;
    border-radius: 12px;
    background: var(--summary-violet-bg);
    border: 1px solid var(--summary-violet-border);
}

.ai-client-summary__identity {
    display: flex;
    align-items: center;
    gap: 12px;
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
    padding: 11px 13px;
    border-radius: 10px;
    font-size: 0.875rem;
    line-height: 1.5;
    font-weight: 500;
    color: var(--wa-text);
    background: var(--summary-sky-bg);
    border: 1px solid var(--summary-sky-border);
    border-left: 3px solid var(--summary-sky-fg);
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
    padding: 4px 10px;
    border-radius: 8px;
    border: 1px solid transparent;
}

.ai-client-summary__chip--violet {
    background: var(--summary-violet-bg);
    color: var(--summary-violet-fg);
    border-color: var(--summary-violet-border);
}

.ai-client-summary__chip--amber {
    background: var(--summary-amber-bg);
    color: var(--summary-amber-fg);
    border-color: var(--summary-amber-border);
}

.ai-client-summary__chip--sky {
    background: var(--summary-sky-bg);
    color: var(--summary-sky-fg);
    border-color: var(--summary-sky-border);
}

.ai-client-summary__chip--rose {
    background: var(--summary-rose-bg);
    color: var(--summary-rose-fg);
    border-color: var(--summary-rose-border);
}

.ai-client-summary__sections {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ai-client-summary__section {
    padding: 10px 12px 10px 11px;
    border-radius: 10px;
    border: 1px solid transparent;
    border-left-width: 3px;
}

.ai-client-summary__section--tone-1 {
    background: var(--summary-violet-bg);
    border-color: var(--summary-violet-border);
    border-left-color: var(--summary-violet-fg);
}

.ai-client-summary__section--tone-1 h3 {
    color: var(--summary-violet-fg);
}

.ai-client-summary__section--tone-2 {
    background: var(--summary-sky-bg);
    border-color: var(--summary-sky-border);
    border-left-color: var(--summary-sky-fg);
}

.ai-client-summary__section--tone-2 h3 {
    color: var(--summary-sky-fg);
}

.ai-client-summary__section--tone-3 {
    background: var(--summary-amber-bg);
    border-color: var(--summary-amber-border);
    border-left-color: var(--summary-amber-fg);
}

.ai-client-summary__section--tone-3 h3 {
    color: var(--summary-amber-fg);
}

.ai-client-summary__section--tone-4 {
    background: var(--summary-rose-bg);
    border-color: var(--summary-rose-border);
    border-left-color: var(--summary-rose-fg);
}

.ai-client-summary__section--tone-4 h3 {
    color: var(--summary-rose-fg);
}

.ai-client-summary__section--tone-5 {
    background: var(--summary-teal-bg);
    border-color: var(--summary-teal-border);
    border-left-color: var(--summary-teal-fg);
}

.ai-client-summary__section--tone-5 h3 {
    color: var(--summary-teal-fg);
}

.ai-client-summary__section h3 {
    margin: 0 0 5px;
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
}

.ai-client-summary__section p {
    margin: 0;
    font-size: 0.8125rem;
    line-height: 1.55;
    color: color-mix(in srgb, var(--wa-text) 94%, var(--wa-text-secondary));
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
