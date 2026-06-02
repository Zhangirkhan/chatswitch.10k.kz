<script setup lang="ts">
import Avatar from '@/Components/Avatar.vue';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import FunnelStageIcon from '@/Components/Funnel/FunnelStageIcon.vue';
import FunnelStageSparkline from '@/Components/Funnel/FunnelStageSparkline.vue';
import SkeletonBlock from '@/Components/SkeletonBlock.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiPillNav from '@/Components/Ui/UiPillNav.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { useFunnelBoardKeyboard } from '@/composables/useFunnelBoardKeyboard';
import { useFunnelBoardPresence } from '@/composables/useFunnelBoardPresence';
import { useFunnelBoardRealtime } from '@/composables/useFunnelBoardRealtime';
import { useFunnelBoardSortable } from '@/composables/useFunnelBoardSortable';
import { funnelStageTypeLabel } from '@/utils/funnelStageTypes';
import { formatPhone } from '@/utils/phone';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch, Transition, type ComponentPublicInstance } from 'vue';
import { useI18n } from '@/composables/useI18n';
import { useToastStore } from '@/stores/toast';

type BoardScope = 'all' | 'mine' | 'department';

const INBOX_STAGE_ID = -1;
const ORPHAN_STAGE_ID = 0;

type StageStats = {
    cards_total: number;
    entered_7d: number;
    conversion_pct: number | null;
    avg_days: number | null;
    sparkline: number[];
};

type FunnelOption = {
    id: number;
    name: string;
    color: string;
    description?: string | null;
};

type BoardCard = {
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
};

type BoardStage = {
    id: number;
    name: string;
    color: string;
    stage_type: string;
    stage_tone: 'default' | 'done' | 'lost' | 'neutral';
    position: number;
    is_inbox?: boolean;
    cards: BoardCard[];
    cards_total?: number;
    has_more?: boolean;
    stats?: StageStats;
    wip_limit?: number | null;
};

type FunnelHistoryItem = {
    id: number;
    source: string;
    reason: string | null;
    confidence: number | null;
    created_at: string | null;
};

type FilterOption = { id: number; name?: string; label?: string };

const props = defineProps<{
    funnels: FunnelOption[];
    selectedFunnelId: number | null;
    filters: Record<string, string | number | undefined>;
    board: {
        funnel: { id: number; name: string; color: string; description?: string | null } | null;
        stages: BoardStage[];
    };
    canFilterAll: boolean;
    canUseAdvancedFilters: boolean;
    filterAssignees: FilterOption[];
    filterDepartments: FilterOption[];
    filterWhatsappSessions: FilterOption[];
}>();

const page = usePage<any>();
const { show: showToast } = useToastStore();
const { t } = useI18n();

const funnelsModuleEnabled = computed(() => Boolean(page.props.modules?.funnels ?? true));
const analyticsEnabled = computed(() => Boolean(page.props.modules?.analytics ?? true));
const userId = computed(() => (typeof page.props.auth?.user?.id === 'number' ? page.props.auth.user.id : null));

const selectedFunnelId = ref<number | null>(props.selectedFunnelId);
const scope = ref<BoardScope>((props.filters.scope as BoardScope) || 'all');
const assigneeFilter = ref(String(props.filters.assignee_id ?? ''));
const departmentFilter = ref(String(props.filters.department_id ?? ''));
const sessionFilter = ref(String(props.filters.whatsapp_session_id ?? ''));
const stages = ref<BoardStage[]>([...(props.board.stages ?? [])]);
const loading = ref(false);
const searchQuery = ref(String(props.filters.search ?? ''));
const hideEmptyColumns = ref(false);
const hideDoneColumns = ref(false);

const selectedCardIds = ref<Set<number>>(new Set());
const selectionMode = ref(false);
const bulkTargetStageId = ref<string>('');
const bulkMoving = ref(false);

const columnListRefs = ref(new Map<number, HTMLElement>());
const loadingStageId = ref<number | null>(null);

const historyOpen = ref(false);
const historyCard = ref<BoardCard | null>(null);
const historyItems = ref<FunnelHistoryItem[]>([]);
const historyLoading = ref(false);
const historyError = ref<string | null>(null);

const movingCardId = ref<number | null>(null);
const draggingCardId = ref<number | null>(null);
const openCardActionsId = ref<number | null>(null);
const focusStageIdx = ref(0);
const focusCardIdx = ref(0);
const cardRefs = ref(new Map<string, HTMLElement>());
const lockedConfirmOpen = ref(false);
const pendingDrop = ref<{ cardId: number; targetStageId: number } | null>(null);

let searchDebounce: ReturnType<typeof setTimeout> | null = null;

watch(
    () => props.board,
    (value) => {
        stages.value = [...(value.stages ?? [])];
    },
    { deep: true },
);

watch(
    () => props.selectedFunnelId,
    (id) => {
        selectedFunnelId.value = id;
    },
);

watch(searchQuery, () => {
    if (searchDebounce) {
        clearTimeout(searchDebounce);
    }
    searchDebounce = setTimeout(() => {
        navigateBoard();
    }, 350);
});

useFunnelBoardRealtime({
    funnelId: selectedFunnelId,
    stages,
    userId,
    movingCardId,
    draggingCardId,
    onUnknownCard: () => {
        void reloadBoard(true);
    },
    onExternalMove: (card, _stageId, actorName) => {
        showToast({
            message: t('funnels.colleagueMoved', { actor: actorName || t('funnels.colleagueDefault'), card: card.name }),
            type: 'info',
        });
    },
    onConflict: (card, actorName) => {
        showToast({
            message: t('funnels.colleagueOverride', { actor: actorName || t('funnels.colleagueDefault'), card: card.name }),
            type: 'warning',
        });
    },
});

const { viewers: boardViewers } = useFunnelBoardPresence(selectedFunnelId);

const otherViewers = computed(() =>
    boardViewers.value.filter((viewer) => viewer.id !== userId.value),
);

const totalCards = computed(() =>
    stages.value.reduce((sum, stage) => sum + (stage.cards_total ?? stage.cards.length), 0),
);

const selectedCount = computed(() => selectedCardIds.value.size);

const bulkStageOptions = computed(() =>
    stages.value
        .filter((stage) => stage.id !== ORPHAN_STAGE_ID)
        .map((stage) => ({ id: stage.id, name: stage.name })),
);

const visibleStages = computed(() =>
    stages.value.filter((stage) => {
        if (stage.is_inbox) {
            return true;
        }
        if (hideEmptyColumns.value && stage.cards.length === 0) {
            return false;
        }
        if (hideDoneColumns.value && (stage.stage_tone === 'done' || stage.stage_tone === 'lost')) {
            return false;
        }
        return true;
    }),
);

const keyboardEnabled = computed(() => !loading.value && props.funnels.length > 0);

function setColumnListRef(stageId: number, el: Element | ComponentPublicInstance | null): void {
    if (el instanceof HTMLElement) {
        columnListRefs.value.set(stageId, el);
    } else {
        columnListRefs.value.delete(stageId);
    }
}

function cardRefKey(stageIdx: number, cardIdx: number): string {
    return `${stageIdx}:${cardIdx}`;
}

function setCardRef(stageIdx: number, cardIdx: number, el: Element | ComponentPublicInstance | null): void {
    const key = cardRefKey(stageIdx, cardIdx);
    if (el instanceof HTMLElement) {
        cardRefs.value.set(key, el);
    } else {
        cardRefs.value.delete(key);
    }
}

function isFocusedCard(stageIdx: number, cardIdx: number): boolean {
    return focusStageIdx.value === stageIdx && focusCardIdx.value === cardIdx;
}

function focusCardElement(stageIdx: number, cardIdx: number): void {
    focusStageIdx.value = stageIdx;
    focusCardIdx.value = cardIdx;
    cardRefs.value.get(cardRefKey(stageIdx, cardIdx))?.focus({ preventScroll: true });
}

function stageCardsTotal(stage: BoardStage): number {
    return stage.cards_total ?? stage.cards.length;
}

function isStageWipFull(stage: BoardStage): boolean {
    if (!stage.wip_limit) {
        return false;
    }

    return stageCardsTotal(stage) >= stage.wip_limit;
}

function canAcceptCardsOnStage(stage: BoardStage, incomingCount = 1): boolean {
    if (!stage.wip_limit || stage.is_inbox) {
        return true;
    }

    return stageCardsTotal(stage) + incomingCount <= stage.wip_limit;
}

function wipBlockedMessage(stage: BoardStage): string {
    return t('funnels.wipLimit', { stage: stage.name, limit: stage.wip_limit ?? 0 });
}

function isCardSelected(cardId: number): boolean {
    return selectedCardIds.value.has(cardId);
}

function toggleCardSelection(cardId: number): void {
    const next = new Set(selectedCardIds.value);
    if (next.has(cardId)) {
        next.delete(cardId);
    } else {
        next.add(cardId);
    }
    selectedCardIds.value = next;
}

function clearSelection(): void {
    selectedCardIds.value = new Set();
    selectionMode.value = false;
}

function onCardClick(card: BoardCard, event: MouseEvent): void {
    if (event.shiftKey || selectionMode.value) {
        event.preventDefault();
        event.stopPropagation();
        toggleCardSelection(card.id);
        return;
    }
    openChat(card);
}

useFunnelBoardSortable({
    stages,
    columnListRefs,
    onDragStart: (cardId) => {
        draggingCardId.value = cardId;
    },
    onDragEnd: () => {
        draggingCardId.value = null;
    },
    onMove: (payload) => {
        void onSortableMove(payload);
    },
});

function closeCardActions(): void {
    openCardActionsId.value = null;
}

function toggleCardActions(cardId: number, event: MouseEvent): void {
    event.preventDefault();
    event.stopPropagation();
    openCardActionsId.value = openCardActionsId.value === cardId ? null : cardId;
}

async function assignCardToMe(card: BoardCard, event: MouseEvent): Promise<void> {
    event.stopPropagation();
    closeCardActions();
    if (userId.value == null) {
        return;
    }
    try {
        const { data } = await axios.post(route('chats.assign', card.id), { user_id: userId.value });
        const assignees = (data.assignments ?? [])
            .map((row: { user?: { id: number; name: string } }) => row.user)
            .filter(Boolean);
        updateCardAssignees(card.id, assignees);
        showToast({ message: t('funnels.assignedToYou', { name: card.name }), type: 'info' });
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        showToast({ message: err?.response?.data?.message || t('funnels.assignFailed'), type: 'warning' });
    }
}

async function moveCardToInbox(card: BoardCard, event: MouseEvent): Promise<void> {
    event.stopPropagation();
    closeCardActions();
    await performMove(card.id, INBOX_STAGE_ID);
}

function updateCardAssignees(cardId: number, assignees: { id: number; name: string }[]): void {
    for (const stage of stages.value) {
        const card = stage.cards.find((c) => c.id === cardId);
        if (card) {
            card.assignees = assignees;
            return;
        }
    }
}

function onSortableMove(payload: { cardId: number; fromStageId: number; toStageId: number }): void {
    if (payload.fromStageId === payload.toStageId) {
        void reloadBoard(true);
        return;
    }

    const location = findCardLocation(payload.cardId);
    if (!location) {
        void reloadBoard(true);
        return;
    }

    const card = stages.value[location.stageIndex].cards[location.cardIndex];
    const targetStage = stages.value.find((s) => s.id === payload.toStageId);
    if (targetStage && !canAcceptCardsOnStage(targetStage)) {
        showToast({ message: wipBlockedMessage(targetStage), type: 'warning' });
        void reloadBoard(true);
        return;
    }

    if (card.funnel_stage_locked) {
        pendingDrop.value = { cardId: payload.cardId, targetStageId: payload.toStageId };
        lockedConfirmOpen.value = true;
        void reloadBoard(true);
        return;
    }

    void performMove(payload.cardId, payload.toStageId);
}

function filterParams(): Record<string, string | number | undefined> {
    return {
        funnel_id: selectedFunnelId.value ?? undefined,
        scope: scope.value,
        assignee_id: assigneeFilter.value || undefined,
        department_id: departmentFilter.value || undefined,
        whatsapp_session_id: sessionFilter.value || undefined,
        search: searchQuery.value.trim() || undefined,
    };
}

function navigateBoard(): void {
    router.get(route('funnels.board'), filterParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

async function reloadBoard(silent = false): Promise<void> {
    if (selectedFunnelId.value == null) {
        stages.value = [];
        return;
    }

    if (!silent) {
        loading.value = true;
    }
    try {
        const { data } = await axios.get(route('funnels.board.data'), {
            params: filterParams(),
        });
        stages.value = data.stages ?? [];
    } catch {
        if (!silent) {
            showToast({ message: t('funnels.loadBoardFailed'), type: 'warning' });
        }
    } finally {
        if (!silent) {
            loading.value = false;
        }
    }
}

function selectFunnel(id: number): void {
    if (selectedFunnelId.value === id) {
        return;
    }
    selectedFunnelId.value = id;
    router.get(route('funnels.board'), { ...filterParams(), funnel_id: id }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function selectScope(next: BoardScope): void {
    if (scope.value === next) {
        return;
    }
    scope.value = next;
    navigateBoard();
}

function onAdvancedFilterChange(): void {
    navigateBoard();
}

function formatRelativeTime(iso: string | null): string {
    if (!iso) {
        return '';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    const now = Date.now();
    const diffMs = now - date.getTime();
    const diffMin = Math.floor(diffMs / 60_000);
    if (diffMin < 1) {
        return t('funnels.now');
    }
    if (diffMin < 60) {
        return t('funnels.minutesAgo', { count: diffMin });
    }
    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24) {
        return t('funnels.hoursShort', { count: diffH });
    }
    return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
}

function assigneeLabel(card: BoardCard): string {
    if (card.assignees.length === 0) {
        return t('funnels.notAssigned');
    }
    if (card.assignees.length === 1) {
        return card.assignees[0].name;
    }
    return `${card.assignees[0].name} +${card.assignees.length - 1}`;
}

function findCardLocation(cardId: number): { stageIndex: number; cardIndex: number } | null {
    for (let stageIndex = 0; stageIndex < stages.value.length; stageIndex++) {
        const cardIndex = stages.value[stageIndex].cards.findIndex((c) => c.id === cardId);
        if (cardIndex >= 0) {
            return { stageIndex, cardIndex };
        }
    }
    return null;
}

function buildMovePayload(targetStageId: number): { funnel_id: number | null; funnel_stage_id: number | null } {
    if (targetStageId === INBOX_STAGE_ID) {
        return { funnel_id: null, funnel_stage_id: null };
    }

    const funnelId = selectedFunnelId.value;
    if (funnelId == null) {
        return { funnel_id: null, funnel_stage_id: null };
    }

    if (targetStageId === ORPHAN_STAGE_ID) {
        return { funnel_id: funnelId, funnel_stage_id: null };
    }

    return {
        funnel_id: funnelId,
        funnel_stage_id: targetStageId,
    };
}

function openChat(card: BoardCard): void {
    if (movingCardId.value === card.id) {
        return;
    }
    router.visit(route('chats.show', card.id));
}

async function performMove(cardId: number, targetStageId: number): Promise<void> {
    if (selectedFunnelId.value == null) {
        return;
    }

    const location = findCardLocation(cardId);
    if (!location) {
        return;
    }

    const sourceStage = stages.value[location.stageIndex];
    if (sourceStage.id === targetStageId) {
        return;
    }

    const card = sourceStage.cards[location.cardIndex];
    const targetStageIndex = stages.value.findIndex((s) => s.id === targetStageId);
    if (targetStageIndex < 0) {
        return;
    }

    const targetStage = stages.value[targetStageIndex];
    if (targetStage && !canAcceptCardsOnStage(targetStage)) {
        showToast({ message: wipBlockedMessage(targetStage), type: 'warning' });
        return;
    }

    const payload = buildMovePayload(targetStageId);
    const previousStageId = card.funnel_stage_id;

    sourceStage.cards.splice(location.cardIndex, 1);
    if (sourceStage.cards_total != null) {
        sourceStage.cards_total = Math.max(0, sourceStage.cards_total - 1);
    }
    card.funnel_stage_id = targetStageId === INBOX_STAGE_ID || targetStageId === ORPHAN_STAGE_ID
        ? null
        : targetStageId;
    stages.value[targetStageIndex].cards.unshift(card);
    if (stages.value[targetStageIndex].cards_total != null) {
        stages.value[targetStageIndex].cards_total += 1;
    }

    movingCardId.value = cardId;
    try {
        await axios.patch(route('chats.funnel.update', cardId), payload);
        showToast({ message: `«${card.name}» → ${stages.value[targetStageIndex].name}`, type: 'info' });
        selectedCardIds.value.delete(cardId);
    } catch (e: unknown) {
        card.funnel_stage_id = previousStageId;
        stages.value[targetStageIndex].cards = stages.value[targetStageIndex].cards.filter((c) => c.id !== cardId);
        sourceStage.cards.splice(location.cardIndex, 0, card);
        const err = e as { response?: { data?: { message?: string } } };
        showToast({ message: err?.response?.data?.message || t('funnels.moveFailed'), type: 'warning' });
        await reloadBoard(true);
    } finally {
        movingCardId.value = null;
    }
}

async function bulkMoveSelected(): Promise<void> {
    if (selectedFunnelId.value == null || selectedCount.value === 0 || bulkTargetStageId.value === '') {
        return;
    }

    const targetStage = stages.value.find((s) => s.id === Number(bulkTargetStageId.value));
    if (targetStage && !canAcceptCardsOnStage(targetStage, selectedCount.value)) {
        showToast({ message: wipBlockedMessage(targetStage), type: 'warning' });
        return;
    }

    bulkMoving.value = true;
    try {
        const { data } = await axios.post(route('funnels.board.bulk-move'), {
            funnel_id: selectedFunnelId.value,
            stage_id: Number(bulkTargetStageId.value),
            chat_ids: [...selectedCardIds.value],
        });
        showToast({
            message: t('funnels.bulkMoved', { moved: data.moved ?? 0, skipped: data.skipped ? t('funnels.bulkSkippedSuffix', { skipped: data.skipped }) : '' }),
            type: data.moved > 0 ? 'info' : 'warning',
        });
        clearSelection();
        bulkTargetStageId.value = '';
        await reloadBoard(true);
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        showToast({ message: err?.response?.data?.message || t('funnels.bulkMoveFailed'), type: 'warning' });
    } finally {
        bulkMoving.value = false;
    }
}

async function loadMoreCards(stage: BoardStage): Promise<void> {
    if (selectedFunnelId.value == null || loadingStageId.value != null) {
        return;
    }

    loadingStageId.value = stage.id;
    try {
        const { data } = await axios.get(route('funnels.board.stage-cards'), {
            params: {
                ...filterParams(),
                stage_id: stage.id,
                offset: stage.cards.length,
            },
        });
        const target = stages.value.find((s) => s.id === stage.id);
        const newCards = (data.cards ?? []) as BoardCard[];
        if (target && newCards.length > 0) {
            target.cards.push(...newCards);
            target.has_more = newCards.length >= 50;
        } else if (target) {
            target.has_more = false;
        }
    } catch {
        showToast({ message: t('funnels.loadCardsFailed'), type: 'warning' });
    } finally {
        loadingStageId.value = null;
    }
}

async function openHistoryPanel(card: BoardCard, event: MouseEvent): Promise<void> {
    event.preventDefault();
    event.stopPropagation();
    historyCard.value = card;
    historyOpen.value = true;
    historyItems.value = [];
    historyError.value = null;
    historyLoading.value = true;
    try {
        const { data } = await axios.get(route('chats.funnel.history', card.id));
        historyItems.value = Array.isArray(data.data) ? data.data : [];
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        historyError.value = err?.response?.data?.message || t('funnels.historyFailed');
    } finally {
        historyLoading.value = false;
    }
}

function closeHistoryPanel(): void {
    historyOpen.value = false;
    historyCard.value = null;
    historyItems.value = [];
    historyError.value = null;
}

function formatHistorySource(source: string): string {
    if (source === 'manual') {
        return t('funnels.sourceManual');
    }
    if (source === 'ai') {
        return 'AI';
    }
    if (source === 'system') {
        return t('funnels.sourceSystem');
    }
    return source;
}

function formatHistoryDate(iso: string | null): string {
    if (!iso) {
        return '';
    }
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) {
        return iso;
    }
    return date.toLocaleString('ru-RU', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}

async function confirmLockedMove(): Promise<void> {
    lockedConfirmOpen.value = false;
    const pending = pendingDrop.value;
    pendingDrop.value = null;
    if (!pending) {
        return;
    }
    await performMove(pending.cardId, pending.targetStageId);
}

useFunnelBoardKeyboard({
    visibleStages,
    focusStageIdx,
    focusCardIdx,
    enabled: keyboardEnabled,
    onOpenCard: (card) => openChat(card as BoardCard),
    onToggleSelect: toggleCardSelection,
    onCloseOverlays: () => {
        closeCardActions();
        closeHistoryPanel();
        clearSelection();
    },
    focusCardElement: (stageIdx, cardIdx) => {
        focusCardElement(stageIdx, cardIdx);
    },
});

function stageColumnClass(stage: BoardStage): Record<string, boolean> {
    return {
        'funnel-column--done': stage.stage_tone === 'done',
        'funnel-column--lost': stage.stage_tone === 'lost',
        'funnel-column--neutral': stage.stage_tone === 'neutral' || Boolean(stage.is_inbox),
        'funnel-column--inbox': Boolean(stage.is_inbox),
        'funnel-column--collapsed': stage.cards.length === 0 && !(stage.is_inbox ?? false),
        'funnel-column--wip-full': isStageWipFull(stage),
    };
}
</script>

<template>
    <Head :title="t('nav.funnels')" />
    <AuthenticatedLayout>
        <div v-if="!funnelsModuleEnabled" class="flex h-full items-center justify-center p-8">
            <div class="ui-empty-state ui-empty-state--dashed max-w-md text-center">
                <p class="text-sm font-medium text-[var(--wa-text)] m-0">{{ t('funnels.moduleDisabled') }}</p>
                <p class="text-xs text-[var(--wa-text-secondary)] mt-2 mb-0">
                    {{ t('funnels.moduleDisabledHint') }}
                </p>
            </div>
        </div>

        <div v-else class="funnel-board flex h-full min-h-0 flex-col">
            <header class="funnel-board__header shrink-0 border-b px-5 py-4" :style="{ borderColor: 'var(--wa-border)' }">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h1 class="m-0 text-lg font-semibold text-[var(--wa-text)]">{{ t('nav.funnels') }}</h1>
                        <p class="mt-1 mb-0 text-xs text-[var(--wa-text-secondary)]">
                            {{ t('funnels.boardHint') }}
                            <span class="opacity-80">{{ t('funnels.keyboardHint') }}</span>
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Link
                            v-if="analyticsEnabled && route().has('analytics.dialogs')"
                            :href="route('analytics.dialogs')"
                            class="funnel-board__link-btn"
                        >
                            {{ t('funnels.analytics') }}
                        </Link>

                        <div
                            v-if="otherViewers.length"
                            class="funnel-board__presence"
                            :title="t('funnels.boardViewersTitle', { names: otherViewers.map((v) => v.name).join(', ') })"
                        >
                            <span class="funnel-board__presence-label">{{ t('funnels.onBoard') }}</span>
                            <div class="funnel-board__presence-avatars">
                                <Avatar
                                    v-for="viewer in otherViewers.slice(0, 4)"
                                    :key="viewer.id"
                                    :name="viewer.name"
                                    :size="24"
                                    variant="neutral"
                                    fallback-initials
                                    class="funnel-board__presence-avatar"
                                />
                                <span v-if="otherViewers.length > 4" class="funnel-board__presence-more">
                                    +{{ otherViewers.length - 4 }}
                                </span>
                            </div>
                        </div>

                        <UiPillNav>
                            <button
                                type="button"
                                class="ui-pill-nav__item"
                                :class="{ 'is-active': scope === 'all' }"
                                @click="selectScope('all')"
                            >
                                {{ canFilterAll ? t('funnels.filterAll') : t('funnels.filterAvailable') }}
                            </button>
                            <button
                                type="button"
                                class="ui-pill-nav__item"
                                :class="{ 'is-active': scope === 'mine' }"
                                @click="selectScope('mine')"
                            >
                                {{ t('funnels.filterMine') }}
                            </button>
                            <button
                                type="button"
                                class="ui-pill-nav__item"
                                :class="{ 'is-active': scope === 'department' }"
                                @click="selectScope('department')"
                            >
                                {{ t('funnels.filterMyDept') }}
                            </button>
                        </UiPillNav>

                        <label class="funnel-board__search">
                            <svg class="h-4 w-4 shrink-0 opacity-50" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <input
                                v-model="searchQuery"
                                type="search"
                                :placeholder="t('funnels.searchPlaceholder')"
                                class="funnel-board__search-input"
                            />
                        </label>
                    </div>
                </div>

                <div v-if="canUseAdvancedFilters" class="mt-3 flex flex-wrap items-center gap-2">
                    <select
                        v-if="filterAssignees.length"
                        v-model="assigneeFilter"
                        class="funnel-board__select"
                        @change="onAdvancedFilterChange"
                    >
                        <option value="">{{ t('funnels.allAssignees') }}</option>
                        <option v-for="u in filterAssignees" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
                    </select>
                    <select
                        v-if="filterDepartments.length"
                        v-model="departmentFilter"
                        class="funnel-board__select"
                        @change="onAdvancedFilterChange"
                    >
                        <option value="">{{ t('funnels.allDepartments') }}</option>
                        <option v-for="d in filterDepartments" :key="d.id" :value="String(d.id)">{{ d.name }}</option>
                    </select>
                    <select
                        v-if="filterWhatsappSessions.length"
                        v-model="sessionFilter"
                        class="funnel-board__select"
                        @change="onAdvancedFilterChange"
                    >
                        <option value="">{{ t('funnels.allWhatsapp') }}</option>
                        <option v-for="s in filterWhatsappSessions" :key="s.id" :value="String(s.id)">{{ s.label }}</option>
                    </select>
                </div>

                <div v-if="funnels.length" class="mt-3 flex flex-wrap items-center gap-3">
                    <label class="funnel-board__toggle">
                        <UiCheckbox v-model="hideEmptyColumns" size="sm" />
                        <span>{{ t('funnels.hideEmpty') }}</span>
                    </label>
                    <label class="funnel-board__toggle">
                        <UiCheckbox v-model="hideDoneColumns" size="sm" />
                        <span>{{ t('funnels.hideClosed') }}</span>
                    </label>
                    <label class="funnel-board__toggle">
                        <UiCheckbox v-model="selectionMode" size="sm" />
                        <span>{{ t('funnels.selectionMode') }}</span>
                    </label>
                    <span v-if="selectedCount > 0" class="text-xs text-[var(--wa-text-secondary)]">
                        {{ t('funnels.selectedHint', { count: selectedCount }) }}
                    </span>
                </div>

                <div v-if="funnels.length" class="mt-3 flex flex-wrap gap-2">
                    <button
                        v-for="f in funnels"
                        :key="f.id"
                        type="button"
                        class="funnel-board__funnel-chip"
                        :class="{ 'funnel-board__funnel-chip--active': selectedFunnelId === f.id }"
                        :style="{
                            '--funnel-chip-color': f.color,
                        }"
                        @click="selectFunnel(f.id)"
                    >
                        <span class="h-2 w-2 rounded-full shrink-0" :style="{ background: f.color }"></span>
                        <span class="truncate">{{ f.name }}</span>
                    </button>
                </div>
            </header>

            <div v-if="funnels.length === 0" class="flex flex-1 items-center justify-center p-8">
                <div class="ui-empty-state ui-empty-state--dashed max-w-md text-center">
                    <p class="text-sm font-medium text-[var(--wa-text)] m-0">{{ t('funnels.noActiveFunnels') }}</p>
                    <p class="text-xs text-[var(--wa-text-secondary)] mt-2 mb-4">
                        {{ t('funnels.noActiveHint') }}
                    </p>
                    <Link
                        v-if="route().has('settings.funnels')"
                        :href="route('settings.funnels')"
                        class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-white"
                        :style="{ background: 'var(--wa-accent)' }"
                    >
                        {{ t('funnels.funnelSettings') }}
                    </Link>
                </div>
            </div>

            <div v-else-if="loading" class="flex flex-1 gap-4 overflow-x-auto p-5">
                <div v-for="i in 4" :key="i" class="funnel-column funnel-column--skeleton">
                    <SkeletonBlock class="h-8 w-32 rounded-lg" />
                    <SkeletonBlock v-for="j in 3" :key="j" class="h-24 w-full rounded-xl" />
                </div>
            </div>

            <div
                v-else
                class="funnel-board__canvas flex-1 min-h-0 overflow-x-auto overflow-y-hidden p-5 wa-scrollbar"
                tabindex="0"
                role="application"
                :aria-label="t('funnels.boardAria')"
            >
                <div class="flex h-full min-w-max gap-4 items-stretch">
                    <section
                        v-for="(stage, stageIdx) in visibleStages"
                        :key="stage.id"
                        class="funnel-column"
                        :class="stageColumnClass(stage)"
                        :aria-label="t('funnels.columnAria', { name: stage.name })"
                    >
                        <header class="funnel-column__head">
                            <div class="flex min-w-0 items-center gap-2">
                                <span
                                    class="funnel-column__accent"
                                    :style="{ background: stage.color }"
                                ></span>
                                <FunnelStageIcon v-if="!stage.is_inbox" :type="stage.stage_type" :size="16" />
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-[var(--wa-text)]">
                                        {{ stage.name }}
                                    </div>
                                    <div class="text-[0.65rem] text-[var(--wa-text-secondary)]">
                                        {{ stage.is_inbox ? t('funnels.noInbox') : funnelStageTypeLabel(stage.stage_type) }}
                                    </div>
                                </div>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <span
                                    class="ui-tab-badge ui-tab-badge--neutral"
                                    :class="{ 'funnel-column__wip-badge--full': isStageWipFull(stage) }"
                                >
                                    <template v-if="stage.wip_limit">
                                        {{ stageCardsTotal(stage) }}/{{ stage.wip_limit }}
                                    </template>
                                    <template v-else>
                                        {{ stageCardsTotal(stage) }}
                                    </template>
                                </span>
                                <FunnelStageSparkline
                                    v-if="stage.stats?.sparkline?.some((v) => v > 0)"
                                    :values="stage.stats!.sparkline"
                                    :color="stage.color"
                                />
                            </div>
                        </header>

                        <div
                            v-if="stage.stats && !stage.is_inbox && (stage.stats.entered_7d > 0 || stage.stats.conversion_pct != null || stage.stats.avg_days != null)"
                            class="funnel-column__kpi"
                        >
                            <span v-if="stage.stats.entered_7d > 0">{{ t('funnels.statsEntered7d', { count: stage.stats.entered_7d }) }}</span>
                            <span v-if="stage.stats.conversion_pct != null">{{ stage.stats.conversion_pct }}% →</span>
                            <span v-if="stage.stats.avg_days != null">{{ t('funnels.statsAvgDays', { days: stage.stats.avg_days }) }}</span>
                        </div>

                        <div class="funnel-column__body wa-scrollbar">
                            <div
                                :ref="(el) => setColumnListRef(stage.id, el)"
                                class="funnel-column__list"
                                :data-stage-id="stage.id"
                            >
                                <article
                                    v-for="(card, cardIdx) in stage.cards"
                                    :key="card.id"
                                    :ref="(el) => setCardRef(stageIdx, cardIdx, el)"
                                    class="funnel-card"
                                    :class="{
                                        'funnel-card--moving': movingCardId === card.id,
                                        'funnel-card--selected': isCardSelected(card.id),
                                        'funnel-card--focused': isFocusedCard(stageIdx, cardIdx),
                                    }"
                                    :data-card-id="card.id"
                                    role="button"
                                    :tabindex="isFocusedCard(stageIdx, cardIdx) ? 0 : -1"
                                    :aria-label="t('funnels.contactAria', { name: card.name })"
                                    @click="onCardClick(card, $event)"
                                    @focus="focusStageIdx = stageIdx; focusCardIdx = cardIdx"
                                    @keydown.enter="openChat(card)"
                                >
                                    <label
                                        v-if="selectionMode || selectedCount > 0"
                                        class="funnel-card__select"
                                        @click.stop
                                    >
                                        <UiCheckbox
                                            :model-value="isCardSelected(card.id)"
                                            size="sm"
                                            @update:model-value="toggleCardSelection(card.id)"
                                        />
                                    </label>

                                    <div class="funnel-card__top">
                                        <Avatar
                                            :name="card.name"
                                            :size="36"
                                            variant="neutral"
                                            fallback-initials
                                            class="shrink-0"
                                        />
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start justify-between gap-2">
                                                <div class="truncate text-sm font-semibold text-[var(--wa-text)]">
                                                    {{ card.name }}
                                                </div>
                                                <div class="flex shrink-0 items-center gap-1">
                                                    <div class="funnel-card__actions">
                                                        <button
                                                            type="button"
                                                            class="funnel-card__actions-btn"
                                                            :title="t('funnels.actions')"
                                                            :aria-label="t('funnels.actions')"
                                                            @click="toggleCardActions(card.id, $event)"
                                                        >
                                                            ⋮
                                                        </button>
                                                        <div
                                                            v-if="openCardActionsId === card.id"
                                                            class="funnel-card__actions-menu"
                                                            @click.stop
                                                        >
                                                            <button type="button" @click="openChat(card); closeCardActions()">
                                                                {{ t('funnels.openChat') }}
                                                            </button>
                                                            <button type="button" @click="assignCardToMe(card, $event)">
                                                                {{ t('funnels.assignToMe') }}
                                                            </button>
                                                            <button
                                                                v-if="!stage.is_inbox"
                                                                type="button"
                                                                @click="moveCardToInbox(card, $event)"
                                                            >
                                                                {{ t('funnels.moveToInbox') }}
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        class="funnel-card__history-btn"
                                                        :title="t('funnels.funnelHistory')"
                                                        :aria-label="t('funnels.funnelHistory')"
                                                        @click="openHistoryPanel(card, $event)"
                                                    >
                                                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                        </svg>
                                                    </button>
                                                    <span
                                                        v-if="card.funnel_stage_locked"
                                                        class="funnel-card__lock"
                                                        :title="t('funnels.stagePinned')"
                                                    >🔒</span>
                                                    <span
                                                        v-if="card.unread_count > 0"
                                                        class="ui-tab-badge shrink-0"
                                                    >{{ card.unread_count > 99 ? '99+' : card.unread_count }}</span>
                                                </div>
                                            </div>
                                            <div v-if="card.phone" class="truncate text-xs text-[var(--wa-text-secondary)]">
                                                {{ formatPhone(card.phone) || card.phone }}
                                            </div>
                                        </div>
                                    </div>

                                    <p v-if="card.last_message_text" class="funnel-card__preview">
                                        {{ card.last_message_text }}
                                    </p>

                                    <p
                                        v-if="card.funnel_ai_reason"
                                        class="funnel-card__ai-hint"
                                        :title="card.funnel_ai_reason"
                                    >
                                        AI: {{ card.funnel_ai_reason }}
                                    </p>

                                    <div class="funnel-card__meta">
                                        <span class="truncate">{{ assigneeLabel(card) }}</span>
                                        <span v-if="card.last_message_at" class="shrink-0">
                                            {{ formatRelativeTime(card.last_message_at) }}
                                        </span>
                                    </div>
                                </article>
                            </div>

                            <button
                                v-if="stage.has_more"
                                type="button"
                                class="funnel-column__load-more"
                                :disabled="loadingStageId === stage.id"
                                @click="loadMoreCards(stage)"
                            >
                                {{ loadingStageId === stage.id ? t('funnels.loading') : t('funnels.showMore') }}
                            </button>

                            <div
                                v-if="stage.cards.length === 0"
                                class="funnel-column__empty"
                            >
                                {{ stage.is_inbox ? t('funnels.emptyInbox') : t('funnels.emptyStage') }}
                            </div>
                        </div>
                    </section>
                </div>
            </div>

            <div
                v-if="selectedCount > 0"
                class="funnel-board__bulk-bar"
            >
                <span class="text-sm font-medium text-[var(--wa-text)]">{{ t('funnels.bulkSelected', { count: selectedCount }) }}</span>
                <select v-model="bulkTargetStageId" class="funnel-board__select">
                    <option value="">{{ t('funnels.bulkStagePlaceholder') }}</option>
                    <option v-for="opt in bulkStageOptions" :key="opt.id" :value="String(opt.id)">
                        {{ opt.name }}
                    </option>
                </select>
                <button
                    type="button"
                    class="funnel-board__bulk-btn funnel-board__bulk-btn--primary"
                    :disabled="bulkMoving || bulkTargetStageId === ''"
                    @click="bulkMoveSelected"
                >
                    {{ bulkMoving ? t('funnels.bulkMoving') : t('funnels.bulkMove') }}
                </button>
                <button
                    type="button"
                    class="funnel-board__bulk-btn"
                    @click="clearSelection"
                >
                    {{ t('funnels.clearSelection') }}
                </button>
            </div>

            <footer
                v-if="funnels.length && board.funnel"
                class="shrink-0 border-t px-5 py-2 text-xs text-[var(--wa-text-secondary)]"
                :style="{ borderColor: 'var(--wa-border)' }"
            >
                {{ t('funnels.footerStats', { funnel: board.funnel.name, count: totalCards }) }}
            </footer>

            <DangerConfirmModal
                :open="lockedConfirmOpen"
                :title="t('funnels.stagePinned')"
                :description="t('funnels.pinStageDesc')"
                :confirm-label="t('funnels.moveConfirm')"
                confirm-variant="primary"
                @confirm="confirmLockedMove"
                @close="lockedConfirmOpen = false; pendingDrop = null"
            />

            <Transition name="funnel-history-panel">
                <aside
                    v-if="historyOpen"
                    class="funnel-history-panel"
                    role="dialog"
                    aria-labelledby="funnel-history-title"
                >
                    <header class="funnel-history-panel__head">
                        <div class="min-w-0">
                            <h2 id="funnel-history-title" class="m-0 text-sm font-semibold text-[var(--wa-text)]">
                                {{ t('funnels.historyTitle') }}
                            </h2>
                            <p v-if="historyCard" class="mt-1 mb-0 truncate text-xs text-[var(--wa-text-secondary)]">
                                {{ historyCard.name }}
                            </p>
                        </div>
                        <button
                            type="button"
                            class="funnel-history-panel__close"
                            :aria-label="t('funnels.close')"
                            @click="closeHistoryPanel"
                        >
                            ×
                        </button>
                    </header>
                    <div class="funnel-history-panel__body wa-scrollbar">
                        <div v-if="historyLoading" class="text-sm text-[var(--wa-text-secondary)]">{{ t('funnels.loading') }}</div>
                        <div v-else-if="historyError" class="text-sm text-[var(--wa-danger)]">{{ historyError }}</div>
                        <ul v-else-if="historyItems.length" class="funnel-history-panel__list">
                            <li v-for="item in historyItems" :key="item.id" class="funnel-history-panel__item">
                                <span class="font-medium text-[var(--wa-text)]">{{ formatHistorySource(item.source) }}</span>
                                <span v-if="item.reason"> — {{ item.reason }}</span>
                                <div class="mt-0.5 text-[var(--wa-text-secondary)]">{{ formatHistoryDate(item.created_at) }}</div>
                            </li>
                        </ul>
                        <div v-else class="text-sm text-[var(--wa-text-secondary)]">{{ t('funnels.historyEmpty') }}</div>
                    </div>
                </aside>
            </Transition>
            <div
                v-if="historyOpen"
                class="funnel-history-panel__backdrop"
                @click="closeHistoryPanel"
            ></div>
            <div
                v-if="openCardActionsId != null"
                class="funnel-card-actions-backdrop"
                @click="closeCardActions"
            ></div>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.funnel-board {
    background: var(--wa-page-bg);
}

.funnel-board__header {
    background: color-mix(in srgb, var(--wa-panel) 92%, var(--wa-page-bg));
}

.funnel-board__link-btn {
    display: inline-flex;
    align-items: center;
    height: 2rem;
    padding: 0 0.75rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: var(--wa-panel);
    color: var(--wa-text-secondary);
    font-size: 0.75rem;
    text-decoration: none;
    transition: background 0.15s ease, color 0.15s ease;
}

.funnel-board__link-btn:hover {
    color: var(--wa-text);
    background: var(--wa-panel-hover);
}

.funnel-board__presence {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    height: 2rem;
    padding: 0 0.65rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: var(--wa-panel);
}

.funnel-board__presence-label {
    font-size: 0.68rem;
    color: var(--wa-text-secondary);
}

.funnel-board__presence-avatars {
    display: inline-flex;
    align-items: center;
}

.funnel-board__presence-avatar {
    margin-left: -0.35rem;
    border: 2px solid var(--wa-panel);
}

.funnel-board__presence-avatar:first-child {
    margin-left: 0;
}

.funnel-board__presence-more {
    margin-left: 0.25rem;
    font-size: 0.65rem;
    color: var(--wa-text-secondary);
}

.funnel-board__select {
    min-width: 160px;
    height: 2rem;
    padding: 0 0.65rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: var(--wa-panel);
    color: var(--wa-text);
    font-size: 0.75rem;
}

.funnel-board__toggle {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    font-size: 0.75rem;
    color: var(--wa-text-secondary);
    cursor: pointer;
    user-select: none;
}

.funnel-card__lock {
    font-size: 0.72rem;
    line-height: 1;
    opacity: 0.85;
}

.funnel-column--collapsed {
    width: 220px;
    opacity: 0.82;
}

.funnel-board__search {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    min-width: 220px;
    height: 2rem;
    padding: 0 0.75rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: var(--wa-panel);
    color: var(--wa-text-secondary);
}

.funnel-board__search-input {
    width: 100%;
    border: 0;
    background: transparent;
    color: var(--wa-text);
    font-size: 0.8125rem;
    outline: none;
}

.funnel-board__funnel-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    max-width: 14rem;
    padding: 0.45rem 0.8rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: var(--wa-panel);
    color: var(--wa-text-secondary);
    font-size: 0.8125rem;
    transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}

.funnel-board__funnel-chip:hover {
    background: var(--wa-panel-hover);
    color: var(--wa-text);
}

.funnel-board__funnel-chip--active {
    color: var(--wa-text);
    border-color: color-mix(in srgb, var(--funnel-chip-color, var(--wa-accent)) 55%, var(--wa-border-strong));
    background: color-mix(in srgb, var(--funnel-chip-color, var(--wa-accent)) 12%, var(--wa-panel));
}

.funnel-board__canvas {
    background:
        radial-gradient(circle at top left, color-mix(in srgb, var(--wa-accent) 6%, transparent), transparent 42%),
        var(--wa-page-bg);
}

.funnel-column {
    display: flex;
    flex-direction: column;
    width: 300px;
    max-height: 100%;
    border-radius: 1rem;
    border: 1px solid var(--wa-border);
    background: color-mix(in srgb, var(--wa-panel) 88%, var(--wa-page-bg));
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
}

.funnel-column--drop {
    border-color: color-mix(in srgb, var(--wa-accent) 55%, var(--wa-border-strong));
    box-shadow:
        0 0 0 2px color-mix(in srgb, var(--wa-accent) 18%, transparent),
        0 16px 34px rgba(0, 0, 0, 0.12);
    transform: translateY(-2px);
}

.funnel-column--inbox {
    border-style: dashed;
    background: color-mix(in srgb, var(--wa-text-secondary) 5%, var(--wa-panel));
}

.funnel-column--wip-full {
    border-color: color-mix(in srgb, #f59e0b 55%, var(--wa-border-strong));
    box-shadow: 0 0 0 1px color-mix(in srgb, #f59e0b 25%, transparent);
}

.funnel-column__wip-badge--full {
    background: color-mix(in srgb, #f59e0b 18%, var(--wa-panel));
    color: #b45309;
}

.funnel-card--focused {
    outline: 2px solid color-mix(in srgb, var(--wa-accent) 55%, transparent);
    outline-offset: 2px;
}

.funnel-board__canvas:focus {
    outline: none;
}

.funnel-column__kpi {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 0.5rem;
    padding: 0 0.9rem 0.55rem;
    font-size: 0.62rem;
    color: var(--wa-text-secondary);
}

.funnel-column__load-more {
    width: 100%;
    margin-top: 0.5rem;
    padding: 0.45rem 0.65rem;
    border-radius: 0.65rem;
    border: 1px dashed var(--wa-border-strong);
    background: transparent;
    color: var(--wa-text-secondary);
    font-size: 0.72rem;
    cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease;
}

.funnel-column__load-more:hover:not(:disabled) {
    background: var(--wa-panel-hover);
    color: var(--wa-text);
}

.funnel-column__load-more:disabled {
    opacity: 0.65;
    cursor: wait;
}

.funnel-board__bulk-bar {
    position: sticky;
    bottom: 0;
    z-index: 20;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.65rem;
    padding: 0.75rem 1.25rem;
    border-top: 1px solid var(--wa-border);
    background: color-mix(in srgb, var(--wa-panel) 95%, var(--wa-accent));
    box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.08);
}

.funnel-board__bulk-btn {
    height: 2rem;
    padding: 0 0.85rem;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: var(--wa-panel);
    color: var(--wa-text);
    font-size: 0.75rem;
    cursor: pointer;
}

.funnel-board__bulk-btn--primary {
    border-color: color-mix(in srgb, var(--wa-accent) 50%, var(--wa-border-strong));
    background: var(--wa-accent);
    color: #fff;
}

.funnel-board__bulk-btn--primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.funnel-card--selected {
    border-color: color-mix(in srgb, var(--wa-accent) 65%, var(--wa-border));
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--wa-accent) 20%, transparent);
}

.funnel-card--ghost {
    opacity: 0.35;
    background: color-mix(in srgb, var(--wa-accent) 12%, var(--wa-panel));
}

.funnel-card--chosen {
    cursor: grabbing;
}

.funnel-card__select {
    display: flex;
    align-items: center;
    margin-bottom: 0.45rem;
    cursor: pointer;
}

.funnel-card__history-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.35rem;
    height: 1.35rem;
    border: 0;
    border-radius: 999px;
    background: transparent;
    color: var(--wa-text-secondary);
    cursor: pointer;
}

.funnel-card__history-btn:hover {
    background: var(--wa-panel-hover);
    color: var(--wa-text);
}

.funnel-history-panel {
    position: fixed;
    top: 0;
    right: 0;
    z-index: 60;
    display: flex;
    flex-direction: column;
    width: min(360px, 92vw);
    height: 100%;
    border-left: 1px solid var(--wa-border);
    background: var(--wa-panel);
    box-shadow: -12px 0 40px rgba(0, 0, 0, 0.15);
}

.funnel-history-panel__backdrop {
    position: fixed;
    inset: 0;
    z-index: 55;
    background: rgba(0, 0, 0, 0.28);
}

.funnel-history-panel__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1rem 1rem 0.85rem;
    border-bottom: 1px solid var(--wa-border);
}

.funnel-history-panel__close {
    width: 2rem;
    height: 2rem;
    border: 0;
    border-radius: 999px;
    background: transparent;
    color: var(--wa-text-secondary);
    font-size: 1.25rem;
    line-height: 1;
    cursor: pointer;
}

.funnel-history-panel__body {
    flex: 1;
    overflow-y: auto;
    padding: 1rem;
}

.funnel-history-panel__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.55rem;
}

.funnel-history-panel__item {
    padding: 0.65rem 0.75rem;
    border: 1px solid var(--wa-border);
    border-radius: 0.75rem;
    font-size: 0.75rem;
}

.funnel-history-panel-enter-active,
.funnel-history-panel-leave-active {
    transition: transform 0.22s ease, opacity 0.22s ease;
}

.funnel-history-panel-enter-from,
.funnel-history-panel-leave-to {
    transform: translateX(100%);
    opacity: 0;
}

.funnel-column--done {
    background: color-mix(in srgb, #22c55e 8%, var(--wa-panel));
}

.funnel-column--lost {
    background: color-mix(in srgb, #ef4444 8%, var(--wa-panel));
}

.funnel-column--neutral {
    background: color-mix(in srgb, var(--wa-text-secondary) 6%, var(--wa-panel));
}

.funnel-column--skeleton {
    gap: 0.75rem;
    padding: 0.85rem;
}

.funnel-column__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.85rem 0.9rem 0.7rem;
    border-bottom: 1px solid var(--wa-border);
}

.funnel-column__accent {
    width: 0.28rem;
    height: 2rem;
    border-radius: 999px;
    flex-shrink: 0;
}

.funnel-column__body {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 0.75rem;
}

.funnel-column__list {
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
    min-height: 120px;
}

.funnel-column__empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 120px;
    padding: 1rem;
    border: 1px dashed var(--wa-border-strong);
    border-radius: 0.85rem;
    color: var(--wa-text-secondary);
    font-size: 0.75rem;
    text-align: center;
}

.funnel-card {
    display: block;
    padding: 0.75rem;
    border-radius: 0.95rem;
    border: 1px solid var(--wa-border);
    background: var(--wa-panel-header);
    color: inherit;
    text-decoration: none;
    cursor: grab;
    transition:
        transform 0.18s ease,
        box-shadow 0.18s ease,
        border-color 0.18s ease,
        opacity 0.18s ease;
}

.funnel-card:hover {
    border-color: color-mix(in srgb, var(--wa-accent) 40%, var(--wa-border));
    box-shadow: 0 8px 22px rgba(0, 0, 0, 0.12);
    transform: translateY(-1px);
}

.funnel-card--dragging {
    opacity: 0.45;
    transform: scale(0.98) rotate(1deg);
    cursor: grabbing;
}

.funnel-card--moving {
    pointer-events: none;
    opacity: 0.72;
}

.funnel-card__top {
    display: flex;
    gap: 0.65rem;
    align-items: flex-start;
}

.funnel-card__preview {
    margin: 0.55rem 0 0;
    font-size: 0.75rem;
    line-height: 1.35;
    color: var(--wa-text-secondary);
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.funnel-card__ai-hint {
    margin: 0.45rem 0 0;
    font-size: 0.68rem;
    line-height: 1.35;
    color: color-mix(in srgb, var(--wa-accent) 70%, var(--wa-text-secondary));
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.funnel-card__actions {
    position: relative;
}

.funnel-card__actions-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.35rem;
    height: 1.35rem;
    border: 0;
    border-radius: 999px;
    background: transparent;
    color: var(--wa-text-secondary);
    font-size: 0.85rem;
    line-height: 1;
    cursor: pointer;
}

.funnel-card__actions-btn:hover {
    background: var(--wa-panel-hover);
    color: var(--wa-text);
}

.funnel-card__actions-menu {
    position: absolute;
    top: calc(100% + 0.25rem);
    right: 0;
    z-index: 30;
    min-width: 150px;
    padding: 0.35rem;
    border-radius: 0.75rem;
    border: 1px solid var(--wa-border);
    background: var(--wa-panel);
    box-shadow: 0 12px 28px rgba(0, 0, 0, 0.14);
}

.funnel-card__actions-menu button {
    display: block;
    width: 100%;
    padding: 0.45rem 0.55rem;
    border: 0;
    border-radius: 0.45rem;
    background: transparent;
    color: var(--wa-text);
    font-size: 0.72rem;
    text-align: left;
    cursor: pointer;
}

.funnel-card__actions-menu button:hover {
    background: var(--wa-panel-hover);
}

.funnel-card-actions-backdrop {
    position: fixed;
    inset: 0;
    z-index: 25;
}

.funnel-card__meta {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    margin-top: 0.65rem;
    font-size: 0.68rem;
    color: var(--wa-text-secondary);
}

.funnel-card-move {
    transition: transform 0.24s cubic-bezier(0.22, 1, 0.36, 1);
}

.funnel-card-enter-active,
.funnel-card-leave-active {
    transition: all 0.22s ease;
}

.funnel-card-enter-from,
.funnel-card-leave-to {
    opacity: 0;
    transform: scale(0.96) translateY(6px);
}

.funnel-card-leave-active {
    position: absolute;
    width: calc(100% - 1.5rem);
}
</style>
