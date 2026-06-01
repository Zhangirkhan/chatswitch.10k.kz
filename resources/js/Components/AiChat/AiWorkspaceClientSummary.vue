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
                <span v-if="summary.crm.deal?.funnel?.name">{{ summary.crm.deal.funnel.name }}</span>
                <span v-if="summary.crm.deal?.stage?.name">{{ summary.crm.deal.stage.name }}</span>
                <span v-if="summary.crm.upcoming_events_count">Записей: {{ summary.crm.upcoming_events_count }}</span>
                <span v-if="summary.crm.open_tasks_count">Задач: {{ summary.crm.open_tasks_count }}</span>
            </div>

            <div class="ai-client-summary__sections">
                <article
                    v-for="section in summary.ai.sections"
                    :key="section.title"
                    class="ai-client-summary__section"
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
    background: var(--wa-panel);
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
    color: var(--wa-chroma-accent-fg, var(--wa-accent));
    background: var(--wa-chroma-accent-bg-12, color-mix(in srgb, var(--wa-accent) 12%, var(--wa-panel)));
    border-color: var(--wa-chroma-accent-border-45, color-mix(in srgb, var(--wa-accent) 30%, var(--wa-border)));
}

.ai-client-summary__badge--medium {
    color: var(--wa-chroma-yellow-fg, #ca8a04);
    background: var(--wa-chroma-yellow-bg, color-mix(in srgb, #eab308 12%, var(--wa-panel)));
    border-color: color-mix(in srgb, var(--wa-chroma-yellow-fg, #ca8a04) 28%, var(--wa-border));
}

.ai-client-summary__badge--low {
    opacity: 0.9;
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
    border: 2px solid color-mix(in srgb, var(--wa-text-secondary) 25%, transparent);
    border-top-color: var(--wa-text-secondary);
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
    background: var(--wa-panel-header);
    border: 1px solid var(--wa-border);
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
    background: color-mix(in srgb, var(--wa-text) 3%, var(--wa-panel-header));
    border: 1px solid var(--wa-border);
    border-left: 3px solid color-mix(in srgb, var(--wa-text-secondary) 35%, var(--wa-border));
}

.ai-client-summary__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 14px;
}

.ai-client-summary__chips span {
    font-size: 0.6875rem;
    font-weight: 500;
    padding: 4px 10px;
    border-radius: 8px;
    background: var(--wa-control-surface);
    color: var(--wa-text-secondary);
    border: 1px solid var(--wa-control-rim);
}

.ai-client-summary__sections {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ai-client-summary__section {
    padding: 10px 12px;
    border-radius: 10px;
    background: color-mix(in srgb, var(--wa-bg) 25%, var(--wa-panel));
    border: 1px solid color-mix(in srgb, var(--wa-border) 90%, transparent);
}

.ai-client-summary__section h3 {
    margin: 0 0 5px;
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: color-mix(in srgb, var(--wa-text-secondary) 90%, transparent);
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
