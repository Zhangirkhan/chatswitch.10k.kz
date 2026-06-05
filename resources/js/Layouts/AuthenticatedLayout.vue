<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import Avatar from '@/Components/Avatar.vue';
import RailNav from '@/Components/RailNav.vue';
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
import { useI18n } from '@/composables/useI18n';
import { useToastStore } from '@/stores/toast';
import { whatsappStatusChannel as buildWhatsappStatusChannel } from '@/utils/tenantChannels';

const { t } = useI18n();
const { show: showToast } = useToastStore();

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

const nav = computed<Record<string, boolean>>(() => {
    const sections = page.props.nav as Record<string, boolean> | undefined;
    return sections ?? {};
});

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

const isDemoTenant = computed(() => page.props.tenantSlug === 'demo');

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

function sessionDisplayName(s: WhatsappSession): string {
    return s.display_name?.trim() || s.wa_name?.trim() || s.session_name;
}

function sessionTooltip(s: WhatsappSession): string {
    const parts = [sessionDisplayName(s)];
    if (s.phone_number) parts.push(formatPhone(s.phone_number) || s.phone_number);
    if (s.status === 'qr_pending') {
        parts.push(t('whatsapp.qrPendingTooltip'));
    } else {
        const statusKey = s.status === 'connected'
            ? 'whatsapp.status.connected'
            : s.status === 'connecting'
                ? 'whatsapp.status.connecting'
                : 'whatsapp.status.disconnected';
        parts.push(t(statusKey));
    }
    return parts.filter(Boolean).join(' · ');
}

const sessionsNeedingQr = computed(() =>
    whatsappSessions.value.filter((s) => s.status === 'qr_pending'),
);

function notifyQrRequired(session: WhatsappSession): void {
    const name = sessionDisplayName(session);
    showToast({
        type: 'warning',
        message: t('whatsapp.qrRequiredToast', { name }),
        duration: 12000,
        action: {
            label: t('whatsapp.openConnections'),
            handler: () => {
                router.visit(route('settings.connections'));
            },
        },
    });

    if (typeof Notification === 'undefined' || Notification.permission !== 'granted') {
        return;
    }

    try {
        new Notification(t('whatsapp.qrRequiredBanner', { name }), {
            body: t('whatsapp.qrPendingTooltip'),
            tag: `whatsapp-qr-${session.id}`,
        });
    } catch {
        /* ignore */
    }
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

    const previousStatus = session.status;

    // В демо-тенанте подключения имитируются; игнорируем события от whatsapp-service.
    if (isDemoTenant.value && status !== 'connected') {
        session.status = 'connected';
        return;
    }

    session.status = status as WhatsappSession['status'];
    if (typeof payload.phone_number === 'string') {
        session.phone_number = payload.phone_number;
    }
    if (typeof payload.wa_name === 'string') {
        session.wa_name = payload.wa_name;
    }

    if (status === 'qr_pending' && previousStatus === 'connected' && canSubscribeToWhatsappStatus.value) {
        notifyQrRequired(session);
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
            class="flex h-full w-[60px] shrink-0 flex-col items-center border-r py-3"
            :style="{ background: 'var(--wa-rail-bg)', borderColor: 'var(--wa-sidebar-divider)' }"
        >
            <RailNav
                class="min-h-0 w-full flex-1"
                :nav="nav"
                :unread-chats-count="unreadChatsCount"
                :calendar-badge-count="calendarBadgeCount"
            />

            <div
                v-if="whatsappSessions.length"
                class="flex w-full shrink-0 flex-col items-center gap-1.5 border-t px-1 pt-2"
                :style="{ borderColor: 'var(--wa-border)' }"
            >
                <Link
                    v-for="s in whatsappSessions"
                    :key="s.id"
                    :href="route('settings.connections')"
                    class="wa-session-chip shrink-0"
                    :title="sessionTooltip(s)"
                >
                    <span class="wa-session-chip-label">{{ sessionInitial(s) }}</span>
                    <span
                        class="wa-session-chip-dot"
                        :class="s.status === 'connected' ? 'is-online' : s.status === 'qr_pending' || s.status === 'connecting' ? 'is-pending' : 'is-offline'"
                    ></span>
                </Link>
            </div>

            <div class="mt-2 flex shrink-0 flex-col items-center pb-1">
                <Link
                    :href="route('profile.edit')"
                    :title="user?.name"
                    :aria-label="t('nav.profile')"
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
            <div
                v-if="canSubscribeToWhatsappStatus && sessionsNeedingQr.length"
                class="shrink-0 border-b px-4 py-2.5 text-sm"
                :style="{ background: 'color-mix(in srgb, #facc15 18%, var(--wa-page-bg))', borderColor: 'var(--wa-border)', color: 'var(--wa-text)' }"
            >
                <div
                    v-for="session in sessionsNeedingQr"
                    :key="session.id"
                    class="flex flex-wrap items-center justify-between gap-2"
                >
                    <span>{{ t('whatsapp.qrRequiredBanner', { name: sessionDisplayName(session) }) }}</span>
                    <Link
                        :href="route('settings.connections')"
                        class="font-medium underline underline-offset-2 whitespace-nowrap"
                    >
                        {{ t('whatsapp.openConnections') }}
                    </Link>
                </div>
            </div>
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
.wa-session-chip {
    position: relative;
    flex-shrink: 0;
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
