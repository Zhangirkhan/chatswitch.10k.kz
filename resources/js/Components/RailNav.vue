<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Sortable from 'sortablejs';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useI18n } from '@/composables/useI18n';
import {
    DEFAULT_RAIL_NAV_ORDER,
    type RailNavId,
    useRailNavOrder,
} from '@/composables/useRailNavOrder';

const props = defineProps<{
    nav: Record<string, boolean>;
    unreadChatsCount: number;
    calendarBadgeCount: number;
}>();

const { t } = useI18n();
const { order } = useRailNavOrder();

const listRef = ref<HTMLElement | null>(null);
let sortable: Sortable | null = null;

function isVisible(id: RailNavId): boolean {
    if (id === 'chats') {
        return true;
    }

    return props.nav[id] === true;
}

const visibleOrder = computed(() => order.value.filter(isVisible));

function mergeVisibleOrder(newVisible: RailNavId[]): RailNavId[] {
    const queue = [...newVisible];

    return order.value.map((id) => {
        if (!isVisible(id)) {
            return id;
        }

        return queue.shift() ?? id;
    });
}

function hrefFor(id: RailNavId): string {
    switch (id) {
        case 'chats':
            return route('chats.index');
        case 'clients':
            return route('clients.index');
        case 'broadcasts':
            return route('broadcasts.index');
        case 'ai_chat':
            return route('ai-chat.index');
        case 'analytics':
            return route('analytics.dialogs');
        case 'calendar':
            return route('calendar.index');
        case 'funnels':
            return route('funnels.board');
    }
}

function isActive(id: RailNavId): boolean {
    switch (id) {
        case 'chats':
            return route().current('chats.index')
                || route().current('chats.show')
                || route().current('chats.archived');
        case 'clients':
            return route().current('clients.*');
        case 'broadcasts':
            return route().current('broadcasts.*');
        case 'ai_chat':
            return route().current('ai-chat.*');
        case 'analytics':
            return route().current('analytics.*');
        case 'calendar':
            return route().current('calendar.*');
        case 'funnels':
            return route().current('funnels.board');
    }
}

function titleFor(id: RailNavId): string {
    switch (id) {
        case 'chats':
            return t('nav.chats');
        case 'clients':
            return t('nav.clients');
        case 'broadcasts':
            return t('nav.broadcasts');
        case 'ai_chat':
            return t('nav.aiChat');
        case 'analytics':
            return t('nav.analytics');
        case 'calendar':
            return t('nav.calendar');
        case 'funnels':
            return t('nav.funnels');
    }
}

function destroySortable(): void {
    sortable?.destroy();
    sortable = null;
}

function initSortable(): void {
    destroySortable();

    const el = listRef.value;
    if (!el || visibleOrder.value.length < 2) {
        return;
    }

    sortable = Sortable.create(el, {
        animation: 160,
        easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
        delay: 350,
        delayOnTouchOnly: false,
        touchStartThreshold: 4,
        draggable: '.wa-rail-btn-wrap',
        ghostClass: 'wa-rail-btn-wrap--ghost',
        dragClass: 'wa-rail-btn-wrap--dragging',
        onEnd(evt) {
            if (evt.oldIndex == null || evt.newIndex == null || evt.oldIndex === evt.newIndex) {
                return;
            }

            const newVisible = [...el.querySelectorAll<HTMLElement>('.wa-rail-btn-wrap')]
                .map((node) => node.dataset.railId as RailNavId | undefined)
                .filter((id): id is RailNavId => id != null && DEFAULT_RAIL_NAV_ORDER.includes(id));

            if (newVisible.length !== visibleOrder.value.length) {
                return;
            }

            order.value = mergeVisibleOrder(newVisible);
        },
    });
}

onMounted(() => {
    void nextTick(() => initSortable());
});

watch(visibleOrder, () => {
    void nextTick(() => initSortable());
});

onBeforeUnmount(() => {
    destroySortable();
});
</script>

<template>
    <nav ref="listRef" class="flex flex-col items-center gap-1 flex-1">
        <div
            v-for="id in visibleOrder"
            :key="id"
            class="wa-rail-btn-wrap"
            :data-rail-id="id"
        >
            <Link
                :href="hrefFor(id)"
                class="wa-rail-btn relative"
                :class="{ active: isActive(id) }"
                :title="titleFor(id)"
                :aria-label="titleFor(id)"
                :aria-current="isActive(id) ? 'page' : undefined"
            >
                <svg
                    v-if="id === 'chats'"
                    class="w-6 h-6"
                    fill="currentColor"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path d="M19.005 3.175H4.674C3.642 3.175 3 3.789 3 4.821V21.02l3.544-3.514h12.461c1.033 0 2.064-1.06 2.064-2.093V4.821c-.001-1.032-1.032-1.646-2.064-1.646zm-4.989 9.869H7.041V11.1h6.975v1.944zm3-4H7.041V7.1h9.975v1.944z" />
                </svg>

                <svg
                    v-else-if="id === 'clients'"
                    class="w-6 h-6"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                </svg>

                <svg
                    v-else-if="id === 'broadcasts'"
                    class="w-6 h-6"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>

                <svg
                    v-else-if="id === 'ai_chat'"
                    class="w-6 h-6"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />
                </svg>

                <svg
                    v-else-if="id === 'analytics'"
                    class="w-6 h-6"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 16l4-8 4 5 4-10" />
                </svg>

                <svg
                    v-else-if="id === 'calendar'"
                    class="w-6 h-6"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke-linecap="round" stroke-linejoin="round" />
                    <line x1="16" y1="2" x2="16" y2="6" stroke-linecap="round" />
                    <line x1="8" y1="2" x2="8" y2="6" stroke-linecap="round" />
                    <line x1="3" y1="10" x2="21" y2="10" stroke-linecap="round" />
                </svg>

                <svg
                    v-else-if="id === 'funnels'"
                    class="w-6 h-6"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h5v5H4V6zm0 7h5v5H4v-5zm7-7h9v3h-9V6zm0 5h9v3h-9v-3zm0 5h9v3h-9v-3z" />
                </svg>

                <span
                    v-if="id === 'chats' && unreadChatsCount > 0"
                    class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] rounded-full text-[10px] font-semibold flex items-center justify-center px-1 leading-none"
                    :style="{ background: 'var(--wa-unread)', color: 'var(--wa-unread-text)' }"
                >
                    {{ unreadChatsCount > 99 ? '99+' : unreadChatsCount }}
                </span>

                <span
                    v-if="id === 'calendar' && calendarBadgeCount > 0"
                    class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] rounded-full text-[10px] font-semibold flex items-center justify-center px-1 leading-none"
                    :style="{ background: 'var(--wa-unread)', color: 'var(--wa-unread-text)' }"
                    :title="t('nav.calendarToday', { count: calendarBadgeCount })"
                >
                    {{ calendarBadgeCount > 99 ? '99+' : calendarBadgeCount }}
                </span>
            </Link>
        </div>
    </nav>
</template>

<style scoped>
.wa-rail-btn-wrap {
    cursor: grab;
    touch-action: none;
}

.wa-rail-btn-wrap:active {
    cursor: grabbing;
}

.wa-rail-btn-wrap--ghost {
    opacity: 0.45;
}

.wa-rail-btn-wrap--dragging .wa-rail-btn {
    background-color: var(--wa-selected);
    color: var(--wa-text);
    box-shadow: 0 8px 24px color-mix(in srgb, var(--wa-text) 18%, transparent);
}

.wa-rail-btn {
    position: relative;
    width: 2.75rem;
    height: 2.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.5rem;
    color: var(--wa-icon);
    transition: background-color 0.15s ease, color 0.15s ease;
}

.wa-rail-btn:hover {
    background-color: var(--wa-rail-btn-hover);
    color: var(--wa-text);
}

.wa-rail-btn.active {
    background-color: var(--wa-selected);
    color: var(--wa-text);
}
</style>
