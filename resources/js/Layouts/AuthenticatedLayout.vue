<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import Avatar from '@/Components/Avatar.vue';
import ToastContainer from '@/Components/ToastContainer.vue';
import ConnectionLostOverlay from '@/Components/ConnectionLostOverlay.vue';
import UiViewTransition from '@/Components/Ui/UiViewTransition.vue';
import ImpersonationBanner from '@/Components/ImpersonationBanner.vue';
import PwaInstallBanner from '@/Components/PwaInstallBanner.vue';
import type { WhatsappSession } from '@/types';
import { formatPhone } from '@/utils/phone';
import { useChatsListDesktopNotifications } from '@/composables/useChatsListDesktopNotifications';
import { useUnreadFavicon } from '@/composables/useUnreadFavicon';
import { useLiveUnreadCount } from '@/composables/useLiveUnreadCount';
import { whatsappStatusChannel as buildWhatsappStatusChannel } from '@/utils/tenantChannels';

const page = usePage<any>();
const tenantCompanyId = computed(() => Number(page.props.tenantCompanyId || 0));
const user = computed(() => page.props.auth.user);
const userId = computed(() => (typeof user.value?.id === 'number' ? user.value.id : null));

useChatsListDesktopNotifications(
    () => userId.value,
    () => userId.value,
);

// Живой счётчик непрочитанных — инициализируется из Inertia-пропов,
// затем обновляется через WebSocket в ChatSidebar без перезагрузки страницы.
const liveUnread = useLiveUnreadCount();
const unreadChatsCount = computed<number>(() => {
    // Если ChatSidebar ещё не проинициализировал синглтон — берём из Inertia-пропов
    if (!liveUnread.initialized()) {
        return Number(page.props.unreadChatsCount || 0);
    }
    return liveUnread.count.value;
});

const calendarBadgeCount = computed<number>(() => Number(page.props.calendarBadgeCount || 0));

// Синхронизируем синглтон когда Inertia обновляет пропы (навигация, reload)
watch(
    () => page.props.unreadChatsCount as number | undefined,
    (n) => { liveUnread.set(Number(n || 0)); },
    { immediate: true },
);

useUnreadFavicon(() => unreadChatsCount.value);

const whatsappSessions = ref<WhatsappSession[]>([]);
let whatsappStatusChannel: any = null;

const canSubscribeToWhatsappStatus = computed(() => {
    const roles = user.value?.roles;
    return Array.isArray(roles) && (roles.includes('administrator') || roles.includes('manager'));
});

watch(
    () => page.props.whatsappSessions as WhatsappSession[] | undefined,
    (sessions) => {
        whatsappSessions.value = [...(sessions || [])];
    },
    { immediate: true },
);

function sessionInitial(s: WhatsappSession): string {
    const src = s.display_name || s.wa_name || s.session_name || '?';
    return src.trim().charAt(0).toUpperCase();
}

function sessionTooltip(s: WhatsappSession): string {
    const parts = [s.display_name || s.wa_name || s.session_name];
    if (s.phone_number) parts.push(formatPhone(s.phone_number) || s.phone_number);
    const statusLabel = s.status === 'connected'
        ? 'Подключён'
        : s.status === 'qr_pending'
            ? 'Ожидание QR'
            : s.status === 'connecting'
                ? 'Подключение…'
                : 'Отключён';
    parts.push(statusLabel);
    return parts.filter(Boolean).join(' · ');
}

function onWhatsappStatusChanged(raw: unknown): void {
    if (!raw || typeof raw !== 'object') return;

    const payload = raw as Record<string, unknown>;
    const sessionName = typeof payload.session === 'string' ? payload.session : null;
    const status = typeof payload.status === 'string' ? payload.status : null;
    if (!sessionName || !status) return;

    const allowed: WhatsappSession['status'][] = ['disconnected', 'connecting', 'qr_pending', 'connected'];
    if (!allowed.includes(status as WhatsappSession['status'])) return;

    const session = whatsappSessions.value.find((s) => s.session_name === sessionName);
    if (!session) return;

    session.status = status as WhatsappSession['status'];
    if (typeof payload.phone_number === 'string') {
        session.phone_number = payload.phone_number;
    }
    if (typeof payload.wa_name === 'string') {
        session.wa_name = payload.wa_name;
    }
}

onMounted(() => {
    const Echo = (window as any).Echo;

    if (Echo) {
        if (canSubscribeToWhatsappStatus.value) {
            try {
                whatsappStatusChannel = Echo.private(buildWhatsappStatusChannel(tenantCompanyId.value));
                whatsappStatusChannel.listen('.status.changed', onWhatsappStatusChanged);
            } catch {
                whatsappStatusChannel = null;
            }
        }
    } else {
        // Ждём инициализации Echo (Reverb может загружаться асинхронно)
        let waited = 0;
        const iv = setInterval(() => {
            waited += 300;
            if ((window as any).Echo) {
                clearInterval(iv);
                if (canSubscribeToWhatsappStatus.value) {
                    try {
                        whatsappStatusChannel = (window as any).Echo.private(buildWhatsappStatusChannel(tenantCompanyId.value));
                        whatsappStatusChannel.listen('.status.changed', onWhatsappStatusChanged);
                    } catch {
                        whatsappStatusChannel = null;
                    }
                }
            } else if (waited >= 15_000) {
                clearInterval(iv);
            }
        }, 300);
    }
});

onUnmounted(() => {
    try {
        whatsappStatusChannel?.stopListening('.status.changed', onWhatsappStatusChanged);
    } catch {
        /* ignore socket cleanup errors */
    }
    whatsappStatusChannel = null;
});
</script>

<template>
    <ImpersonationBanner />
    <div
        class="h-screen w-screen flex bg-[var(--wa-page-bg)] text-[var(--wa-text)] overflow-hidden"
        :class="{ 'has-impersonation-banner': Boolean(page.props.impersonation) }"
    >
        <aside
            class="w-[60px] shrink-0 flex flex-col items-center py-3 border-r"
            :style="{ background: 'var(--wa-rail-bg)', borderColor: 'var(--wa-sidebar-divider)' }"
        >
            <nav class="flex flex-col items-center gap-1 flex-1">
                <Link
                    :href="route('chats.index')"
                    class="wa-rail-btn relative"
                    :class="{ active: route().current('chats.index') || route().current('chats.show') || route().current('chats.archived') }"
                    title="Чаты"
                    aria-label="Чаты"
                    :aria-current="route().current('chats.index') || route().current('chats.show') || route().current('chats.archived') ? 'page' : undefined"
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

                <Link
                    v-if="route().has('clients.index')"
                    :href="route('clients.index')"
                    class="wa-rail-btn"
                    :class="{ active: route().current('clients.*') }"
                    title="Клиенты"
                    aria-label="Клиенты"
                    :aria-current="route().current('clients.*') ? 'page' : undefined"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                    </svg>
                </Link>

                <Link
                    v-if="route().has('broadcasts.index')"
                    :href="route('broadcasts.index')"
                    class="wa-rail-btn"
                    :class="{ active: route().current('broadcasts.*') }"
                    title="Рассылки"
                    aria-label="Рассылки"
                    :aria-current="route().current('broadcasts.*') ? 'page' : undefined"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                    </svg>
                </Link>

                <Link
                    v-if="route().has('ai-chat.index')"
                    :href="route('ai-chat.index')"
                    class="wa-rail-btn"
                    :class="{ active: route().current('ai-chat.*') }"
                    title="ИИ чат"
                    aria-label="ИИ чат"
                    :aria-current="route().current('ai-chat.*') ? 'page' : undefined"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" />
                    </svg>
                </Link>

                <Link
                    v-if="route().has('analytics.dialogs') && (page.props.modules?.analytics || page.props.modules?.funnels)"
                    :href="route('analytics.dialogs')"
                    class="wa-rail-btn"
                    :class="{ active: route().current('analytics.*') }"
                    title="Аналитика диалогов"
                    aria-label="Аналитика диалогов"
                    :aria-current="route().current('analytics.*') ? 'page' : undefined"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18M7 16l4-8 4 5 4-10" />
                    </svg>
                </Link>

                <Link
                    v-if="route().has('calendar.index') && page.props.modules?.calendar"
                    :href="route('calendar.index')"
                    class="wa-rail-btn relative"
                    :class="{ active: route().current('calendar.*') }"
                    title="Календарь"
                    aria-label="Календарь"
                    :aria-current="route().current('calendar.*') ? 'page' : undefined"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <line x1="16" y1="2" x2="16" y2="6" stroke-linecap="round"/>
                        <line x1="8" y1="2" x2="8" y2="6" stroke-linecap="round"/>
                        <line x1="3" y1="10" x2="21" y2="10" stroke-linecap="round"/>
                    </svg>
                    <span
                        v-if="calendarBadgeCount > 0"
                        class="absolute -top-0.5 -right-0.5 min-w-[18px] h-[18px] rounded-full text-[10px] font-semibold flex items-center justify-center px-1 leading-none"
                        :style="{ background: 'var(--wa-unread)', color: 'var(--wa-unread-text)' }"
                        :title="`Записей сегодня: ${calendarBadgeCount}`"
                    >
                        {{ calendarBadgeCount > 99 ? '99+' : calendarBadgeCount }}
                    </span>
                </Link>

                <Link
                    v-if="route().has('funnels.board') && page.props.modules?.funnels"
                    :href="route('funnels.board')"
                    class="wa-rail-btn"
                    :class="{ active: route().current('funnels.board') }"
                    title="Воронки"
                    aria-label="Воронки"
                    :aria-current="route().current('funnels.board') ? 'page' : undefined"
                >
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h5v5H4V6zm0 7h5v5H4v-5zm7-7h9v3h-9V6zm0 5h9v3h-9v-3zm0 5h9v3h-9v-3z" />
                    </svg>
                </Link>

                <template v-if="whatsappSessions.length">
                    <div
                        class="w-7 h-px my-1 shrink-0"
                        :style="{ background: 'var(--wa-border)' }"
                    ></div>
                    <Link
                        v-for="s in whatsappSessions"
                        :key="s.id"
                        :href="route('settings.connections')"
                        class="wa-session-chip"
                        :title="sessionTooltip(s)"
                    >
                        <span class="wa-session-chip-label">{{ sessionInitial(s) }}</span>
                        <span
                            class="wa-session-chip-dot"
                            :class="s.status === 'connected' ? 'is-online' : s.status === 'qr_pending' || s.status === 'connecting' ? 'is-pending' : 'is-offline'"
                        ></span>
                    </Link>
                </template>
            </nav>

            <div class="flex flex-col items-center gap-1 pb-1">
                <Link
                    :href="route('profile.edit')"
                    :title="user?.name"
                    aria-label="Профиль и настройки"
                    :aria-current="route().current('profile.edit') || route().current('settings.*') ? 'page' : undefined"
                    class="block rounded-full transition"
                    :class="{ 'ring-2 ring-[var(--wa-accent)]': route().current('profile.edit') || route().current('settings.*') }"
                >
                    <Avatar
                        :avatar-url="user?.profile_photo_url"
                        :name="user?.name"
                        fallback-initials
                        :size="40"
                    />
                </Link>
            </div>
        </aside>

        <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            <UiViewTransition
                scope="app-shell"
                panel-class="flex flex-1 min-h-0 min-w-0 flex-col overflow-hidden"
                class="flex flex-1 min-h-0 min-w-0 flex-col overflow-hidden"
            >
                <slot />
            </UiViewTransition>
        </div>

        <ToastContainer />
        <ConnectionLostOverlay />
        <PwaInstallBanner />
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
.wa-session-chip {
    position: relative;
    width: 2.25rem;
    height: 2.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 9999px;
    color: var(--wa-text);
    background: var(--wa-rail-btn-hover);
    font-size: 0.75rem;
    font-weight: 600;
    text-decoration: none;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
    cursor: pointer;
    user-select: none;
}
.wa-session-chip:hover {
    transform: scale(1.05);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--wa-accent) 45%, transparent);
}
.wa-session-chip-label {
    pointer-events: none;
    line-height: 1;
}
.wa-session-chip-dot {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 0.625rem;
    height: 0.625rem;
    border-radius: 9999px;
    border: 2px solid var(--wa-rail-bg);
    background: #94a3b8;
}
.wa-session-chip-dot.is-online {
    background: var(--wa-accent);
}
.wa-session-chip-dot.is-pending {
    background: #facc15;
}
.wa-session-chip-dot.is-offline {
    background: #ef4444;
}

.has-impersonation-banner {
    padding-top: 2.25rem;
    box-sizing: border-box;
}
</style>
