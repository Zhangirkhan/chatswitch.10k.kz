<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { useI18n } from '@/composables/useI18n';
import FunnelStageIcon from '@/Components/Funnel/FunnelStageIcon.vue';
import FunnelAiWizard, { type AiFunnelSuggestion } from '@/Pages/Settings/Partials/FunnelAiWizard.vue';
import { FUNNEL_STAGE_TYPES, guessStageTypeFromName, type FunnelStageTypeValue } from '@/utils/funnelStageTypes';
import {
    stageHintToneStyle,
    stageInlineHints,
    stageRuleIssues as collectStageRuleIssues,
    type StageRuleIssueId,
    type StageHint,
} from '@/utils/funnelStageHints';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import { useToastStore } from '@/stores/toast';

interface FunnelStage {
    id: number;
    funnel_id: number;
    name: string;
    color: string;
    stage_type: string;
    position: number;
    is_active: boolean;
    wip_limit?: number | null;
    ai_rule?: FunnelStageAiRule | null;
}

interface FunnelAiScenario {
    id?: number;
    enabled: boolean;
    customer_identity: string;
    booking_horizon_days: number;
    fallback_manager_user_id: number | null;
    fallback_department_id: number | null;
    manager_confirmation_required: boolean;
}

interface FunnelStageAiRule {
    id?: number;
    goal: string | null;
    required_questions: string[] | null;
    transition_conditions: string | null;
    allowed_actions: string[] | null;
    assignee_user_ids: number[] | null;
    assignee_department_id: number | null;
    require_manager_confirmation: boolean;
    follow_up_enabled: boolean;
    follow_up_delay_hours: number;
    follow_up_message: string | null;
    follow_up_mode: 'template' | 'ab' | 'ai';
    follow_up_message_b: string | null;
    follow_up_ab_ratio: number;
    follow_up_cooldown_hours: number;
    follow_up_max_count: number;
    follow_up_strategy?: 'off' | 'manager_proposals' | 'auto_cron';
    follow_up_silence_after?: 'inbound' | 'outbound';
    follow_up_allowed_promos?: Array<{
        id: string;
        label: string;
        percent: number | null;
        valid_until: string | null;
        note: string | null;
    }>;
    follow_up_promotion_ids?: number[];
    follow_up_use_promotions?: boolean;
}

interface Funnel {
    id: number;
    name: string;
    description: string | null;
    color: string;
    is_active: boolean;
    position: number;
    stages: FunnelStage[];
    stages_count?: number;
    ai_scenario?: FunnelAiScenario | null;
}

interface FunnelTemplate {
    key: string;
    industry: string;
    name: string;
    description: string;
    color: string;
    stages: Array<{ name: string; color: string }>;
}

type PromotionOption = {
    id: number;
    name: string;
    discount_type: 'percent' | 'fixed' | 'bogo' | 'gift' | 'bundle' | 'free_delivery' | 'custom';
    percent: number | null;
    fixed_amount: string | null;
    buy_quantity: number | null;
    get_quantity: number | null;
    benefit_summary: string | null;
    valid_from: string | null;
    valid_until: string | null;
    conditions: string | null;
    is_active: boolean;
    is_currently_valid: boolean;
};

const props = defineProps<{
    funnels: Funnel[];
    paymentStagesRequired?: boolean;
    funnelTemplates?: FunnelTemplate[];
    promotions?: PromotionOption[];
    aiScenarioUsers?: Array<{ id: number; name: string; department_id: number | null }>;
    aiScenarioDepartments?: Array<{ id: number; name: string }>;
}>();

const { show: showToast } = useToastStore();
const { t } = useI18n();

const localFunnels = ref<Funnel[]>([...props.funnels]);
const aiWizardRef = ref<InstanceType<typeof FunnelAiWizard> | null>(null);
const creatingTemplateKey = ref<string | null>(null);

watch(
    () => props.funnels,
    (next) => {
        localFunnels.value = [...next];
    },
    { deep: true },
);

/** Палитра для цветовых пресетов и в воронке, и в этапе. */
const palette = [
    '#01b964', '#34d399', '#22d3ee', '#3b82f6', '#6366f1',
    '#8b5cf6', '#a855f7', '#ec4899', '#ef4444', '#f97316',
    '#f59e0b', '#facc15', '#84cc16', '#9ca3af', '#64748b',
];

const aiActionOptions = computed(() => [
    { id: 'reply_customer', label: t('settings.funnels.stageActions.replyCustomer') },
    { id: 'move_funnel_stage', label: t('settings.funnels.stageActions.moveFunnelStage') },
    { id: 'create_appointment', label: t('settings.funnels.stageActions.createAppointment') },
    { id: 'assign_employee', label: t('settings.funnels.stageActions.assignEmployee') },
    { id: 'notify_manager', label: t('settings.funnels.stageActions.notifyManager') },
    { id: 'create_task', label: t('settings.funnels.stageActions.createTask') },
]);

/* ============================================================
 * Modal: Funnel (create/edit)
 * ============================================================ */
type FunnelMode = 'manual' | 'ai';

interface AiStageDraft {
    name: string;
    color: string;
}

const funnelModalOpen = ref(false);
const editingFunnelId = ref<number | null>(null);
const funnelMode = ref<FunnelMode>('manual');
const funnelForm = ref({
    name: '',
    description: '',
    color: '#01b964',
    is_active: true,
});
const savingFunnel = ref(false);
const funnelErrors = ref<Record<string, string>>({});

const aiStages = ref<AiStageDraft[]>([]);
const aiSuggested = ref(false);
const aiError = ref<string | null>(null);

function resetAiState() {
    aiStages.value = [];
    aiSuggested.value = false;
    aiError.value = null;
    aiWizardRef.value?.resetWizard();
}

function openCreateFunnel(mode: FunnelMode = 'manual') {
    editingFunnelId.value = null;
    funnelMode.value = mode;
    funnelForm.value = {
        name: '',
        description: '',
        color: '#01b964',
        is_active: true,
    };
    funnelErrors.value = {};
    resetAiState();
    funnelModalOpen.value = true;
}

function openEditFunnel(funnel: Funnel) {
    editingFunnelId.value = funnel.id;
    funnelMode.value = 'manual';
    funnelForm.value = {
        name: funnel.name,
        description: funnel.description ?? '',
        color: funnel.color || '#01b964',
        is_active: funnel.is_active !== false,
    };
    funnelErrors.value = {};
    resetAiState();
    funnelModalOpen.value = true;
}

function closeFunnelModal() {
    funnelModalOpen.value = false;
    funnelErrors.value = {};
    resetAiState();
}

function switchFunnelMode(mode: FunnelMode) {
    if (funnelMode.value === mode) return;
    funnelMode.value = mode;
    funnelErrors.value = {};
    aiError.value = null;
    if (mode === 'ai') {
        aiSuggested.value = false;
        aiStages.value = [];
        aiWizardRef.value?.resetWizard();
    }
}

function onWizardSelect(suggestion: AiFunnelSuggestion) {
    funnelForm.value = {
        name: String(suggestion.name ?? '').slice(0, 255) || t('settings.funnels.newFunnelDefaultName'),
        description: String(suggestion.description ?? ''),
            color: String(suggestion.color ?? '#01b964'),
        is_active: true,
    };
    aiStages.value = suggestion.stages.map((s) => ({
        name: s.name.trim(),
        color: s.color || '#9ca3af',
    }));
    aiSuggested.value = true;
    aiError.value = null;
}

function addAiStage() {
    aiStages.value.push({ name: '', color: '#9ca3af' });
}

function removeAiStage(index: number) {
    aiStages.value.splice(index, 1);
}

function moveAiStage(index: number, direction: -1 | 1) {
    const swap = index + direction;
    if (swap < 0 || swap >= aiStages.value.length) return;
    const stages = aiStages.value;
    [stages[index], stages[swap]] = [stages[swap], stages[index]];
}

async function saveFunnel() {
    if (!funnelForm.value.name.trim()) {
        funnelErrors.value = { name: t('settings.funnels.errorFunnelNameRequired') };
        return;
    }

    const isAiCreate = funnelMode.value === 'ai' && editingFunnelId.value === null;
    if (isAiCreate && !aiSuggested.value) {
        aiError.value = t('settings.funnels.errorAiFirst');
        return;
    }

    let stagesPayload: { name: string; color: string; is_active: boolean }[] = [];
    if (isAiCreate) {
        stagesPayload = aiStages.value
            .map((s) => ({
                name: s.name.trim(),
                color: s.color || '#9ca3af',
                is_active: true,
            }))
            .filter((s) => s.name !== '');

        if (stagesPayload.length === 0) {
            aiError.value = t('settings.funnels.errorAddStageFirst');
            return;
        }
    }

    if (savingFunnel.value) return;
    savingFunnel.value = true;
    funnelErrors.value = {};

    try {
        const payload: Record<string, unknown> = {
            name: funnelForm.value.name.trim(),
            description: funnelForm.value.description.trim() || null,
            color: funnelForm.value.color,
            is_active: funnelForm.value.is_active,
        };
        if (isAiCreate) {
            payload.stages = stagesPayload;
        }

        if (editingFunnelId.value === null) {
            await axios.post(route('settings.funnels.store'), payload);
            showToast({
                message: isAiCreate
                    ? t('settings.funnels.toastFunnelCreatedWithStages', { count: stagesPayload.length })
                    : t('settings.funnels.toastFunnelCreated'),
                duration: 3000,
            });
        } else {
            await axios.put(route('settings.funnels.update', editingFunnelId.value), payload);
            showToast({ message: t('settings.funnels.toastFunnelUpdated'), duration: 3000 });
        }
        funnelModalOpen.value = false;
        resetAiState();
        await router.reload({ only: ['funnels'] });
    } catch (err: unknown) {
        const e = err as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } };
        if (e.response?.status === 422 && e.response.data?.errors) {
            const flat: Record<string, string> = {};
            for (const [k, msgs] of Object.entries(e.response.data.errors)) {
                flat[k] = (msgs as string[]).join('\n');
            }
            funnelErrors.value = flat;
        } else {
            showToast({ message: e.response?.data?.message || t('settings.funnels.errorSave'), duration: 6000 });
        }
    } finally {
        savingFunnel.value = false;
    }
}

type BulkDeleteKind = 'funnel' | 'stage';

const bulkDeleteOpen = ref(false);
const bulkDeleteKind = ref<BulkDeleteKind | null>(null);
const bulkDeleteFunnel = ref<Funnel | null>(null);
const bulkDeleteStage = ref<FunnelStage | null>(null);
const bulkDeleteBusy = ref(false);

const bulkDeleteTitle = computed(() =>
    bulkDeleteKind.value === 'stage' ? t('settings.funnels.deleteStageTitle') : t('settings.funnels.deleteFunnelTitle'),
);

const bulkDeleteDescription = computed(() => {
    if (bulkDeleteKind.value === 'funnel' && bulkDeleteFunnel.value) {
        const f = bulkDeleteFunnel.value;
        const stagesCount = f.stages?.length ?? 0;
        const extra =
            stagesCount > 0
                ? t('settings.funnels.deleteFunnelStagesExtra', { count: stagesCount })
                : '';
        return t('settings.funnels.deleteFunnelDescription', { name: f.name, extra });
    }
    if (bulkDeleteKind.value === 'stage' && bulkDeleteStage.value) {
        return t('settings.funnels.deleteStageDescription', { name: bulkDeleteStage.value.name });
    }
    return '';
});

function closeBulkDelete(): void {
    if (bulkDeleteBusy.value) return;
    bulkDeleteOpen.value = false;
    bulkDeleteKind.value = null;
    bulkDeleteFunnel.value = null;
    bulkDeleteStage.value = null;
}

function requestDeleteFunnel(funnel: Funnel): void {
    bulkDeleteKind.value = 'funnel';
    bulkDeleteFunnel.value = funnel;
    bulkDeleteStage.value = null;
    bulkDeleteOpen.value = true;
}

function requestDeleteStage(funnel: Funnel, stage: FunnelStage): void {
    bulkDeleteKind.value = 'stage';
    bulkDeleteFunnel.value = funnel;
    bulkDeleteStage.value = stage;
    bulkDeleteOpen.value = true;
}

async function confirmBulkDelete(): Promise<void> {
    const kind = bulkDeleteKind.value;
    const funnel = bulkDeleteFunnel.value;
    const stage = bulkDeleteStage.value;
    if (!kind || !funnel) return;

    bulkDeleteBusy.value = true;
    try {
        if (kind === 'funnel') {
            await axios.delete(route('settings.funnels.destroy', funnel.id));
            showToast({ message: t('settings.funnels.toastFunnelDeleted'), duration: 3000 });
        } else if (stage) {
            await axios.delete(route('settings.funnels.stages.destroy', [funnel.id, stage.id]));
            showToast({ message: t('settings.funnels.toastStageDeleted'), duration: 3000 });
        }
        bulkDeleteOpen.value = false;
        bulkDeleteKind.value = null;
        bulkDeleteFunnel.value = null;
        bulkDeleteStage.value = null;
        await router.reload({ only: ['funnels'] });
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } };
        showToast({ message: e.response?.data?.message || t('settings.funnels.errorDelete'), duration: 6000 });
    } finally {
        bulkDeleteBusy.value = false;
    }
}

/* ============================================================
 * Modal: Stage (create/edit)
 * ============================================================ */
const stageModalOpen = ref(false);
const stageContext = ref<{ funnel: Funnel | null; editingStageId: number | null }>({
    funnel: null,
    editingStageId: null,
});
const stageForm = ref({
    name: '',
    color: '#9ca3af',
    stage_type: 'other' as FunnelStageTypeValue,
    is_active: true,
    wip_limit: '' as string | number,
});
const savingStage = ref(false);
const stageErrors = ref<Record<string, string>>({});

function openCreateStage(funnel: Funnel) {
    stageContext.value = { funnel, editingStageId: null };
    stageForm.value = { name: '', color: '#9ca3af', stage_type: 'other', is_active: true, wip_limit: '' };
    stageErrors.value = {};
    stageModalOpen.value = true;
}

function openEditStage(funnel: Funnel, stage: FunnelStage) {
    stageContext.value = { funnel, editingStageId: stage.id };
    stageForm.value = {
        name: stage.name,
        color: stage.color || '#9ca3af',
        stage_type: (stage.stage_type || 'other') as FunnelStageTypeValue,
        is_active: stage.is_active !== false,
        wip_limit: stage.wip_limit ?? '',
    };
    stageErrors.value = {};
    stageModalOpen.value = true;
}

function closeStageModal() {
    stageModalOpen.value = false;
    stageErrors.value = {};
}

async function saveStage() {
    const funnel = stageContext.value.funnel;
    if (!funnel) return;
    if (!stageForm.value.name.trim()) {
        stageErrors.value = { name: t('settings.funnels.errorStageNameRequired') };
        return;
    }
    if (savingStage.value) return;
    savingStage.value = true;
    stageErrors.value = {};

    try {
        const payload = {
            name: stageForm.value.name.trim(),
            color: stageForm.value.color,
            stage_type: stageForm.value.stage_type,
            is_active: stageForm.value.is_active,
            wip_limit: stageForm.value.wip_limit === '' ? null : Number(stageForm.value.wip_limit),
        };

        if (stageContext.value.editingStageId === null) {
            await axios.post(route('settings.funnels.stages.store', funnel.id), payload);
            showToast({ message: t('settings.funnels.toastStageAdded'), duration: 3000 });
        } else {
            await axios.put(
                route('settings.funnels.stages.update', [funnel.id, stageContext.value.editingStageId]),
                payload,
            );
            showToast({ message: t('settings.funnels.toastStageUpdated'), duration: 3000 });
        }
        stageModalOpen.value = false;
        await router.reload({ only: ['funnels'] });
    } catch (err: unknown) {
        const e = err as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } };
        if (e.response?.status === 422 && e.response.data?.errors) {
            const flat: Record<string, string> = {};
            for (const [k, msgs] of Object.entries(e.response.data.errors)) {
                flat[k] = (msgs as string[]).join('\n');
            }
            stageErrors.value = flat;
        } else {
            showToast({ message: e.response?.data?.message || t('settings.funnels.errorSave'), duration: 6000 });
        }
    } finally {
        savingStage.value = false;
    }
}

/* ============================================================
 * Reorder stages (DnD + move up/down)
 * ============================================================ */
const draggingStage = ref<{ funnelId: number; stageId: number } | null>(null);
const dragOverStageId = ref<number | null>(null);
const reorderingStages = ref(false);

function sortedStages(funnel: Funnel): FunnelStage[] {
    return [...funnel.stages].sort((a, b) => a.position - b.position);
}

async function persistStageOrder(funnel: Funnel, orderedIds: number[]): Promise<void> {
    if (reorderingStages.value) {
        return;
    }
    reorderingStages.value = true;
    try {
        await axios.post(route('settings.funnels.stages.reorder', funnel.id), {
            stage_ids: orderedIds,
        });
        await router.reload({ only: ['funnels'] });
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } };
        showToast({ message: e.response?.data?.message || t('settings.funnels.errorReorderStage'), duration: 6000 });
    } finally {
        reorderingStages.value = false;
        draggingStage.value = null;
        dragOverStageId.value = null;
    }
}

async function moveStage(funnel: Funnel, stage: FunnelStage, direction: -1 | 1) {
    const stages = sortedStages(funnel);
    const idx = stages.findIndex((s) => s.id === stage.id);
    const swapIdx = idx + direction;
    if (idx === -1 || swapIdx < 0 || swapIdx >= stages.length) {
        return;
    }

    [stages[idx], stages[swapIdx]] = [stages[swapIdx], stages[idx]];
    await persistStageOrder(funnel, stages.map((s) => s.id));
}

function onStageDragStart(funnel: Funnel, stage: FunnelStage, event: DragEvent) {
    draggingStage.value = { funnelId: funnel.id, stageId: stage.id };
    event.dataTransfer?.setData('text/plain', String(stage.id));
    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
}

function onStageDragOver(stageId: number, event: DragEvent) {
    event.preventDefault();
    dragOverStageId.value = stageId;
    if (event.dataTransfer) {
        event.dataTransfer.dropEffect = 'move';
    }
}

function onStageDragEnd() {
    draggingStage.value = null;
    dragOverStageId.value = null;
}

async function onStageDrop(funnel: Funnel, targetStageId: number, event: DragEvent) {
    event.preventDefault();
    const from = draggingStage.value;
    dragOverStageId.value = null;
    if (!from || from.funnelId !== funnel.id || from.stageId === targetStageId) {
        draggingStage.value = null;
        return;
    }

    const stages = sortedStages(funnel);
    const fromIdx = stages.findIndex((s) => s.id === from.stageId);
    const toIdx = stages.findIndex((s) => s.id === targetStageId);
    if (fromIdx === -1 || toIdx === -1) {
        draggingStage.value = null;
        return;
    }

    const [item] = stages.splice(fromIdx, 1);
    stages.splice(toIdx, 0, item);
    await persistStageOrder(funnel, stages.map((s) => s.id));
}

function scenarioDraft(funnel: Funnel): FunnelAiScenario {
    return {
        enabled: funnel.ai_scenario?.enabled ?? false,
        customer_identity: funnel.ai_scenario?.customer_identity ?? 'company',
        booking_horizon_days: funnel.ai_scenario?.booking_horizon_days ?? 30,
        fallback_manager_user_id: funnel.ai_scenario?.fallback_manager_user_id ?? null,
        fallback_department_id: funnel.ai_scenario?.fallback_department_id ?? null,
        manager_confirmation_required: funnel.ai_scenario?.manager_confirmation_required ?? false,
    };
}

async function saveAiScenario(funnel: Funnel, patch: Partial<FunnelAiScenario>): Promise<void> {
    const payload = { ...scenarioDraft(funnel), ...patch };

    if (patch.enabled === true && !payload.fallback_manager_user_id && !payload.fallback_department_id) {
        showToast({
            message: t('settings.funnels.aiScenario.errorFallbackRequired'),
            duration: 6000,
        });

        return;
    }

    const issues = funnelIssueCount(funnel);
    if (patch.enabled === true && issues > 0) {
        showToast({
            message: t('settings.funnels.aiScenario.errorFixRulesBeforeEnable', {
                count: issues,
                summary: funnelIssueSummary(funnel),
            }),
            duration: 8000,
        });

        return;
    }

    try {
        const { data } = await axios.put(route('settings.funnels.ai-scenario.update', funnel.id), payload);
        funnel.ai_scenario = data.scenario as FunnelAiScenario;
        showToast({
            message: patch.enabled
                ? t('settings.funnels.aiScenario.toastEnabled')
                : t('settings.funnels.aiScenario.toastSaved'),
            duration: 2500,
        });
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string; readiness?: { label?: string } } } };
        const status = (err as { response?: { status?: number } }).response?.status;
        const message =
            status === 423
                ? t('settings.funnels.aiScenario.errorOnboarding')
                : e.response?.data?.message || t('settings.funnels.aiScenario.errorSaveScenario');
        showToast({ message, duration: 6000 });
    }
}

function stageRuleDraft(stage: FunnelStage): FunnelStageAiRule {
    return {
        goal: stage.ai_rule?.goal ?? '',
        required_questions: stage.ai_rule?.required_questions ?? [],
        transition_conditions: stage.ai_rule?.transition_conditions ?? '',
        allowed_actions: stage.ai_rule?.allowed_actions ?? aiActionOptions.value.map((a) => a.id),
        assignee_user_ids: stage.ai_rule?.assignee_user_ids ?? [],
        assignee_department_id: stage.ai_rule?.assignee_department_id ?? null,
        require_manager_confirmation: stage.ai_rule?.require_manager_confirmation ?? false,
        follow_up_enabled: stage.ai_rule?.follow_up_enabled ?? true,
        follow_up_delay_hours: stage.ai_rule?.follow_up_delay_hours ?? 24,
        follow_up_message: stage.ai_rule?.follow_up_message ?? '',
        follow_up_mode: stage.ai_rule?.follow_up_mode ?? 'ai',
        follow_up_message_b: stage.ai_rule?.follow_up_message_b ?? '',
        follow_up_ab_ratio: stage.ai_rule?.follow_up_ab_ratio ?? 50,
        follow_up_cooldown_hours: stage.ai_rule?.follow_up_cooldown_hours ?? 72,
        follow_up_max_count: stage.ai_rule?.follow_up_max_count ?? 2,
        follow_up_strategy: stage.ai_rule?.follow_up_strategy ?? 'auto_cron',
        follow_up_silence_after: stage.ai_rule?.follow_up_silence_after ?? 'outbound',
        follow_up_use_promotions: stage.ai_rule?.follow_up_use_promotions ?? true,
        follow_up_promotion_ids: stage.ai_rule?.follow_up_promotion_ids ?? [],
    };
}

function promotionLabel(promo: PromotionOption): string {
    if (promo.benefit_summary) {
        return `${promo.name} (${promo.benefit_summary})`;
    }
    if (promo.discount_type === 'percent' && promo.percent != null) {
        return `${promo.name} (−${promo.percent}%)`;
    }
    if (promo.discount_type === 'fixed' && promo.fixed_amount) {
        return `${promo.name} (−${promo.fixed_amount} ₸)`;
    }
    if (promo.discount_type === 'bogo') {
        const buy = promo.buy_quantity ?? 1;
        const get = promo.get_quantity ?? 1;
        return `${promo.name} (${buy}+${get})`;
    }
    return promo.name;
}

function toggleStagePromotion(funnel: Funnel, stage: FunnelStage, promoId: number, enabled: boolean): void {
    const current = [...(stageRuleDraft(stage).follow_up_promotion_ids ?? [])];
    const next = enabled
        ? Array.from(new Set([...current, promoId]))
        : current.filter((id) => id !== promoId);
    saveStageAiRule(funnel, stage, { follow_up_promotion_ids: next });
}

const followUpStrategyOptions = computed(() => [
    { id: 'off' as const, label: t('settings.funnels.followUp.strategyOff') },
    { id: 'manager_proposals' as const, label: t('settings.funnels.followUp.strategyManager') },
    { id: 'auto_cron' as const, label: t('settings.funnels.followUp.strategyAutoCron') },
]);

const followUpModeOptions = computed(() => [
    { id: 'template' as const, label: t('settings.funnels.followUp.modeTemplate'), hint: t('settings.funnels.followUp.modeTemplateHint') },
    { id: 'ab' as const, label: t('settings.funnels.followUp.modeAb'), hint: t('settings.funnels.followUp.modeAbHint') },
    { id: 'ai' as const, label: t('settings.funnels.followUp.modeAi'), hint: t('settings.funnels.followUp.modeAiHint') },
]);

function isLastFunnelStage(funnel: Funnel, stage: FunnelStage): boolean {
    const stages = [...(funnel.stages ?? [])].sort((a, b) => a.position - b.position);
    if (stages.length === 0) {
        return false;
    }

    return stages[stages.length - 1]?.id === stage.id;
}

async function saveStageAiRule(funnel: Funnel, stage: FunnelStage, patch: Partial<FunnelStageAiRule>): Promise<void> {
    const payload = { ...stageRuleDraft(stage), ...patch };
    try {
        const { data } = await axios.put(route('settings.funnels.stages.ai-rule.update', [funnel.id, stage.id]), payload);
        stage.ai_rule = data.rule as FunnelStageAiRule;
        showToast({ message: t('settings.funnels.toastRulesSaved'), duration: 2500 });
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } };
        showToast({ message: e.response?.data?.message || t('settings.funnels.errorRulesSave'), duration: 6000 });
    }
}

async function applySuggestedStageRule(funnel: Funnel, stage: FunnelStage, index: number, total: number): Promise<void> {
    await saveStageAiRule(funnel, stage, suggestedStageRule(funnel, stage, index, total));
}

function suggestedStageRule(funnel: Funnel, stage: FunnelStage, index: number, total: number): Partial<FunnelStageAiRule> {
    const name = stage.name.toLowerCase();
    const isFinal = total > 0 && index >= total - 1;
    const current = stageRuleDraft(stage);
    const baseActions = ['reply_customer', 'move_funnel_stage', 'notify_manager', 'create_task'];

    if (name.includes('запись') || name.includes('приём') || name.includes('замер') || name.includes('показ') || name.includes('созвон')) {
        return {
            goal: current.goal || t('settings.funnels.presets.appointment.goal'),
            required_questions: current.required_questions?.length
                ? current.required_questions
                : [
                    t('settings.funnels.presets.appointment.q1'),
                    t('settings.funnels.presets.appointment.q2'),
                    t('settings.funnels.presets.appointment.q3'),
                ],
            transition_conditions:
                current.transition_conditions || t('settings.funnels.presets.appointment.transition'),
            allowed_actions: [...baseActions, 'create_appointment', 'assign_employee'],
            assignee_department_id: current.assignee_department_id ?? scenarioDraft(funnel).fallback_department_id ?? null,
            require_manager_confirmation: current.require_manager_confirmation ?? false,
        };
    }

    if (name.includes('оплат') || name.includes('предоплат')) {
        if (props.paymentStagesRequired === false) {
            return {
                goal: current.goal || t('settings.funnels.presets.paymentBypass.goal'),
                required_questions: current.required_questions?.length ? current.required_questions : [],
                transition_conditions: current.transition_conditions || t('settings.funnels.presets.paymentBypass.transition'),
                allowed_actions: baseActions,
                assignee_department_id: current.assignee_department_id ?? scenarioDraft(funnel).fallback_department_id ?? null,
                require_manager_confirmation: false,
            };
        }

        return {
            goal: current.goal || t('settings.funnels.presets.payment.goal'),
            required_questions: current.required_questions?.length
                ? current.required_questions
                : [t('settings.funnels.presets.payment.q1'), t('settings.funnels.presets.payment.q2')],
            transition_conditions: current.transition_conditions || t('settings.funnels.presets.payment.transition'),
            allowed_actions: baseActions,
            assignee_department_id: current.assignee_department_id ?? scenarioDraft(funnel).fallback_department_id ?? null,
            require_manager_confirmation: false,
        };
    }

    if (name.includes('достав') || name.includes('монтаж') || name.includes('готов')) {
        return {
            goal: current.goal || t('settings.funnels.presets.delivery.goal'),
            required_questions: current.required_questions?.length
                ? current.required_questions
                : [
                    t('settings.funnels.presets.delivery.q1'),
                    t('settings.funnels.presets.delivery.q2'),
                    t('settings.funnels.presets.delivery.q3'),
                ],
            transition_conditions: current.transition_conditions || t('settings.funnels.presets.delivery.transition'),
            allowed_actions: baseActions,
            assignee_department_id: current.assignee_department_id ?? scenarioDraft(funnel).fallback_department_id ?? null,
            require_manager_confirmation: false,
        };
    }

    if (isFinal || name.includes('закрыто') || name.includes('выполн')) {
        return {
            goal: current.goal || t('settings.funnels.presets.final.goal'),
            required_questions: [],
            transition_conditions: current.transition_conditions || t('settings.funnels.presets.final.transition'),
            allowed_actions: ['reply_customer', 'notify_manager'],
            assignee_department_id: current.assignee_department_id ?? scenarioDraft(funnel).fallback_department_id ?? null,
            require_manager_confirmation: false,
        };
    }

    return {
        goal: current.goal || t('settings.funnels.presets.default.goal'),
        required_questions: current.required_questions?.length
            ? current.required_questions
            : [
                t('settings.funnels.presets.default.q1'),
                t('settings.funnels.presets.default.q2'),
                t('settings.funnels.presets.default.q3'),
            ],
        transition_conditions: current.transition_conditions || t('settings.funnels.presets.default.transition'),
        allowed_actions: baseActions,
        assignee_department_id: current.assignee_department_id ?? scenarioDraft(funnel).fallback_department_id ?? null,
        require_manager_confirmation: false,
    };
}

function csvToList(value: string): string[] {
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean)
        .slice(0, 20);
}

function toggleStageAction(stage: FunnelStage, action: string): string[] {
    const current = new Set(stageRuleDraft(stage).allowed_actions ?? []);
    if (current.has(action)) {
        current.delete(action);
    } else {
        current.add(action);
    }

    return [...current];
}

const totalFunnels = computed(() => localFunnels.value.length);
const funnelTemplates = computed(() => props.funnelTemplates ?? []);

function stageRuleIssueLabel(issue: StageRuleIssueId): string {
    return t(`settings.funnels.issues.${issue}`);
}

function stageRuleIssues(stage: FunnelStage, index = 0, total = 0): StageRuleIssueId[] {
    return collectStageRuleIssues(stage.ai_rule, index, total, stage.stage_type);
}

function funnelIssueSummary(funnel: Funnel): string {
    const parts: string[] = [];
    funnel.stages.forEach((stage, index) => {
        const issues = stageRuleIssues(stage, index, funnel.stages.length);
        if (issues.length > 0) {
            parts.push(`${stage.name}: ${issues.map(stageRuleIssueLabel).join(', ')}`);
        }
    });

    return parts.join('; ');
}

function stageHints(stage: FunnelStage, index: number, total: number): StageHint[] {
    return stageInlineHints(stage.ai_rule, stage.stage_type, index, total);
}

function stageRuleHealthLabel(stage: FunnelStage, index = 0, total = 0): string {
    const count = stageRuleIssues(stage, index, total).length;

    return count === 0 ? t('settings.funnels.aiHealthOk') : t('settings.funnels.aiHealthIssues', { count });
}

function stageRuleHealthColor(stage: FunnelStage, index = 0, total = 0): string {
    const count = stageRuleIssues(stage, index, total).length;
    if (count === 0) return '#16a34a';
    if (count <= 2) return '#d97706';

    return '#dc2626';
}

function funnelIssueCount(funnel: Funnel): number {
    return funnel.stages.reduce(
        (total, stage, index) => total + stageRuleIssues(stage, index, funnel.stages.length).length,
        0,
    );
}

async function createFromTemplate(template: FunnelTemplate): Promise<void> {
    if (creatingTemplateKey.value !== null) return;
    creatingTemplateKey.value = template.key;
    try {
        await axios.post(route('settings.funnels.templates.store'), {
            template_key: template.key,
        });
        showToast({ message: t('settings.funnels.toastTemplateApplied', { name: template.industry }), duration: 3000 });
        await router.reload({ only: ['funnels'] });
    } catch (e: any) {
        showToast({ message: e.response?.data?.message || t('settings.funnels.errorTemplateCreate'), duration: 6000 });
    } finally {
        creatingTemplateKey.value = null;
    }
}
</script>

<template>
    <Head :title="t('settings.funnels.title')" />
    <SettingsLayout :title="t('settings.funnels.title')" :subtitle="t('settings.funnels.subtitle')">
        <template #actions>
            <div class="flex items-center gap-2">
                <button
                    type="button"
                    class="ui-btn ui-btn--ghost"
                    @click="openCreateFunnel('ai')"
                >
                    {{ t('settings.funnels.aiBuilder') }}
                </button>
                <button
                    type="button"
                    class="ui-btn ui-btn--primary"
                    @click="openCreateFunnel('manual')"
                >
                    {{ t('settings.funnels.newFunnel') }}
                </button>
            </div>
        </template>

        <div class="funnels-page w-full px-6 py-6 space-y-5">
            <p class="text-sm text-[var(--ui-text-secondary)] max-w-3xl">
                {{ t('settings.funnels.intro') }}
            </p>

            <section
                v-if="funnelTemplates.length"
                class="ui-panel p-4"
            >
                <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <h2 class="text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.funnels.templatesTitle') }}</h2>
                        <p class="text-xs text-[var(--ui-text-secondary)]">
                            {{ t('settings.funnels.templatesDesc') }}
                        </p>
                    </div>
                    <span class="text-xs font-medium text-[var(--ui-text-secondary)]">{{ t('settings.funnels.templatesCount', { count: funnelTemplates.length }) }}</span>
                </div>

                <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    <article
                        v-for="template in funnelTemplates"
                        :key="template.key"
                        class="rounded-xl border p-3"
                        :style="{ background: 'var(--ui-surface-muted)', borderColor: 'var(--ui-border)' }"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ background: template.color }"></span>
                                    <h3 class="truncate text-sm font-semibold text-[var(--ui-text)]">{{ template.industry }}</h3>
                                </div>
                                <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-[var(--ui-text-secondary)]">{{ template.description }}</p>
                            </div>
                            <button
                                type="button"
                                class="ui-btn ui-btn--primary ui-btn--sm shrink-0"
                                :disabled="creatingTemplateKey !== null"
                                @click="createFromTemplate(template)"
                            >
                                {{ creatingTemplateKey === template.key ? t('settings.funnels.creating') : t('settings.funnels.create') }}
                            </button>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <span
                                v-for="stage in template.stages.slice(0, 4)"
                                :key="`${template.key}-${stage.name}`"
                                class="rounded-full px-2 py-0.5 text-[11px]"
                                :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text-secondary)' }"
                            >
                                {{ stage.name }}
                            </span>
                            <span v-if="template.stages.length > 4" class="rounded-full px-2 py-0.5 text-[11px]" :style="{ background: 'var(--ui-surface)', color: 'var(--ui-text-secondary)' }">
                                +{{ template.stages.length - 4 }}
                            </span>
                        </div>
                    </article>
                </div>
            </section>

            <div
                v-if="totalFunnels === 0"
                class="funnel-empty-card rounded-2xl border px-6 py-12 text-center"
            >
                <div class="text-[var(--ui-text)] text-base font-semibold mb-1">{{ t('settings.funnels.emptyTitle') }}</div>
                <div class="text-sm text-[var(--ui-text-secondary)] mb-4">
                    {{ t('settings.funnels.emptyHint') }}
                </div>
                <div class="flex flex-wrap justify-center gap-2">
                    <button
                        type="button"
                        class="ui-btn ui-btn--primary"
                        @click="openCreateFunnel('ai')"
                    >
                        {{ t('settings.funnels.createWithAi') }}
                    </button>
                    <button
                        type="button"
                        class="ui-btn ui-btn--ghost"
                        @click="openCreateFunnel('manual')"
                    >
                        {{ t('settings.funnels.createManual') }}
                    </button>
                </div>
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="funnel in localFunnels"
                    :key="funnel.id"
                    class="funnel-card rounded-2xl border overflow-hidden"
                >
                    <div
                        class="funnel-card-header px-5 py-4 flex items-center justify-between gap-3"
                    >
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <span
                                class="w-3 h-3 rounded-full shrink-0"
                                :style="{ background: funnel.color }"
                            ></span>
                            <div class="min-w-0">
                                <div class="text-[15px] font-semibold text-[var(--ui-text)] truncate flex items-center gap-2">
                                    {{ funnel.name }}
                                    <span
                                        v-if="!funnel.is_active"
                                        class="text-[10px] px-1.5 py-0.5 rounded bg-red-500/10 text-red-400"
                                    >
                                        {{ t('settings.funnels.inactiveBadge') }}
                                    </span>
                                    <span
                                        v-if="funnelIssueCount(funnel) > 0"
                                        class="text-[10px] px-1.5 py-0.5 rounded cursor-help"
                                        :style="{ color: '#d97706', background: 'rgba(217, 119, 6, .12)' }"
                                        :title="funnelIssueSummary(funnel)"
                                    >
                                        AI: {{ funnelIssueCount(funnel) }} {{ funnelIssueCount(funnel) === 1 ? t('settings.funnels.aiIssuesOne') : t('settings.funnels.aiIssuesMany') }}
                                    </span>
                                </div>
                                <div
                                    v-if="funnel.description"
                                    class="text-xs text-[var(--ui-text-secondary)] truncate"
                                >
                                    {{ funnel.description }}
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button
                                type="button"
                                class="text-xs px-2.5 py-1.5 rounded-md border transition hover:brightness-95"
                                :style="{ color: 'var(--ui-accent)', borderColor: 'var(--ui-accent-border)' }"
                                @click="openCreateStage(funnel)"
                            >
                                {{ t('settings.funnels.addStage') }}
                            </button>
                            <button
                                type="button"
                                class="text-xs px-2.5 py-1.5 rounded-md border transition hover:brightness-95"
                                :style="{ color: 'var(--ui-text)', borderColor: 'var(--ui-border-strong)' }"
                                @click="openEditFunnel(funnel)"
                            >
                                {{ t('settings.funnels.edit') }}
                            </button>
                            <button
                                type="button"
                                class="text-xs px-2.5 py-1.5 rounded-md border border-red-500/40 text-red-400 transition hover:bg-red-500/10"
                                @click="requestDeleteFunnel(funnel)"
                            >
                                {{ t('common.delete') }}
                            </button>
                        </div>
                    </div>

                    <div
                        class="ai-scenario-card px-5 py-4 border-b space-y-3"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.funnels.aiScenario.title') }}</div>
                                <div class="text-xs text-[var(--ui-text-secondary)]">
                                    {{ t('settings.funnels.aiScenario.desc') }}
                                </div>
                            </div>
                            <label class="ai-toggle inline-flex cursor-pointer items-center gap-2 text-sm text-[var(--ui-text)]">
                                <UiCheckbox
                                    :model-value="scenarioDraft(funnel).enabled"
                                    :aria-label="t('settings.funnels.aiScenario.enableAria')"
                                    @update:model-value="(v) => saveAiScenario(funnel, { enabled: v })"
                                />
                                {{ t('settings.funnels.aiScenario.enable') }}
                            </label>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-2 text-xs">
                            <label class="space-y-1">
                                <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.aiScenario.bookingHorizon') }}</span>
                                <input
                                    type="number"
                                    min="1"
                                    max="60"
                                    class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                    :value="scenarioDraft(funnel).booking_horizon_days"
                                    @change="saveAiScenario(funnel, { booking_horizon_days: Number(($event.target as HTMLInputElement).value || 30) })"
                                />
                            </label>
                            <label class="space-y-1">
                                <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.aiScenario.fallbackManager') }}</span>
                                <select
                                    class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                    :value="scenarioDraft(funnel).fallback_manager_user_id ?? ''"
                                    @change="saveAiScenario(funnel, { fallback_manager_user_id: ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null })"
                                >
                                    <option value="">{{ t('settings.funnels.notSelected') }}</option>
                                    <option v-for="u in aiScenarioUsers ?? []" :key="u.id" :value="u.id">{{ u.name }}</option>
                                </select>
                            </label>
                            <label class="space-y-1">
                                <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.aiScenario.taskDepartment') }}</span>
                                <select
                                    class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                    :value="scenarioDraft(funnel).fallback_department_id ?? ''"
                                    @change="saveAiScenario(funnel, { fallback_department_id: ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null })"
                                >
                                    <option value="">{{ t('settings.funnels.notSelected') }}</option>
                                    <option v-for="d in aiScenarioDepartments ?? []" :key="d.id" :value="d.id">{{ d.name }}</option>
                                </select>
                            </label>
                            <span class="inline-flex items-end gap-2 pb-1 text-[var(--ui-text)]">
                                <UiCheckbox
                                    :model-value="scenarioDraft(funnel).manager_confirmation_required"
                                    :aria-label="t('settings.funnels.aiScenario.managerConfirmAria')"
                                    @update:model-value="(v) => saveAiScenario(funnel, { manager_confirmation_required: v })"
                                />
                                {{ t('settings.funnels.aiScenario.managerConfirm') }}
                            </span>
                        </div>
                    </div>

                    <div class="px-5 py-4">
                        <div
                            v-if="funnel.stages.length === 0"
                            class="text-sm text-[var(--ui-text-secondary)] italic"
                        >
                            {{ t('settings.funnels.noStagesInFunnel') }}
                        </div>
                        <ol v-else class="space-y-2">
                            <li
                                v-for="(stage, idx) in sortedStages(funnel)"
                                :key="stage.id"
                                class="stage-card flex flex-col gap-3 px-3 py-3 rounded-xl border transition"
                                :class="{
                                    'opacity-50': draggingStage?.stageId === stage.id,
                                    'ring-2 ring-[var(--ui-accent)]': dragOverStageId === stage.id,
                                }"
                                draggable="true"
                                @dragstart="onStageDragStart(funnel, stage, $event)"
                                @dragover="onStageDragOver(stage.id, $event)"
                                @drop="onStageDrop(funnel, stage.id, $event)"
                                @dragend="onStageDragEnd"
                            >
                                <div class="flex items-center gap-3 w-full">
                                    <button
                                        type="button"
                                        class="cursor-grab px-1 text-[var(--ui-text-secondary)] active:cursor-grabbing"
                                        :title="t('settings.funnels.dragStage')"
                                        @mousedown.stop
                                    >
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                            <circle cx="7" cy="5" r="1.2" />
                                            <circle cx="13" cy="5" r="1.2" />
                                            <circle cx="7" cy="10" r="1.2" />
                                            <circle cx="13" cy="10" r="1.2" />
                                            <circle cx="7" cy="15" r="1.2" />
                                            <circle cx="13" cy="15" r="1.2" />
                                        </svg>
                                    </button>
                                    <span class="text-xs font-mono text-[var(--ui-text-secondary)] w-6 text-right">
                                        {{ idx + 1 }}.
                                    </span>
                                    <span
                                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg"
                                        :style="{ background: `${stage.color}22`, color: stage.color }"
                                        :title="stage.name"
                                    >
                                        <FunnelStageIcon :type="stage.stage_type" :size="16" />
                                    </span>
                                    <span class="flex-1 min-w-0 truncate text-sm font-medium text-[var(--ui-text)]">
                                        {{ stage.name }}
                                        <span
                                            v-if="!stage.is_active"
                                            class="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-red-500/10 text-red-400"
                                        >
                                            {{ t('settings.funnels.inactiveStage') }}
                                        </span>
                                        <span
                                            class="ml-2 text-[10px] px-1.5 py-0.5 rounded"
                                            :style="{
                                                color: stageRuleHealthColor(stage, idx, funnel.stages.length),
                                                background: `${stageRuleHealthColor(stage, idx, funnel.stages.length)}1A`,
                                            }"
                                        >
                                            {{ stageRuleHealthLabel(stage, idx, funnel.stages.length) }}
                                        </span>
                                    </span>
                                    <div class="flex items-center gap-1 shrink-0">
                                    <button
                                        type="button"
                                        class="px-1.5 py-1 rounded text-[var(--ui-text-secondary)] hover:bg-[var(--ui-surface-hover)] disabled:opacity-30"
                                        :disabled="idx === 0"
                                        :title="t('settings.funnels.moveUp')"
                                        @click="moveStage(funnel, stage, -1)"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        class="px-1.5 py-1 rounded text-[var(--ui-text-secondary)] hover:bg-[var(--ui-surface-hover)] disabled:opacity-30"
                                        :disabled="idx === funnel.stages.length - 1"
                                        :title="t('settings.funnels.moveDown')"
                                        @click="moveStage(funnel, stage, 1)"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        class="text-xs px-2 py-1 rounded-md border transition hover:brightness-95"
                                        :style="{ color: 'var(--ui-accent)', borderColor: 'var(--ui-accent-border)' }"
                                        @click="openEditStage(funnel, stage)"
                                    >
                                        {{ t('settings.funnels.edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="text-xs px-2 py-1 rounded-md border border-red-500/40 text-red-400 transition hover:bg-red-500/10"
                                        @click="requestDeleteStage(funnel, stage)"
                                    >
                                        {{ t('common.delete') }}
                                    </button>
                                    </div>
                                </div>
                                <div class="stage-hints w-full flex flex-wrap gap-1.5 pl-10 sm:pl-12">
                                    <span
                                        v-for="hint in stageHints(stage, idx, funnel.stages.length)"
                                        :key="`${stage.id}-${hint.id}`"
                                        class="stage-hint-chip inline-flex max-w-full items-start gap-1 rounded-lg border px-2 py-1 text-[11px] leading-snug"
                                        :style="stageHintToneStyle(hint.tone)"
                                    >
                                        <span class="shrink-0 font-semibold" aria-hidden="true">
                                            {{ hint.tone === 'success' ? '✓' : hint.tone === 'warn' ? '!' : '→' }}
                                        </span>
                                        <span>{{ t(hint.textKey) }}</span>
                                    </span>
                                </div>

                                <details class="ai-rule-panel w-full rounded-xl border px-3 py-2">
                                    <summary class="cursor-pointer text-xs font-semibold text-[var(--ui-accent)]">
                                        {{ t('settings.funnels.stageRules.title') }}
                                        <span
                                            v-if="stageRuleIssues(stage, idx, funnel.stages.length).length > 0"
                                            class="ml-2 rounded-full px-2 py-0.5 text-[10px]"
                                            :style="{ color: stageRuleHealthColor(stage, idx, funnel.stages.length), background: `${stageRuleHealthColor(stage, idx, funnel.stages.length)}1A` }"
                                        >
                                            {{ t('settings.funnels.issuesCount', { count: stageRuleIssues(stage, idx, funnel.stages.length).length }) }}
                                        </span>
                                    </summary>
                                    <div
                                        v-if="stageRuleIssues(stage, idx, funnel.stages.length).length > 0"
                                        class="mt-3 rounded-lg border px-3 py-2"
                                        :style="{ borderColor: 'rgba(217, 119, 6, .35)', background: 'rgba(217, 119, 6, .08)' }"
                                    >
                                        <div class="mb-1 flex items-center justify-between gap-2">
                                            <div class="text-xs font-semibold" :style="{ color: '#d97706' }">{{ t('settings.funnels.stageRules.fixWhat') }}</div>
                                            <button
                                                type="button"
                                                class="rounded-md px-2 py-1 text-[11px] font-semibold"
                                                :style="{ background: 'rgba(217, 119, 6, .14)', color: '#d97706' }"
                                                @click.prevent="applySuggestedStageRule(funnel, stage, idx, funnel.stages.length)"
                                            >
                                                {{ t('settings.funnels.stageRules.fixBasic') }}
                                            </button>
                                        </div>
                                        <ul class="space-y-1 text-xs text-[var(--ui-text-secondary)]">
                                            <li v-for="issue in stageRuleIssues(stage, idx, funnel.stages.length)" :key="issue">
                                                • {{ stageRuleIssueLabel(issue) }}
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3 text-xs">
                                        <label class="space-y-1">
                                            <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.stageRules.goal') }}</span>
                                            <textarea
                                                rows="2"
                                                class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                :value="stageRuleDraft(stage).goal ?? ''"
                                                @change="saveStageAiRule(funnel, stage, { goal: ($event.target as HTMLTextAreaElement).value })"
                                            />
                                        </label>
                                        <label class="space-y-1">
                                            <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.stageRules.transitionConditions') }}</span>
                                            <textarea
                                                rows="2"
                                                class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                :value="stageRuleDraft(stage).transition_conditions ?? ''"
                                                @change="saveStageAiRule(funnel, stage, { transition_conditions: ($event.target as HTMLTextAreaElement).value })"
                                            />
                                        </label>
                                        <label class="space-y-1">
                                            <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.stageRules.requiredQuestions') }}</span>
                                            <textarea
                                                rows="3"
                                                class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                :value="(stageRuleDraft(stage).required_questions ?? []).join('\n')"
                                                @change="saveStageAiRule(funnel, stage, { required_questions: csvToList(($event.target as HTMLTextAreaElement).value) })"
                                            />
                                        </label>
                                        <div class="space-y-1">
                                            <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.stageRules.allowedActions') }}</span>
                                            <div class="flex flex-wrap gap-2">
                                                <span
                                                    v-for="action in aiActionOptions"
                                                    :key="action.id"
                                                    class="action-chip inline-flex items-center gap-1.5 text-[var(--ui-text)] cursor-pointer"
                                                    :class="{ 'action-chip-on': (stageRuleDraft(stage).allowed_actions ?? []).includes(action.id) }"
                                                    role="button"
                                                    tabindex="0"
                                                    @click="saveStageAiRule(funnel, stage, { allowed_actions: toggleStageAction(stage, action.id) })"
                                                    @keydown.enter.prevent="saveStageAiRule(funnel, stage, { allowed_actions: toggleStageAction(stage, action.id) })"
                                                >
                                                    <UiCheckbox
                                                        size="sm"
                                                        :model-value="(stageRuleDraft(stage).allowed_actions ?? []).includes(action.id)"
                                                        :aria-label="action.label"
                                                        @click.stop
                                                    />
                                                    {{ action.label }}
                                                </span>
                                            </div>
                                        </div>
                                        <label class="space-y-1">
                                            <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.stageRules.assigneeDepartment') }}</span>
                                            <select
                                                class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                :value="stageRuleDraft(stage).assignee_department_id ?? ''"
                                                @change="saveStageAiRule(funnel, stage, { assignee_department_id: ($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null })"
                                            >
                                                <option value="">{{ t('settings.funnels.notSelected') }}</option>
                                                <option v-for="d in aiScenarioDepartments ?? []" :key="d.id" :value="d.id">{{ d.name }}</option>
                                            </select>
                                        </label>
                                        <span class="inline-flex items-center gap-2 text-[var(--ui-text)]">
                                            <UiCheckbox
                                                :model-value="stageRuleDraft(stage).require_manager_confirmation"
                                                :aria-label="t('settings.funnels.stageRules.managerConfirmAria')"
                                                @update:model-value="(v) => saveStageAiRule(funnel, stage, { require_manager_confirmation: v })"
                                            />
                                            {{ t('settings.funnels.stageRules.managerConfirm') }}
                                        </span>
                                        <div
                                            class="md:col-span-2 rounded-xl border px-3 py-3 space-y-3"
                                            :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-inset)' }"
                                        >
                                            <label class="block space-y-1">
                                                <span class="text-[var(--ui-text)] font-medium">{{ t('settings.funnels.followUp.title') }}</span>
                                                <select
                                                    class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                    :value="stageRuleDraft(stage).follow_up_strategy ?? 'off'"
                                                    @change="saveStageAiRule(funnel, stage, { follow_up_strategy: ($event.target as HTMLSelectElement).value as FunnelStageAiRule['follow_up_strategy'] })"
                                                >
                                                    <option
                                                        v-for="opt in followUpStrategyOptions"
                                                        :key="opt.id"
                                                        :value="opt.id"
                                                    >
                                                        {{ opt.label }}
                                                    </option>
                                                </select>
                                            </label>
                                            <p
                                                v-if="stageRuleDraft(stage).follow_up_strategy === 'manager_proposals'"
                                                class="text-[11px] leading-relaxed text-[var(--ui-text-secondary)]"
                                            >
                                                {{ t('settings.funnels.followUp.managerProposalsHint') }}
                                            </p>
                                            <div
                                                v-if="stageRuleDraft(stage).follow_up_strategy === 'manager_proposals'"
                                                class="grid grid-cols-1 sm:grid-cols-3 gap-3"
                                            >
                                                <label class="space-y-1">
                                                    <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.followUp.waitHours') }}</span>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="720"
                                                        class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                        :value="stageRuleDraft(stage).follow_up_delay_hours"
                                                        @change="saveStageAiRule(funnel, stage, { follow_up_delay_hours: Number(($event.target as HTMLInputElement).value) || 24 })"
                                                    />
                                                </label>
                                                <label class="space-y-1">
                                                    <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.followUp.silenceAfter') }}</span>
                                                    <select
                                                        class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                        :value="stageRuleDraft(stage).follow_up_silence_after ?? 'outbound'"
                                                        @change="saveStageAiRule(funnel, stage, { follow_up_silence_after: ($event.target as HTMLSelectElement).value as 'inbound' | 'outbound' })"
                                                    >
                                                        <option value="outbound">{{ t('settings.funnels.followUp.silenceOutbound') }}</option>
                                                        <option value="inbound">{{ t('settings.funnels.followUp.silenceInbound') }}</option>
                                                    </select>
                                                </label>
                                                <label class="space-y-1">
                                                    <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.followUp.maxProposalsPerPeriod') }}</span>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="10"
                                                        class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                        :value="stageRuleDraft(stage).follow_up_max_count"
                                                        @change="saveStageAiRule(funnel, stage, { follow_up_max_count: Number(($event.target as HTMLInputElement).value) || 2 })"
                                                    />
                                                </label>
                                            </div>
                                            <div
                                                v-if="stageRuleDraft(stage).follow_up_strategy === 'manager_proposals'"
                                                class="space-y-2"
                                            >
                                                <span class="inline-flex items-center gap-2 text-sm text-[var(--ui-text)]">
                                                    <UiCheckbox
                                                        :model-value="stageRuleDraft(stage).follow_up_use_promotions ?? true"
                                                        :aria-label="t('settings.funnels.followUp.usePromotionsAria')"
                                                        @update:model-value="(v) => saveStageAiRule(funnel, stage, { follow_up_use_promotions: v })"
                                                    />
                                                    {{ t('settings.funnels.followUp.usePromotions') }}
                                                </span>
                                                <div
                                                    v-if="stageRuleDraft(stage).follow_up_use_promotions !== false"
                                                    class="space-y-2 rounded-lg border px-3 py-2"
                                                    :style="{ borderColor: 'var(--ui-border)' }"
                                                >
                                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                                        <span class="text-[var(--ui-text-secondary)] text-sm">
                                                            {{ t('settings.funnels.followUp.limitPromotionsList') }}
                                                        </span>
                                                        <Link
                                                            :href="route('settings.promotions')"
                                                            class="text-[11px] text-[var(--ui-accent)] hover:underline"
                                                        >
                                                            {{ t('settings.funnels.followUp.managePromotions') }}
                                                        </Link>
                                                    </div>
                                                    <p class="text-[11px] text-[var(--ui-text-secondary)]">
                                                        {{ t('settings.funnels.followUp.allPromotionsHint') }}
                                                    </p>
                                                    <p
                                                        v-if="!(promotions ?? []).length"
                                                        class="text-[11px] text-[var(--ui-text-secondary)]"
                                                    >
                                                        {{ t('settings.funnels.followUp.addPromotionsFirst') }}
                                                    </p>
                                                    <div v-else class="flex flex-col gap-2">
                                                        <label
                                                            v-for="promo in promotions"
                                                            :key="promo.id"
                                                            class="inline-flex items-start gap-2 text-[13px] text-[var(--ui-text)]"
                                                        >
                                                            <UiCheckbox
                                                                :model-value="(stageRuleDraft(stage).follow_up_promotion_ids ?? []).includes(promo.id)"
                                                                :aria-label="promotionLabel(promo)"
                                                                @update:model-value="(v) => toggleStagePromotion(funnel, stage, promo.id, v)"
                                                            />
                                                            <span>
                                                                {{ promotionLabel(promo) }}
                                                                <span
                                                                    v-if="!promo.is_currently_valid"
                                                                    class="ml-1 text-[11px] opacity-60"
                                                                >{{ t('settings.funnels.followUp.promoExpired') }}</span>
                                                            </span>
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                            <p
                                                v-if="stageRuleDraft(stage).follow_up_strategy === 'auto_cron'"
                                                class="text-[11px] leading-relaxed text-[var(--ui-text-secondary)]"
                                            >
                                                {{ isLastFunnelStage(funnel, stage)
                                                    ? t('settings.funnels.followUp.lastStageHint')
                                                    : t('settings.funnels.followUp.clientFollowUpHint') }}
                                            </p>
                                            <div
                                                v-if="stageRuleDraft(stage).follow_up_strategy === 'auto_cron' && !isLastFunnelStage(funnel, stage)"
                                                class="grid grid-cols-1 sm:grid-cols-3 gap-3"
                                            >
                                                <label class="space-y-1">
                                                    <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.followUp.waitHours') }}</span>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="720"
                                                        class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                        :value="stageRuleDraft(stage).follow_up_delay_hours"
                                                        @change="saveStageAiRule(funnel, stage, { follow_up_delay_hours: Number(($event.target as HTMLInputElement).value) || 24 })"
                                                    />
                                                </label>
                                                <label class="space-y-1">
                                                    <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.followUp.pauseHours') }}</span>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="720"
                                                        class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                        :value="stageRuleDraft(stage).follow_up_cooldown_hours"
                                                        @change="saveStageAiRule(funnel, stage, { follow_up_cooldown_hours: Number(($event.target as HTMLInputElement).value) || 72 })"
                                                    />
                                                </label>
                                                <label class="space-y-1">
                                                    <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.followUp.maxPerPeriod') }}</span>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="10"
                                                        class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                        :value="stageRuleDraft(stage).follow_up_max_count"
                                                        @change="saveStageAiRule(funnel, stage, { follow_up_max_count: Number(($event.target as HTMLInputElement).value) || 2 })"
                                                    />
                                                </label>
                                            </div>
                                            <div
                                                v-if="stageRuleDraft(stage).follow_up_strategy === 'auto_cron' && !isLastFunnelStage(funnel, stage)"
                                                class="space-y-3"
                                            >
                                                <label class="block space-y-1">
                                                    <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.followUp.textMode') }}</span>
                                                    <select
                                                        class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                        :value="stageRuleDraft(stage).follow_up_mode"
                                                        @change="saveStageAiRule(funnel, stage, { follow_up_mode: ($event.target as HTMLSelectElement).value as FunnelStageAiRule['follow_up_mode'] })"
                                                    >
                                                        <option
                                                            v-for="opt in followUpModeOptions"
                                                            :key="opt.id"
                                                            :value="opt.id"
                                                        >
                                                            {{ opt.label }} — {{ opt.hint }}
                                                        </option>
                                                    </select>
                                                </label>
                                                <template v-if="stageRuleDraft(stage).follow_up_mode !== 'ai'">
                                                    <label class="block space-y-1">
                                                        <span class="text-[var(--ui-text-secondary)]">
                                                            {{ stageRuleDraft(stage).follow_up_mode === 'ab' ? t('settings.funnels.followUp.variantA') : t('settings.funnels.followUp.messageText') }}
                                                        </span>
                                                        <textarea
                                                            rows="3"
                                                            class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                            :value="stageRuleDraft(stage).follow_up_message ?? ''"
                                                            :placeholder="t('settings.funnels.followUp.messagePlaceholder')"
                                                            @change="saveStageAiRule(funnel, stage, { follow_up_message: ($event.target as HTMLTextAreaElement).value })"
                                                        />
                                                    </label>
                                                    <label
                                                        v-if="stageRuleDraft(stage).follow_up_mode === 'ab'"
                                                        class="block space-y-1"
                                                    >
                                                        <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.followUp.variantB') }}</span>
                                                        <textarea
                                                            rows="3"
                                                            class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                            :value="stageRuleDraft(stage).follow_up_message_b ?? ''"
                                                            :placeholder="t('settings.funnels.followUp.variantBPlaceholder')"
                                                            @change="saveStageAiRule(funnel, stage, { follow_up_message_b: ($event.target as HTMLTextAreaElement).value })"
                                                        />
                                                    </label>
                                                    <label
                                                        v-if="stageRuleDraft(stage).follow_up_mode === 'ab'"
                                                        class="block space-y-1 max-w-xs"
                                                    >
                                                        <span class="text-[var(--ui-text-secondary)]">{{ t('settings.funnels.followUp.abRatio') }}</span>
                                                        <input
                                                            type="number"
                                                            min="0"
                                                            max="100"
                                                            class="ui-field w-full rounded-lg border px-2.5 py-2 text-[var(--ui-text)]"
                                                            :value="stageRuleDraft(stage).follow_up_ab_ratio"
                                                            @change="saveStageAiRule(funnel, stage, { follow_up_ab_ratio: Number(($event.target as HTMLInputElement).value) || 50 })"
                                                        />
                                                    </label>
                                                </template>
                                                <p
                                                    v-else
                                                    class="text-[11px] leading-relaxed text-[var(--ui-text-secondary)]"
                                                >
                                                    {{ t('settings.funnels.followUp.aiGeneratedHint') }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </details>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: Funnel -->
        <Teleport to="body">
            <div
                v-if="funnelModalOpen"
                class="fixed inset-0 z-[2000] flex items-center justify-center p-4 bg-black/60"
                role="dialog"
                aria-modal="true"
                @click.self="closeFunnelModal"
            >
                <div
                    class="w-full max-h-[min(90vh,800px)] overflow-hidden flex flex-col rounded-xl border shadow-2xl"
                    :class="editingFunnelId === null && funnelMode === 'ai' && !aiSuggested ? 'max-w-2xl' : 'max-w-lg'"
                    :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border-strong)' }"
                    @click.stop
                >
                    <div
                        class="px-5 py-4 border-b shrink-0 flex items-center justify-between gap-3"
                        :style="{ borderColor: 'var(--ui-border)' }"
                    >
                        <h3 class="text-base font-medium text-[var(--ui-text)]">
                            {{ editingFunnelId === null ? t('settings.funnels.modals.newFunnel') : t('settings.funnels.modals.editFunnel') }}
                        </h3>
                        <button
                            type="button"
                            class="text-sm text-[var(--ui-text-secondary)] hover:text-[var(--ui-text)] px-2 py-1 rounded"
                            :aria-label="t('common.close')"
                            @click="closeFunnelModal"
                        >
                            ✕
                        </button>
                    </div>

                    <div
                        v-if="editingFunnelId === null"
                        class="px-5 pt-4 shrink-0"
                    >
                        <div
                            class="inline-flex rounded-lg border p-1 text-xs"
                            :style="{ background: 'var(--ui-bg)', borderColor: 'var(--ui-border-strong)' }"
                        >
                            <button
                                type="button"
                                class="px-3 py-1.5 rounded-md transition"
                                :style="funnelMode === 'manual'
                                    ? { background: 'var(--ui-accent)', color: '#fff' }
                                    : { color: 'var(--ui-text-secondary)' }"
                                @click="switchFunnelMode('manual')"
                            >
                                {{ t('settings.funnels.modals.manual') }}
                            </button>
                            <button
                                type="button"
                                class="px-3 py-1.5 rounded-md transition flex items-center gap-1.5"
                                :style="funnelMode === 'ai'
                                    ? { background: 'var(--ui-accent)', color: '#fff' }
                                    : { color: 'var(--ui-text-secondary)' }"
                                @click="switchFunnelMode('ai')"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.5 6.5L22 12l-6.5 2.5L13 21l-2.5-6.5L4 12l6.5-2.5L13 3z" />
                                </svg>
                                {{ t('settings.funnels.aiBuilder') }}
                            </button>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto wa-scrollbar px-5 py-4 space-y-3">
                        <template v-if="funnelMode === 'ai' && editingFunnelId === null && !aiSuggested">
                            <FunnelAiWizard
                                ref="aiWizardRef"
                                @select="onWizardSelect"
                            />
                        </template>

                        <div v-if="funnelMode === 'manual' || aiSuggested">
                            <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.funnels.modals.name') }}</label>
                            <input
                                v-model="funnelForm.name"
                                type="text"
                                class="settings-input"
                                :class="{ 'settings-input-error': funnelErrors.name }"
                                :placeholder="t('settings.funnels.modals.namePlaceholderFunnel')"
                            />
                            <div v-if="funnelErrors.name" class="text-xs text-red-400 mt-1 whitespace-pre-line">
                                {{ funnelErrors.name }}
                            </div>
                        </div>

                        <div v-if="funnelMode === 'manual' || aiSuggested">
                            <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.funnels.modals.descriptionOptional') }}</label>
                            <textarea
                                v-model="funnelForm.description"
                                class="settings-input min-h-[64px]"
                                rows="2"
                                :placeholder="t('settings.funnels.modals.descriptionPlaceholder')"
                            ></textarea>
                        </div>

                        <div v-if="funnelMode === 'manual' || aiSuggested">
                            <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.funnels.modals.color') }}</label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="c in palette"
                                    :key="c"
                                    type="button"
                                    class="w-7 h-7 rounded-full border-2 transition"
                                    :style="{
                                        background: c,
                                        borderColor: funnelForm.color === c ? 'var(--ui-text)' : 'transparent',
                                    }"
                                    @click="funnelForm.color = c"
                                />
                            </div>
                        </div>

                        <div
                            v-if="funnelMode === 'manual' || aiSuggested"
                            class="flex items-center gap-2 pt-1"
                        >
                            <UiCheckbox id="funnel-active" v-model="funnelForm.is_active" />
                            <label for="funnel-active" class="text-sm text-[var(--ui-text)] cursor-pointer">
                                {{ t('settings.funnels.modals.active') }}
                            </label>
                        </div>

                        <!-- AI stages preview / editor -->
                        <div
                            v-if="funnelMode === 'ai' && aiSuggested"
                            class="pt-2 border-t"
                            :style="{ borderColor: 'var(--ui-border)' }"
                        >
                            <div class="flex items-center justify-between mb-2 mt-2">
                                <label class="text-sm text-[var(--ui-text-secondary)]">
                                    {{ t('settings.funnels.modals.stagesInFunnel', { count: aiStages.length }) }}
                                </label>
                                <button
                                    type="button"
                                    class="text-xs px-2 py-1 rounded-md border transition hover:brightness-95"
                                    :style="{ color: 'var(--ui-accent)', borderColor: 'var(--ui-accent-border)' }"
                                    @click="addAiStage"
                                >
                                    {{ t('settings.funnels.addStage') }}
                                </button>
                            </div>

                            <ol v-if="aiStages.length > 0" class="space-y-2">
                                <li
                                    v-for="(stage, idx) in aiStages"
                                    :key="idx"
                                    class="flex items-center gap-2 px-2.5 py-2 rounded-lg border"
                                    :style="{ background: 'var(--ui-bg)', borderColor: 'var(--ui-border-strong)' }"
                                >
                                    <span class="text-xs font-mono text-[var(--ui-text-secondary)] w-5 text-right">
                                        {{ idx + 1 }}.
                                    </span>
                                    <input
                                        v-model="stage.color"
                                        type="color"
                                        class="w-6 h-6 rounded cursor-pointer border-0 p-0 bg-transparent shrink-0"
                                        :title="stage.color"
                                    />
                                    <input
                                        v-model="stage.name"
                                        type="text"
                                        class="settings-input flex-1 min-w-0"
                                        :placeholder="t('settings.funnels.modals.stageNamePlaceholder')"
                                    />
                                    <div class="flex items-center gap-0.5 shrink-0">
                                        <button
                                            type="button"
                                            class="px-1.5 py-1 rounded text-[var(--ui-text-secondary)] hover:bg-[var(--ui-surface-hover)] disabled:opacity-30"
                                            :disabled="idx === 0"
                                            :title="t('settings.funnels.moveUp')"
                                            @click="moveAiStage(idx, -1)"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            class="px-1.5 py-1 rounded text-[var(--ui-text-secondary)] hover:bg-[var(--ui-surface-hover)] disabled:opacity-30"
                                            :disabled="idx === aiStages.length - 1"
                                            :title="t('settings.funnels.moveDown')"
                                            @click="moveAiStage(idx, 1)"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                        <button
                                            type="button"
                                            class="px-1.5 py-1 rounded text-red-400 hover:bg-red-500/10"
                                            :title="t('settings.funnels.removeStage')"
                                            @click="removeAiStage(idx)"
                                        >
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                </li>
                            </ol>
                            <div v-else class="text-xs text-[var(--ui-text-secondary)] italic">
                                {{ t('settings.funnels.modals.noStagesInModal') }}
                            </div>
                        </div>
                    </div>

                    <div
                        v-if="funnelMode !== 'ai' || editingFunnelId !== null || aiSuggested"
                        class="flex justify-end gap-2 px-5 py-3 border-t shrink-0"
                        :style="{ borderColor: 'var(--ui-border)' }"
                    >
                        <button
                            type="button"
                            class="ui-btn ui-btn--secondary"
                            @click="closeFunnelModal"
                        >
                            {{ t('common.cancel') }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--primary"
                            :disabled="savingFunnel || (funnelMode === 'ai' && editingFunnelId === null && !aiSuggested)"
                            @click="saveFunnel"
                        >
                            <template v-if="savingFunnel">{{ t('settings.funnels.savingEllipsis') }}</template>
                            <template v-else-if="editingFunnelId !== null">{{ t('common.save') }}</template>
                            <template v-else-if="funnelMode === 'ai' && aiSuggested">
                                {{ t('settings.funnels.modals.createWithStages', { count: aiStages.length }) }}
                            </template>
                            <template v-else>{{ t('settings.funnels.create') }}</template>
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal: Stage -->
        <Teleport to="body">
            <div
                v-if="stageModalOpen"
                class="fixed inset-0 z-[2000] flex items-center justify-center p-4 bg-black/60"
                role="dialog"
                aria-modal="true"
                @click.self="closeStageModal"
            >
                <div
                    class="w-full max-w-md max-h-[min(90vh,800px)] overflow-hidden flex flex-col rounded-xl border shadow-2xl"
                    :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border-strong)' }"
                    @click.stop
                >
                    <div
                        class="px-5 py-4 border-b shrink-0 flex items-center justify-between gap-3"
                        :style="{ borderColor: 'var(--ui-border)' }"
                    >
                        <h3 class="text-base font-medium text-[var(--ui-text)]">
                            <template v-if="stageContext.editingStageId === null">
                                {{ t('settings.funnels.modals.newStage') }}
                            </template>
                            <template v-else>
                                {{ t('settings.funnels.modals.editStage') }}
                            </template>
                            <span class="text-xs text-[var(--ui-text-secondary)] ml-1">
                                — {{ stageContext.funnel?.name }}
                            </span>
                        </h3>
                        <button
                            type="button"
                            class="text-sm text-[var(--ui-text-secondary)] hover:text-[var(--ui-text)] px-2 py-1 rounded"
                            :aria-label="t('common.close')"
                            @click="closeStageModal"
                        >
                            ✕
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto wa-scrollbar px-5 py-4 space-y-3">
                        <div>
                            <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.funnels.modals.name') }}</label>
                            <input
                                v-model="stageForm.name"
                                type="text"
                                class="settings-input"
                                :class="{ 'settings-input-error': stageErrors.name }"
                                :placeholder="t('settings.funnels.modals.stageNamePlaceholder')"
                            />
                            <div v-if="stageErrors.name" class="text-xs text-red-400 mt-1 whitespace-pre-line">
                                {{ stageErrors.name }}
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">{{ t('settings.funnels.modals.color') }}</label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="c in palette"
                                    :key="c"
                                    type="button"
                                    class="w-7 h-7 rounded-full border-2 transition"
                                    :style="{
                                        background: c,
                                        borderColor: stageForm.color === c ? 'var(--ui-text)' : 'transparent',
                                    }"
                                    @click="stageForm.color = c"
                                />
                            </div>
                        </div>

                        <div>
                            <div class="mb-2 flex items-center justify-between gap-2">
                                <label class="block text-sm text-[var(--ui-text-secondary)]">{{ t('settings.funnels.modals.stageType') }}</label>
                                <button
                                    type="button"
                                    class="text-xs text-[var(--ui-accent)] hover:underline"
                                    @click="stageForm.stage_type = guessStageTypeFromName(stageForm.name)"
                                >
                                    {{ t('settings.funnels.modals.guessTypeFromName') }}
                                </button>
                            </div>
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                <button
                                    v-for="option in FUNNEL_STAGE_TYPES"
                                    :key="option.value"
                                    type="button"
                                    class="flex items-center gap-2 rounded-lg border px-2 py-2 text-left text-xs transition"
                                    :style="{
                                        borderColor: stageForm.stage_type === option.value ? 'var(--ui-accent)' : 'var(--ui-border)',
                                        background: stageForm.stage_type === option.value ? 'var(--ui-accent-soft)' : 'var(--ui-surface-muted)',
                                        color: 'var(--ui-text)',
                                    }"
                                    @click="stageForm.stage_type = option.value"
                                >
                                    <FunnelStageIcon :type="option.value" :size="16" />
                                    <span>{{ option.label }}</span>
                                </button>
                            </div>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-[var(--ui-text-secondary)]">
                                {{ t('settings.funnels.modals.wipLimit') }}
                            </label>
                            <input
                                v-model="stageForm.wip_limit"
                                type="number"
                                min="1"
                                max="999"
                                :placeholder="t('settings.funnels.modals.wipLimitPlaceholder')"
                                class="ui-input w-full"
                            />
                            <p class="mt-1 mb-0 text-[11px] text-[var(--ui-text-secondary)]">
                                {{ t('settings.funnels.modals.wipLimitHint') }}
                            </p>
                        </div>

                        <div class="flex items-center gap-2 pt-1">
                            <UiCheckbox id="stage-active" v-model="stageForm.is_active" />
                            <label for="stage-active" class="text-sm text-[var(--ui-text)] cursor-pointer">
                                {{ t('settings.funnels.modals.activeStage') }}
                            </label>
                        </div>
                    </div>

                    <div
                        class="flex justify-end gap-2 px-5 py-3 border-t shrink-0"
                        :style="{ borderColor: 'var(--ui-border)' }"
                    >
                        <button
                            type="button"
                            class="ui-btn ui-btn--secondary"
                            @click="closeStageModal"
                        >
                            {{ t('common.cancel') }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--primary"
                            :disabled="savingStage"
                            @click="saveStage"
                        >
                            {{ savingStage ? t('settings.funnels.savingEllipsis') : (stageContext.editingStageId === null ? t('settings.funnels.create') : t('common.save')) }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </SettingsLayout>

    <DangerConfirmModal
        :open="bulkDeleteOpen"
        :title="bulkDeleteTitle"
        :description="bulkDeleteDescription"
        :confirm-label="t('common.delete')"
        :busy="bulkDeleteBusy"
        confirm-variant="danger"
        @close="closeBulkDelete"
        @confirm="confirmBulkDelete"
    />
</template>

<style scoped>
.funnel-empty-card,
.funnel-card {
    background: var(--ui-surface);
    border-color: var(--ui-border);
    box-shadow: var(--ui-shadow-card);
}

.funnel-card-header {
    background: var(--ui-surface-raised);
    border-bottom: 1px solid var(--ui-border);
}

.ai-scenario-card {
    background: color-mix(in srgb, var(--ui-accent) 6%, var(--ui-surface));
    border-color: var(--ui-accent-border);
}

.stage-card {
    background: var(--ui-surface-muted);
    border-color: var(--ui-border);
    box-shadow: var(--ui-shadow-soft);
}

.ai-rule-panel {
    background: color-mix(in srgb, var(--ui-surface) 78%, var(--ui-surface-muted) 22%);
    border-color: var(--ui-border);
}

.ai-toggle {
    padding: 0.35rem 0.55rem;
    border: 1px solid var(--ui-border);
    border-radius: 999px;
    background: var(--ui-surface);
    transition: border-color 0.15s ease, background-color 0.15s ease;
}

.funnels-page select,
.funnels-page input,
.funnels-page textarea {
    color-scheme: light;
}

[data-theme='dark'] .funnels-page select,
[data-theme='dark'] .funnels-page input,
[data-theme='dark'] .funnels-page textarea {
    color-scheme: dark;
}
</style>
