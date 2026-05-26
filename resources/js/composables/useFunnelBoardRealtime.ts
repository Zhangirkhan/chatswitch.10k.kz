import type { Ref } from 'vue';
import { onBeforeUnmount, onMounted, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { funnelBoardChannel } from '@/utils/tenantChannels';

export type FunnelBoardCard = {
    id: number;
    name: string;
    phone: string | null;
    last_message_text: string | null;
    last_message_at: string | null;
    unread_count: number;
    assignees: { id: number; name: string }[];
    funnel_stage_id: number | null;
    funnel_stage_locked: boolean;
    funnel_ai_reason?: string | null;
    actor_name?: string | null;
};

type BoardStage = {
    id: number;
    name: string;
    cards: FunnelBoardCard[];
};

type RemoteUpdatePayload = {
    funnel_id: number;
    chat_id: number;
    stage_id: number | null;
    actor_user_id: number | null;
    source: string;
    card: FunnelBoardCard | null;
};

export function useFunnelBoardRealtime(options: {
    funnelId: Ref<number | null>;
    stages: Ref<BoardStage[]>;
    userId: Ref<number | null>;
    movingCardId: Ref<number | null>;
    draggingCardId: Ref<number | null>;
    onUnknownCard: () => void;
    onExternalMove: (card: FunnelBoardCard, stageId: number, actorName: string | null) => void;
    onConflict: (card: FunnelBoardCard, actorName: string | null) => void;
}): void {
    let echoChannel: { listen: (event: string, cb: (payload: RemoteUpdatePayload) => void) => void } | null = null;
    let reloadTimer: number | null = null;
    const pendingUpdates = new Map<number, RemoteUpdatePayload>();

    function teardown(): void {
        if (reloadTimer !== null) {
            clearTimeout(reloadTimer);
            reloadTimer = null;
        }
        pendingUpdates.clear();
        const funnelId = options.funnelId.value;
        const Echo = (window as Window & { Echo?: { leave: (name: string) => void } }).Echo;
        if (Echo && funnelId != null) {
            try {
                const tenantId = Number(usePage().props.tenantCompanyId || 0);
                Echo.leave(funnelBoardChannel(tenantId, funnelId));
            } catch {
                /* ignore */
            }
        }
        echoChannel = null;
    }

    function scheduleReload(): void {
        if (reloadTimer !== null) {
            clearTimeout(reloadTimer);
        }
        reloadTimer = window.setTimeout(() => {
            reloadTimer = null;
            options.onUnknownCard();
        }, 300);
    }

    function moveCardLocally(chatId: number, stageId: number | null, cardPayload: FunnelBoardCard | null): FunnelBoardCard | null {
        let moved: FunnelBoardCard | null = null;

        for (const stage of options.stages.value) {
            const idx = stage.cards.findIndex((c) => c.id === chatId);
            if (idx >= 0) {
                moved = { ...stage.cards[idx], ...(cardPayload ?? {}) };
                stage.cards.splice(idx, 1);
                break;
            }
        }

        if (moved === null && cardPayload) {
            moved = cardPayload;
        }

        if (moved === null) {
            return null;
        }

        moved.funnel_stage_id = stageId;
        const targetStageId = stageId ?? 0;
        const target = options.stages.value.find((s) => s.id === targetStageId);
        if (!target) {
            scheduleReload();
            return moved;
        }

        target.cards.unshift(moved);
        return moved;
    }

    function applyRemoteUpdate(payload: RemoteUpdatePayload): void {
        const moved = moveCardLocally(payload.chat_id, payload.stage_id, payload.card);
        if (moved === null) {
            scheduleReload();
            return;
        }

        if (payload.actor_user_id != null && payload.actor_user_id !== options.userId.value) {
            options.onExternalMove(moved, payload.stage_id ?? 0, payload.card?.actor_name ?? null);
        }
    }

    function flushPendingForCard(chatId: number): void {
        const payload = pendingUpdates.get(chatId);
        if (!payload) {
            return;
        }
        pendingUpdates.delete(chatId);
        applyRemoteUpdate(payload);
        if (payload.actor_user_id != null && payload.actor_user_id !== options.userId.value) {
            options.onConflict(payload.card ?? { id: chatId, name: 'Контакт' } as FunnelBoardCard, payload.card?.actor_name ?? null);
        }
    }

    function onRemoteUpdate(payload: RemoteUpdatePayload): void {
        if (options.funnelId.value == null || payload.funnel_id !== options.funnelId.value) {
            return;
        }

        if (payload.actor_user_id != null && payload.actor_user_id === options.userId.value) {
            if (options.movingCardId.value === payload.chat_id) {
                return;
            }
        }

        if (options.draggingCardId.value === payload.chat_id) {
            pendingUpdates.set(payload.chat_id, payload);
            return;
        }

        if (options.movingCardId.value === payload.chat_id && payload.actor_user_id !== options.userId.value) {
            pendingUpdates.set(payload.chat_id, payload);
            return;
        }

        applyRemoteUpdate(payload);
    }

    function subscribe(funnelId: number | null): void {
        teardown();
        if (funnelId == null) {
            return;
        }

        const Echo = (window as any).Echo;
        if (!Echo) {
            return;
        }

        const tenantId = Number(usePage().props.tenantCompanyId || 0);
        echoChannel = Echo.private(funnelBoardChannel(tenantId, funnelId));
        echoChannel?.listen('.card.updated', onRemoteUpdate);
    }

    watch(
        () => options.draggingCardId.value,
        (current, previous) => {
            if (previous != null && current == null) {
                flushPendingForCard(previous);
            }
        },
    );

    watch(
        () => options.movingCardId.value,
        (current, previous) => {
            if (previous != null && current == null) {
                flushPendingForCard(previous);
            }
        },
    );

    onMounted(() => {
        subscribe(options.funnelId.value);
    });

    watch(
        () => options.funnelId.value,
        (id) => {
            subscribe(id);
        },
    );

    onBeforeUnmount(() => {
        teardown();
    });
}
