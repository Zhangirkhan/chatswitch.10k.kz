<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

defineProps<{
    active: 'clients' | 'organization';
}>();

const page = usePage<any>();
const listOwnership = computed(() => (page.props.listOwnership === 'mine' ? 'mine' : 'all'));
const unread = computed<number>(() => {
    const mine = Number(page.props.unreadChatsCountMine || 0);
    const all = Number(page.props.unreadChatsCount || 0);
    return listOwnership.value === 'mine' ? mine : all;
});
const orgOpen = computed<number>(() => Number(page.props.orgOpenTasksCount || 0));
const tasksEnabled = computed<boolean>(() => Boolean(page.props.modules?.tasks ?? true));
</script>

<template>
    <div class="ui-pill-nav">
        <Link
            :href="route('chats.index')"
            class="ui-pill-nav__item"
            :class="{ 'is-active': active === 'clients' }"
        >
            <svg class="h-4 w-4 shrink-0 opacity-80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
            <span class="truncate">Клиенты</span>
            <span
                v-if="unread > 0"
                class="ui-tab-badge"
                :title="listOwnership === 'mine'
                    ? `Непрочитанных среди «Мои»: ${unread}`
                    : `Непрочитанных чатов (все доступные): ${unread}`"
            >{{ unread > 99 ? '99+' : unread }}</span>
        </Link>
        <Link
            v-if="tasksEnabled"
            :href="route('organization.index')"
            class="ui-pill-nav__item"
            :class="{ 'is-active': active === 'organization' }"
        >
            <svg class="h-4 w-4 shrink-0 opacity-80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 21h18M5 21V7l7-4 7 4v14M9 21V12h6v9M9 9h.01M15 9h.01" />
            </svg>
            <span class="truncate">Организация</span>
            <span
                v-if="orgOpen > 0"
                class="ui-tab-badge ui-tab-badge--warn"
                :title="`Активных задач: ${orgOpen}`"
            >{{ orgOpen > 99 ? '99+' : orgOpen }}</span>
        </Link>
    </div>
</template>
