<script setup lang="ts">
import type {
    ResultTabId,
    TabCounts,
    WorkspaceResults,
} from '@/Components/AiChat/aiWorkspaceTypes';
import { Link } from '@inertiajs/vue3';
import { formatPhone } from '@/utils/phone';
import { computed } from 'vue';

const props = defineProps<{
    results: WorkspaceResults;
    activeTab: ResultTabId;
    tabCounts: TabCounts;
    focusedContactId: number | null;
}>();

const emit = defineEmits<{
    'update:activeTab': [tab: ResultTabId];
    selectContact: [contactId: number];
}>();

const tabs = computed(() => {
    const items: Array<{ id: ResultTabId; label: string; count: number }> = [
        { id: 'contacts', label: 'Контакты', count: props.tabCounts.contacts },
        { id: 'media', label: 'Файлы', count: props.tabCounts.media },
        { id: 'messages', label: 'Сообщения', count: props.tabCounts.messages },
        { id: 'calendar', label: 'Календарь', count: props.tabCounts.calendar },
        { id: 'funnel', label: 'Воронка', count: props.tabCounts.funnel },
        { id: 'tasks', label: 'Задачи', count: props.tabCounts.tasks },
        { id: 'employees', label: 'Сотрудники', count: props.tabCounts.employees },
    ];

    return items.filter((tab) => tab.count > 0);
});

function mimeLabel(mime: string | null | undefined): string {
    if (!mime) {
        return 'Файл';
    }
    if (mime.startsWith('image/')) {
        return 'Фото';
    }
    if (mime.startsWith('video/')) {
        return 'Видео';
    }
    if (mime.startsWith('audio/')) {
        return 'Аудио';
    }
    return 'Документ';
}

function formatEventWhen(startsAt: string, endsAt: string, allDay?: boolean): string {
    const start = new Date(startsAt);
    const end = new Date(endsAt);
    if (Number.isNaN(start.getTime())) {
        return startsAt;
    }
    if (allDay) {
        return start.toLocaleDateString('ru-RU');
    }
    const date = start.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
    const from = start.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    const to = end.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });

    return `${date}, ${from}–${to}`;
}

function pickTab(tab: ResultTabId): void {
    emit('update:activeTab', tab);
}

function onContactClick(contactId: number): void {
    emit('selectContact', contactId);
}
</script>

<template>
    <section class="ai-result-tabs">
        <div v-if="tabs.length === 0" class="ai-result-tabs__empty">
            Результаты появятся здесь после запроса.
        </div>

        <template v-else>
            <div class="ai-result-tabs__bar wa-scrollbar" role="tablist">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    role="tab"
                    class="ai-result-tabs__tab"
                    :class="{ 'is-active': activeTab === tab.id }"
                    :aria-selected="activeTab === tab.id"
                    @click="pickTab(tab.id)"
                >
                    {{ tab.label }}
                    <span class="ai-result-tabs__count">{{ tab.count }}</span>
                </button>
            </div>

            <div class="ai-result-tabs__panel wa-scrollbar">
                <ul v-if="activeTab === 'contacts'" class="ai-result-tabs__list">
                    <li
                        v-for="c in results.contacts"
                        :key="c.id"
                        class="ai-result-tabs__card"
                        :class="{ 'is-focused': focusedContactId === c.id }"
                    >
                        <button type="button" class="ai-result-tabs__card-btn" @click="onContactClick(c.id)">
                            <div class="ai-result-tabs__card-title">{{ c.name }}</div>
                            <p v-if="c.phone_number" class="ai-result-tabs__card-meta">
                                {{ formatPhone(c.phone_number) || c.phone_number }}
                            </p>
                            <p v-if="c.companies?.length" class="ai-result-tabs__card-meta truncate">
                                {{ c.companies.join(', ') }}
                            </p>
                            <p v-if="c.unread_count" class="ai-result-tabs__card-badge">
                                Непрочитанных: {{ c.unread_count }}
                            </p>
                        </button>
                        <Link
                            v-if="c.chat_id"
                            :href="route('chats.show', c.chat_id)"
                            class="ai-result-tabs__link"
                            @click.stop
                        >
                            Чат
                        </Link>
                    </li>
                </ul>

                <ul v-else-if="activeTab === 'media'" class="ai-result-tabs__list">
                    <li v-for="m in results.media" :key="m.id" class="ai-result-tabs__card ai-result-tabs__card--media">
                        <a :href="m.url" target="_blank" rel="noopener" class="ai-result-tabs__thumb">
                            <img v-if="m.mime_type?.startsWith('image/')" :src="m.url" :alt="m.filename || ''" />
                            <span v-else>{{ mimeLabel(m.mime_type) }}</span>
                        </a>
                        <div class="min-w-0 flex-1">
                            <a
                                :href="m.url"
                                target="_blank"
                                rel="noopener"
                                class="ai-result-tabs__card-title block truncate hover:underline"
                            >
                                {{ m.filename || 'Без имени' }}
                            </a>
                            <p class="ai-result-tabs__card-meta truncate">
                                {{ m.contact_name || m.chat_name || 'Чат' }}
                            </p>
                            <Link
                                v-if="m.chat_id"
                                :href="route('chats.show', m.chat_id)"
                                class="ai-result-tabs__link"
                            >
                                В диалог
                            </Link>
                        </div>
                    </li>
                </ul>

                <ul v-else-if="activeTab === 'calendar'" class="ai-result-tabs__list">
                    <li v-for="ev in results.calendar_events" :key="ev.id + ev.starts_at" class="ai-result-tabs__card">
                        <div class="ai-result-tabs__card-title">{{ ev.title }}</div>
                        <p class="ai-result-tabs__card-meta">
                            {{ formatEventWhen(ev.starts_at, ev.ends_at, ev.all_day) }}
                        </p>
                        <p v-if="ev.assignee?.name" class="ai-result-tabs__card-meta">{{ ev.assignee.name }}</p>
                    </li>
                </ul>

                <ul v-else-if="activeTab === 'funnel'" class="ai-result-tabs__list">
                    <li v-for="deal in results.funnel_deals" :key="deal.id" class="ai-result-tabs__card">
                        <div class="ai-result-tabs__card-title">{{ deal.name }}</div>
                        <p class="ai-result-tabs__card-meta">{{ deal.funnel_name }} · {{ deal.stage_name }}</p>
                        <Link :href="route('chats.show', deal.id)" class="ai-result-tabs__link">Открыть сделку</Link>
                    </li>
                </ul>

                <ul v-else-if="activeTab === 'messages'" class="ai-result-tabs__list">
                    <li v-for="msg in results.messages" :key="msg.id" class="ai-result-tabs__card">
                        <div class="ai-result-tabs__card-title truncate">
                            {{ msg.contact_name || msg.chat_name || 'Чат' }}
                        </div>
                        <p class="ai-result-tabs__card-meta line-clamp-3">{{ msg.body }}</p>
                        <Link
                            v-if="msg.chat_id"
                            :href="route('chats.show', msg.chat_id)"
                            class="ai-result-tabs__link"
                        >
                            В диалог
                        </Link>
                    </li>
                </ul>

                <ul v-else-if="activeTab === 'tasks'" class="ai-result-tabs__list">
                    <li v-for="post in results.department_posts" :key="post.id" class="ai-result-tabs__card">
                        <div class="ai-result-tabs__card-title">{{ post.title }}</div>
                        <p class="ai-result-tabs__card-meta">
                            {{ post.department_name || 'Отдел' }} · {{ post.status }}
                        </p>
                    </li>
                </ul>

                <ul v-else-if="activeTab === 'employees'" class="ai-result-tabs__list">
                    <li v-for="emp in results.employees" :key="emp.id" class="ai-result-tabs__card">
                        <div class="ai-result-tabs__card-title">{{ emp.name }}</div>
                        <p v-if="emp.email" class="ai-result-tabs__card-meta">{{ emp.email }}</p>
                    </li>
                </ul>
            </div>
        </template>
    </section>
</template>

<style scoped>
.ai-result-tabs {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-height: 0;
}

.ai-result-tabs__empty {
    padding: 14px;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
}

.ai-result-tabs__bar {
    display: flex;
    gap: 6px;
    padding: 8px 10px;
    overflow-x: auto;
    flex-shrink: 0;
    border-bottom: 1px solid var(--wa-sidebar-divider);
}

.ai-result-tabs__tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-shrink: 0;
    min-height: 30px;
    padding: 0 10px;
    border-radius: 999px;
    border: 1px solid var(--wa-control-rim);
    background: var(--wa-control-surface);
    color: var(--wa-text-secondary);
    font-size: 0.6875rem;
    font-weight: 600;
    cursor: pointer;
}

.ai-result-tabs__tab.is-active {
    border-color: var(--ui-accent-border);
    background: var(--ui-accent-soft);
    color: var(--wa-accent);
}

.ai-result-tabs__count {
    min-width: 1rem;
    text-align: center;
    font-size: 0.625rem;
    font-weight: 700;
}

.ai-result-tabs__panel {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 10px;
}

.ai-result-tabs__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.ai-result-tabs__card {
    border-radius: 12px;
    border: 1px solid var(--wa-control-rim);
    background: var(--wa-control-surface);
    padding: 10px;
}

.ai-result-tabs__card.is-focused {
    border-color: var(--ui-accent-border);
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--wa-accent) 25%, transparent);
}

.ai-result-tabs__card--media {
    display: flex;
    gap: 10px;
    align-items: flex-start;
}

.ai-result-tabs__card-btn {
    width: 100%;
    padding: 0;
    border: 0;
    background: transparent;
    color: inherit;
    text-align: left;
    cursor: pointer;
}

.ai-result-tabs__card-title {
    font-size: 0.8125rem;
    font-weight: 650;
    line-height: 1.3;
}

.ai-result-tabs__card-meta {
    margin: 4px 0 0;
    font-size: 0.6875rem;
    line-height: 1.35;
    color: var(--wa-text-secondary);
}

.ai-result-tabs__card-badge {
    margin: 6px 0 0;
    font-size: 0.625rem;
    font-weight: 700;
    color: var(--wa-accent);
}

.ai-result-tabs__link {
    display: inline-flex;
    margin-top: 8px;
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--wa-accent);
    text-decoration: none;
}

.ai-result-tabs__link:hover {
    text-decoration: underline;
}

.ai-result-tabs__thumb {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 2.75rem;
    height: 2.75rem;
    border-radius: 8px;
    overflow: hidden;
    background: var(--wa-panel-header);
    color: var(--wa-text-secondary);
    font-size: 0.625rem;
    font-weight: 700;
    text-decoration: none;
}

.ai-result-tabs__thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
</style>
