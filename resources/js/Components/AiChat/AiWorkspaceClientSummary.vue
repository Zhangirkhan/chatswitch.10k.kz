<script setup lang="ts">
import UserAvatar from '@/Components/UserAvatar.vue';
import type { ClientSummary, WorkspaceContact } from '@/Components/AiChat/aiWorkspaceTypes';
import { Link } from '@inertiajs/vue3';
import { formatPhone } from '@/utils/phone';
import { useI18n } from '@/composables/useI18n';
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

const { t } = useI18n();

type SectionSemantic = 'who' | 'preferences' | 'context' | 'agreements' | 'deal' | 'neutral';

type EnrichedSection = {
    title: string;
    body: string;
    semantic: SectionSemantic;
};

const emptyMessage = computed(() => {
    if (props.emptyHint) {
        return props.emptyHint;
    }
    if (props.variant === 'chat') {
        return t('aiChat.summaryEmptyChat');
    }
    return t('aiChat.summaryEmptyWorkspace');
});

const emit = defineEmits<{
    selectContact: [contactId: number];
}>();

const confidenceLabel = computed(() => {
    const level = props.summary?.ai.confidence;
    if (level === 'high') {
        return t('aiChat.confidenceHigh');
    }
    if (level === 'medium') {
        return t('aiChat.confidenceMedium');
    }
    return t('aiChat.confidenceLow');
});

const enrichedSections = computed((): EnrichedSection[] => {
    if (!props.summary) {
        return [];
    }

    return props.summary.ai.sections.map((section) => ({
        title: section.title,
        body: section.body,
        semantic: sectionSemantic(section.title),
    }));
});

const profileSections = computed(() =>
    enrichedSections.value.filter((section) => section.semantic === 'who' || section.semantic === 'preferences'),
);

const activitySections = computed(() =>
    enrichedSections.value.filter((section) => section.semantic !== 'who' && section.semantic !== 'preferences'),
);

const hasCrmTags = computed(() => {
    const crm = props.summary?.crm;
    if (!crm) {
        return false;
    }

    return Boolean(
        crm.deal?.funnel?.name
            || crm.deal?.stage?.name
            || crm.upcoming_events_count
            || crm.open_tasks_count,
    );
});

function onPickContact(event: Event): void {
    const value = Number((event.target as HTMLSelectElement).value);
    if (value > 0) {
        emit('selectContact', value);
    }
}

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
            <div class="summary-hero">
                <UserAvatar
                    class="summary-hero__avatar"
                    :name="summary.identity.display_name"
                    :src="summary.identity.avatar"
                    :size="48"
                />
                <div class="summary-hero__main min-w-0">
                    <div class="summary-hero__title-row">
                        <h2 class="summary-hero__name">{{ summary.identity.display_name }}</h2>
                        <span
                            class="summary-hero__status"
                            :class="`summary-hero__status--${summary.ai.confidence}`"
                        >
                            {{ confidenceLabel }}
                        </span>
                    </div>
                    <p v-if="summary.identity.phone" class="summary-hero__meta">
                        {{ formatPhone(summary.identity.phone) || summary.identity.phone }}
                    </p>
                    <p v-if="summary.identity.companies.length" class="summary-hero__meta truncate">
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

            <p class="summary-insight">{{ summary.ai.headline }}</p>

            <section v-if="hasCrmTags" class="summary-group">
                <h3 class="summary-group__label">{{ t('aiChat.funnelAndCrm') }}</h3>
                <div class="summary-tags">
                    <span
                        v-if="summary.crm.deal?.funnel?.name"
                        class="summary-tag summary-tag--who"
                    >
                        [ {{ summary.crm.deal.funnel.name }} ]
                    </span>
                    <span
                        v-if="summary.crm.deal?.stage?.name"
                        class="summary-tag summary-tag--context"
                    >
                        [ {{ summary.crm.deal.stage.name }} ]
                    </span>
                    <span
                        v-if="summary.crm.upcoming_events_count"
                        class="summary-tag summary-tag--prefs"
                    >
                        [ Записей: {{ summary.crm.upcoming_events_count }} ]
                    </span>
                    <span
                        v-if="summary.crm.open_tasks_count"
                        class="summary-tag summary-tag--agreements"
                    >
                        [ Задач: {{ summary.crm.open_tasks_count }} ]
                    </span>
                </div>
            </section>

            <section v-if="profileSections.length" class="summary-group">
                <h3 class="summary-group__label">{{ t('aiChat.clientProfile') }}</h3>
                <div class="summary-profile">
                    <div
                        v-for="section in profileSections"
                        :key="section.title"
                        class="summary-profile__item"
                        :class="`summary-profile__item--${section.semantic}`"
                    >
                        <span class="summary-profile__icon" aria-hidden="true">
                            <svg v-if="section.semantic === 'who'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" d="M12 12a4 4 0 100-8 4 4 0 000 8z" />
                                <path stroke-linecap="round" d="M5 20a7 7 0 0114 0" />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85">
                                <polygon
                                    points="12 3.4 14.65 8.77 20.58 9.63 16.29 13.81 17.3 19.72 12 16.93 6.7 19.72 7.71 13.81 3.42 9.63 9.35 8.77 12 3.4"
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                />
                            </svg>
                        </span>
                        <div class="summary-profile__content min-w-0">
                            <span class="summary-profile__label">{{ section.title }}</span>
                            <p class="summary-profile__value">{{ section.body }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section v-if="activitySections.length" class="summary-group">
                <h3 class="summary-group__label">{{ t('aiChat.contextAgreements') }}</h3>
                <div class="summary-activity">
                    <article
                        v-for="section in activitySections"
                        :key="section.title"
                        class="summary-activity__item"
                        :class="`summary-activity__item--${section.semantic}`"
                    >
                        <span class="summary-activity__icon" aria-hidden="true">
                            <svg v-if="section.semantic === 'context'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" d="M12 11.5a2.5 2.5 0 100-5 2.5 2.5 0 000 5z" />
                                <path stroke-linecap="round" d="M5.5 10.5c1.2-3 4.3-5 6.5-5s5.3 2 6.5 5c-1.2 3-4.3 9-6.5 9s-5.3-6-6.5-9z" />
                            </svg>
                            <svg v-else-if="section.semantic === 'agreements'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" d="M5 13l4 4L19 7" />
                            </svg>
                            <svg v-else-if="section.semantic === 'deal'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path stroke-linecap="round" d="M5 12h14M13 6l6 6-6 6" />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                                <circle cx="12" cy="12" r="8" />
                                <path stroke-linecap="round" d="M12 8v4l2.5 2.5" />
                            </svg>
                        </span>
                        <div class="summary-activity__content min-w-0">
                            <h4 class="summary-activity__title">{{ section.title }}</h4>
                            <p class="summary-activity__text">{{ section.body }}</p>
                        </div>
                    </article>
                </div>
            </section>

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
    --sem-who-bg: color-mix(in srgb, var(--sem-who) 14%, var(--wa-panel));
    --sem-who-border: color-mix(in srgb, var(--sem-who) 28%, var(--wa-border));
    --sem-prefs: #d4a72c;
    --sem-prefs-bg: color-mix(in srgb, var(--sem-prefs) 15%, var(--wa-panel));
    --sem-prefs-border: color-mix(in srgb, var(--sem-prefs) 34%, var(--wa-border));
    --sem-context: #b8945f;
    --sem-context-bg: color-mix(in srgb, var(--sem-context) 15%, var(--wa-panel));
    --sem-context-border: color-mix(in srgb, var(--sem-context) 30%, var(--wa-border));
    --sem-agreements: #6fa384;
    --sem-agreements-bg: color-mix(in srgb, var(--sem-agreements) 15%, var(--wa-panel));
    --sem-agreements-border: color-mix(in srgb, var(--sem-agreements) 30%, var(--wa-border));
    --sem-deal: #8890b5;
    --sem-deal-bg: color-mix(in srgb, var(--sem-deal) 14%, var(--wa-panel));
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
    border-bottom: none;
    background: transparent;
}

.ai-client-summary--expanded .ai-client-summary__body {
    padding: 14px 16px 16px;
}

.ai-client-summary--expanded .ai-client-summary__loading,
.ai-client-summary--expanded .ai-client-summary__empty {
    flex: 1;
    min-height: 0;
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
    padding: 14px 16px 16px;
}

.summary-hero {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 14px;
}

.summary-hero__avatar {
    flex-shrink: 0;
}

.summary-hero__title-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 8px;
    margin-bottom: 4px;
}

.summary-hero__name {
    margin: 0;
    font-size: 1.0625rem;
    font-weight: 650;
    line-height: 1.25;
    color: var(--wa-text);
}

.summary-hero__status {
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 3px 8px;
    border-radius: 999px;
    color: var(--wa-text-secondary);
    background: color-mix(in srgb, var(--wa-text) 5%, var(--wa-panel));
    border: 1px solid var(--wa-border);
}

.summary-hero__meta {
    margin: 2px 0 0;
    font-size: 0.75rem;
    line-height: 1.35;
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

.summary-insight {
    margin: 0 0 16px;
    padding: 11px 13px;
    font-size: 0.875rem;
    line-height: 1.45;
    font-weight: 500;
    color: var(--wa-text);
    border-radius: 12px;
    background: var(--sem-context-bg);
    border: 1px solid var(--sem-context-border);
}

.summary-group + .summary-group {
    margin-top: 16px;
}

.summary-group__label {
    margin: 0 0 8px;
    font-size: 0.625rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--wa-text-secondary);
}

.summary-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.summary-tag {
    font-size: 0.6875rem;
    font-weight: 500;
    padding: 5px 10px;
    border-radius: 8px;
    border: 1px solid transparent;
    line-height: 1.2;
}

.summary-tag--who {
    color: var(--sem-who);
    background: var(--sem-who-bg);
    border-color: var(--sem-who-border);
}

.summary-tag--prefs {
    color: var(--sem-prefs);
    background: var(--sem-prefs-bg);
    border-color: var(--sem-prefs-border);
}

.summary-tag--context {
    color: var(--sem-context);
    background: var(--sem-context-bg);
    border-color: var(--sem-context-border);
}

.summary-tag--agreements {
    color: var(--sem-agreements);
    background: var(--sem-agreements-bg);
    border-color: var(--sem-agreements-border);
}

.summary-profile {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.summary-profile__item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid transparent;
}

.summary-profile__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 999px;
}

.summary-profile__icon svg {
    width: 0.9375rem;
    height: 0.9375rem;
}

.summary-profile__item--who {
    background: var(--sem-who-bg);
    border-color: var(--sem-who-border);
}

.summary-profile__item--who .summary-profile__icon {
    color: var(--sem-who);
    background: color-mix(in srgb, var(--sem-who) 18%, var(--wa-panel));
}

.summary-profile__item--preferences {
    background: var(--sem-prefs-bg);
    border-color: var(--sem-prefs-border);
}

.summary-profile__item--preferences .summary-profile__icon {
    color: var(--sem-prefs);
    background: color-mix(in srgb, var(--sem-prefs) 24%, var(--wa-panel));
}

.summary-profile__item--preferences .summary-profile__icon svg {
    width: 1.0625rem;
    height: 1.0625rem;
    filter: drop-shadow(0 1px 0 color-mix(in srgb, #fff 45%, transparent));
}

.summary-profile__content {
    flex: 1;
    min-width: 0;
}

.summary-profile__label {
    display: block;
    margin-bottom: 3px;
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--wa-text-secondary);
}

.summary-profile__value {
    margin: 0;
    font-size: 0.8125rem;
    line-height: 1.45;
    color: var(--wa-text);
}

.summary-activity {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.summary-activity__item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 12px;
    border: 1px solid transparent;
}

.summary-activity__icon {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 999px;
}

.summary-activity__icon svg {
    width: 0.9375rem;
    height: 0.9375rem;
}

.summary-activity__item--context {
    background: var(--sem-context-bg);
    border-color: var(--sem-context-border);
}

.summary-activity__item--context .summary-activity__icon {
    color: var(--sem-context);
    background: color-mix(in srgb, var(--sem-context) 18%, var(--wa-panel));
}

.summary-activity__item--agreements {
    background: var(--sem-agreements-bg);
    border-color: var(--sem-agreements-border);
}

.summary-activity__item--agreements .summary-activity__icon {
    color: var(--sem-agreements);
    background: color-mix(in srgb, var(--sem-agreements) 18%, var(--wa-panel));
}

.summary-activity__item--deal {
    background: var(--sem-deal-bg);
    border-color: var(--sem-deal-border);
}

.summary-activity__item--deal .summary-activity__icon {
    color: var(--sem-deal);
    background: color-mix(in srgb, var(--sem-deal) 18%, var(--wa-panel));
}

.summary-activity__item--neutral {
    background: color-mix(in srgb, var(--wa-text) 4%, var(--wa-panel));
    border-color: color-mix(in srgb, var(--wa-border) 85%, transparent);
}

.summary-activity__item--neutral .summary-activity__icon {
    color: var(--wa-text-secondary);
    background: color-mix(in srgb, var(--wa-text) 6%, var(--wa-panel));
}

.summary-activity__title {
    margin: 0 0 3px;
    font-size: 0.625rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    color: var(--wa-text-secondary);
}

.summary-activity__text {
    margin: 0;
    font-size: 0.8125rem;
    line-height: 1.45;
    color: var(--wa-text);
}

.ai-client-summary__actions {
    display: flex;
    gap: 8px;
    margin-top: 16px;
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
