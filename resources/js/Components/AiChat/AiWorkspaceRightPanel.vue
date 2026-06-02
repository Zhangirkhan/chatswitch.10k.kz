<script setup lang="ts">
import AiWorkspaceClientSummary from '@/Components/AiChat/AiWorkspaceClientSummary.vue';
import AiWorkspaceResultTabs from '@/Components/AiChat/AiWorkspaceResultTabs.vue';
import { useI18n } from '@/composables/useI18n';
import type {
    ClientSummary,
    ResultTabId,
    TabCounts,
    WorkspaceContact,
    WorkspaceResults,
} from '@/Components/AiChat/aiWorkspaceTypes';

defineProps<{
    open: boolean;
    summary: ClientSummary | null;
    summaryLoading: boolean;
    results: WorkspaceResults;
    activeTab: ResultTabId;
    tabCounts: TabCounts;
    focusedContactId: number | null;
    contacts: WorkspaceContact[];
    resultsCount: number;
}>();

const emit = defineEmits<{
    close: [];
    'update:activeTab': [tab: ResultTabId];
    selectContact: [contactId: number];
}>();

const { t } = useI18n();
</script>

<template>
    <aside class="ai-workspace__results" :class="{ 'is-open': open }">
        <div class="ai-workspace__results-head">
            <div>
                <h2 class="ai-workspace__results-title">{{ t('aiChat.panel') }}</h2>
                <p class="ai-workspace__results-sub">{{ t('aiChat.resultsCount', { count: resultsCount }) }}</p>
            </div>
            <button
                type="button"
                class="ai-workspace__icon-btn lg:hidden"
                :aria-label="t('aiChat.hidePanel')"
                @click="emit('close')"
            >
                <svg viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="M5 5l10 10M15 5 5 15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
            </button>
        </div>

        <AiWorkspaceClientSummary
            :summary="summary"
            :loading="summaryLoading"
            :contacts="contacts"
            :focused-contact-id="focusedContactId"
            @select-contact="emit('selectContact', $event)"
        />

        <AiWorkspaceResultTabs
            :results="results"
            :active-tab="activeTab"
            :tab-counts="tabCounts"
            :focused-contact-id="focusedContactId"
            @update:active-tab="emit('update:activeTab', $event)"
            @select-contact="emit('selectContact', $event)"
        />
    </aside>
</template>

<style scoped>
.ai-workspace__results {
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
    width: var(--ai-results-width, 340px);
    min-width: 0;
    background: var(--wa-panel-header);
    border-left: 1px solid var(--wa-sidebar-divider);
    z-index: 20;
}

.ai-workspace__results-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1rem 1rem 0.75rem;
    border-bottom: 1px solid var(--wa-sidebar-divider);
}

.ai-workspace__results-title {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--wa-text);
}

.ai-workspace__results-sub {
    margin: 0.15rem 0 0;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
}

.ai-workspace__icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border: none;
    border-radius: 999px;
    background: transparent;
    color: var(--wa-text-secondary);
    cursor: pointer;
}

.ai-workspace__icon-btn:hover {
    background: var(--wa-panel-hover);
    color: var(--wa-text);
}

.ai-workspace__icon-btn svg {
    width: 1.1rem;
    height: 1.1rem;
}

@media (max-width: 1023px) {
    .ai-workspace__results {
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: min(100vw, var(--ai-results-width, 340px));
        transform: translateX(100%);
        transition: transform 0.22s ease;
        box-shadow: -8px 0 32px rgba(0, 0, 0, 0.25);
    }

    .ai-workspace__results.is-open {
        transform: translateX(0);
    }
}
</style>
