<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<any>();
const user = computed(() => page.props.auth.user);
const unreadChatsCount = computed<number>(() => Number(page.props.unreadChatsCount || 0));

function initial(name?: string): string {
    return (name || '?').charAt(0).toUpperCase();
}

function notifySoon() {
    // Communities is not implemented yet; keep as visual stub.
}
</script>

<template>
    <div class="h-screen w-screen flex bg-[var(--wa-bg)] text-[var(--wa-text)] overflow-hidden">
        <!-- LEFT ICON RAIL (matches WhatsApp Web 1:1) -->
        <aside
            class="w-[60px] shrink-0 flex flex-col items-center py-3"
            :style="{ background: 'var(--wa-rail-bg)' }"
        >
            <nav class="flex flex-col items-center gap-1 flex-1">
                <!-- Chats -->
                <Link
                    :href="route('chats.index')"
                    class="wa-rail-btn relative"
                    :class="{ active: route().current('chats.index') || route().current('chats.show') || route().current('chats.archived') }"
                    title="Чаты"
                >
                    <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M19.005 3.175H4.674C3.642 3.175 3 3.789 3 4.821V21.02l3.544-3.514h12.461c1.033 0 2.064-1.06 2.064-2.093V4.821c-.001-1.032-1.032-1.646-2.064-1.646zm-4.989 9.869H7.041V11.1h6.975v1.944zm3-4H7.041V7.1h9.975v1.944z"/>
                    </svg>
                    <span
                        v-if="unreadChatsCount > 0"
                        class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] rounded-full text-[10px] font-semibold flex items-center justify-center px-1 leading-none"
                        :style="{ background: 'var(--wa-unread)', color: 'var(--wa-unread-text)' }"
                    >
                        {{ unreadChatsCount > 99 ? '99+' : unreadChatsCount }}
                    </span>
                </Link>

                <!-- Status -->
                <Link
                    :href="route('status.index')"
                    class="wa-rail-btn"
                    :class="{ active: route().current('status.index') }"
                    title="Статус"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="9" stroke-dasharray="3 2" />
                        <circle cx="12" cy="12" r="4" fill="currentColor" stroke="none" />
                    </svg>
                </Link>

                <!-- Channels -->
                <Link
                    :href="route('channels.index')"
                    class="wa-rail-btn"
                    :class="{ active: route().current('channels.index') }"
                    title="Каналы"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 12a8 8 0 10-14.93 4L4 20l4.07-1.07A8 8 0 0020 12z" />
                        <circle cx="8.5" cy="12" r="1" fill="currentColor" stroke="none" />
                        <circle cx="12" cy="12" r="1" fill="currentColor" stroke="none" />
                        <circle cx="15.5" cy="12" r="1" fill="currentColor" stroke="none" />
                    </svg>
                </Link>

                <!-- Communities -->
                <button
                    type="button"
                    class="wa-rail-btn"
                    title="Сообщества"
                    @click="notifySoon"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                    </svg>
                </button>
            </nav>

            <div class="flex flex-col items-center gap-1 pb-1">
                <!-- Profile avatar opens settings, just like WhatsApp Web -->
                <Link
                    :href="route('profile.edit')"
                    :title="user?.name"
                    class="block rounded-full transition"
                    :class="{ 'ring-2 ring-[var(--wa-accent)]': route().current('profile.edit') || route().current('settings.*') }"
                >
                    <div class="w-10 h-10 rounded-full bg-[#6b7c85] flex items-center justify-center text-white text-sm font-medium">
                        {{ initial(user?.name) }}
                    </div>
                </Link>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <slot />
        </div>
    </div>
</template>

<style scoped>
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
