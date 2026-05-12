<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    active: 'clients' | 'organization';
}>();

const page = usePage<any>();
const unread = computed<number>(() => Number(page.props.unreadChatsCount || 0));
const orgOpen = computed<number>(() => Number(page.props.orgOpenTasksCount || 0));
</script>

<template>
    <div class="px-3 pt-2 pb-1 flex items-center gap-2 shrink-0 section-tabs-row">
        <Link
            :href="route('chats.index')"
            class="section-tab"
            :class="{ 'section-tab-active': active === 'clients' }"
        >
            <span class="tab-inner">
                <svg class="section-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
                Клиенты
                <span
                    v-if="unread > 0"
                    class="tab-badge"
                    :title="`Непрочитанных чатов: ${unread}`"
                >{{ unread > 99 ? '99+' : unread }}</span>
            </span>
        </Link>
        <Link
            :href="route('organization.index')"
            class="section-tab"
            :class="{ 'section-tab-active': active === 'organization' }"
        >
            <span class="tab-inner">
                <svg class="section-tab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 21V12h6v9M9 9h.01M15 9h.01" />
                </svg>
                Организация
                <span
                    v-if="orgOpen > 0"
                    class="tab-badge tab-badge-org"
                    :title="`Активных задач: ${orgOpen}`"
                >{{ orgOpen > 99 ? '99+' : orgOpen }}</span>
            </span>
        </Link>
    </div>
</template>

<style scoped>
.section-tabs-row {
    border-bottom: 1px solid var(--wa-border);
    margin-bottom: 0.25rem;
}
.section-tab {
    flex: 1 1 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.5rem 0.75rem;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--wa-text-secondary);
    background-color: transparent;
    border: none;
    border-bottom: 2px solid transparent;
    transition: color 0.15s ease, border-color 0.15s ease, background-color 0.15s ease;
    line-height: 1.25rem;
    text-decoration: none;
    cursor: pointer;
    border-radius: 0;
}
.section-tab:hover {
    color: var(--wa-text);
    background-color: var(--wa-panel-hover);
}
.section-tab-active {
    color: var(--wa-accent);
    border-bottom-color: var(--wa-accent);
}
.section-tab-active:hover {
    color: var(--wa-accent);
}
.tab-inner {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    position: relative;
}
.section-tab-icon {
    width: 1.05rem;
    height: 1.05rem;
    flex-shrink: 0;
}
.tab-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 0.32rem;
    border-radius: 999px;
    background: var(--wa-accent);
    color: var(--wa-unread-text, #0b0d0e);
    font-size: 0.68rem;
    font-weight: 700;
    line-height: 1;
    flex-shrink: 0;
}
.tab-badge-org {
    background: #f59e0b;
    color: #1c1917;
}
</style>
