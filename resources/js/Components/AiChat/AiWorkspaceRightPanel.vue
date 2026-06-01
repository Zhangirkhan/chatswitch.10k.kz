<script setup lang="ts">
import AiWorkspaceClientSummary from '@/Components/AiChat/AiWorkspaceClientSummary.vue';
import AiWorkspaceResultTabs from '@/Components/AiChat/AiWorkspaceResultTabs.vue';
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
</script>

<template>
    <aside class="ai-workspace__results" :class="{ 'is-open': open }">
        <div class="ai-workspace__results-head">
            <div>
                <h2 class="ai-workspace__results-title">Панель</h2>
                <p class="ai-workspace__results-sub">{{ resultsCount }} результатов</p>
            </div>
            <button
                type="button"
                class="ai-workspace__icon-btn lg:hidden"
                aria-label="Скрыть панель"
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
    gap: 10px;
    padding: 12px 14px 8px;
    flex-shrink: 0;
}

.ai-workspace__results-title {
    margin: 0;
    font-size: 0.875rem;
    font-weight: 700;
}

.ai-workspace__results-sub {
    margin: 2px 0 0;
    font-size: 0.6875rem;
    color: var(--wa-text-secondary);
}

.ai-workspace__icon-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: var(--wa-text-secondary);
    cursor: pointer;
}

.ai-workspace__icon-btn svg {
    width: 1.125rem;
    height: 1.125rem;
}

@media (max-width: 1023px) {
    .ai-workspace__results {
        position: absolute;
        top: 0;
        right: 0;
        bottom: 0;
        width: min(100%, 22rem);
        transform: translateX(100%);
        transition: transform 0.22s ease;
        box-shadow: -8px 0 24px rgba(0, 0, 0, 0.18);
    }

    .ai-workspace__results.is-open {
        transform: translateX(0);
    }
}
</style>
