<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import type { Department } from '@/types';
import { useToastStore } from '@/stores/toast';

const { show: showToast } = useToastStore();

interface DeptUser {
    id: number;
    name: string;
    email: string;
    department_id?: number | null;
}

interface WorkDaySlot {
    enabled: boolean;
    from: string;
    to: string;
}

type WorkDayKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

interface DepartmentRow extends Department {
    users_count: number;
    users: DeptUser[];
    funnel_ids?: number[];
    funnel_stage_ids?: number[];
    work_schedule_enabled?: boolean;
    work_schedule_timezone?: string | null;
    work_schedule?: Partial<Record<WorkDayKey, WorkDaySlot>>;
}

const WEEK_DAYS: Array<{ key: WorkDayKey; label: string }> = [
    { key: 'mon', label: 'Понедельник' },
    { key: 'tue', label: 'Вторник' },
    { key: 'wed', label: 'Среда' },
    { key: 'thu', label: 'Четверг' },
    { key: 'fri', label: 'Пятница' },
    { key: 'sat', label: 'Суббота' },
    { key: 'sun', label: 'Воскресенье' },
];

const TIMEZONE_OPTIONS = [
    'Asia/Almaty',
    'Asia/Aqtobe',
    'Asia/Aqtau',
    'Asia/Qyzylorda',
    'Asia/Oral',
    'Europe/Moscow',
    'UTC',
];

function defaultWorkWeek(): Record<WorkDayKey, WorkDaySlot> {
    const slot = (enabled: boolean): WorkDaySlot => ({ enabled, from: '09:00', to: '18:00' });
    return {
        mon: slot(true),
        tue: slot(true),
        wed: slot(true),
        thu: slot(true),
        fri: slot(true),
        sat: slot(false),
        sun: slot(false),
    };
}

function mergeWorkWeek(raw?: Partial<Record<WorkDayKey, WorkDaySlot>>): Record<WorkDayKey, WorkDaySlot> {
    const base = defaultWorkWeek();
    if (!raw) return base;
    for (const { key } of WEEK_DAYS) {
        const day = raw[key];
        if (day) {
            base[key] = {
                enabled: day.enabled === true,
                from: day.from || base[key].from,
                to: day.to || base[key].to,
            };
        }
    }
    return base;
}

function scheduleSummary(dept: DepartmentRow): string | null {
    if (!dept.work_schedule_enabled) return null;
    const week = mergeWorkWeek(dept.work_schedule);
    const parts = WEEK_DAYS.filter((d) => week[d.key].enabled).map(
        (d) => `${d.label.slice(0, 2)} ${week[d.key].from}–${week[d.key].to}`,
    );
    return parts.length ? parts.join(', ') : 'график не задан';
}

interface AssignmentUser {
    id: number;
    name: string;
    email: string;
    department_id: number | null;
    department_ids?: number[];
    is_active: boolean;
}

interface FunnelStageOption {
    id: number;
    funnel_id: number;
    name: string;
    color: string;
    position: number;
    is_active: boolean;
}

interface FunnelOption {
    id: number;
    name: string;
    description: string | null;
    color: string;
    is_active: boolean;
    position: number;
    stages: FunnelStageOption[];
}

/**
 * Узел иерархии для отрисовки. depth — отступ для лесенки.
 */
interface DepartmentNode {
    dept: DepartmentRow;
    depth: number;
}

const props = defineProps<{
    departments: DepartmentRow[];
    users: AssignmentUser[];
    funnels?: FunnelOption[];
}>();

const localDepartments = ref<DepartmentRow[]>([...props.departments]);
const localUsers = ref<AssignmentUser[]>([...props.users]);
const localFunnels = computed<FunnelOption[]>(() => props.funnels ?? []);

watch(
    () => props.departments,
    (v) => {
        localDepartments.value = [...v];
    },
    { deep: true },
);
watch(
    () => props.users,
    (v) => {
        localUsers.value = [...v];
    },
    { deep: true },
);

const modalOpen = ref(false);
const saving = ref(false);
const deptDeleteOpen = ref(false);
const deptDeleteTarget = ref<DepartmentRow | null>(null);
const deptDeleteBusy = ref(false);
const memberSearch = ref('');
const modalTab = ref<'members' | 'funnels' | 'schedule'>('members');
const departmentSearch = ref('');
const departmentStatusFilter = ref<'all' | 'active' | 'inactive'>('all');
const departmentTypeFilter = ref<'all' | 'root' | 'nested'>('all');
const departmentMembersFilter = ref<'all' | 'with_users' | 'empty'>('all');

/**
 * editingId == null → создание нового отдела;
 * editingId == number → редактирование существующего (PUT по этому id).
 */
const editingId = ref<number | null>(null);

const form = ref({
    name: '',
    description: '',
    parent_id: null as number | null,
    is_active: true,
    work_schedule_enabled: false,
    work_schedule_timezone: 'Asia/Almaty',
    work_schedule: defaultWorkWeek(),
});

const validationErrors = ref<Record<string, string>>({});

const selectedMemberIds = ref<number[]>([]);

/**
 * Выбранные воронки и их этапы для редактируемого/создаваемого отдела.
 * Состояние: Set'ы для O(1) toggle и проверки "включено ли".
 */
const selectedFunnelIds = ref<Set<number>>(new Set());
const selectedStageIds = ref<Set<number>>(new Set());

const showMemberSearch = computed(() => localUsers.value.length > 10);

const filteredUsersForPicker = computed(() => {
    const list = localUsers.value;
    if (!showMemberSearch.value || !memberSearch.value.trim()) {
        return list;
    }
    const q = memberSearch.value.trim().toLowerCase();
    return list.filter((u) => {
        const name = (u.name || '').toLowerCase();
        const email = (u.email || '').toLowerCase();
        return name.includes(q) || email.includes(q);
    });
});

/**
 * Превращаем плоский список в плоский же список «нод дерева» (DFS-порядок),
 * чтобы простым `<div v-for>` отрисовать иерархию с отступами без вложенных циклов.
 */
function buildDepartmentTree(departments: DepartmentRow[]): DepartmentNode[] {
    const sorted = [...departments].sort((a, b) => a.name.localeCompare(b.name, 'ru'));
    const byParent = new Map<number | null, DepartmentRow[]>();
    for (const d of sorted) {
        const key = d.parent_id ?? null;
        if (!byParent.has(key)) byParent.set(key, []);
        byParent.get(key)!.push(d);
    }

    const result: DepartmentNode[] = [];
    const visited = new Set<number>();

    function walk(parentId: number | null, depth: number): void {
        const list = byParent.get(parentId) ?? [];
        for (const dept of list) {
            if (visited.has(dept.id)) continue;
            visited.add(dept.id);
            result.push({ dept, depth });
            walk(dept.id, depth + 1);
        }
    }

    walk(null, 0);

    // На случай "осиротевших" (parent_id указывает на несуществующий, удалённый отдел) —
    // показываем их как корневые, чтобы они не пропали из UI.
    for (const d of sorted) {
        if (!visited.has(d.id)) {
            result.push({ dept: d, depth: 0 });
        }
    }

    return result;
}

const departmentTree = computed<DepartmentNode[]>(() => buildDepartmentTree(localDepartments.value));

const visibleDepartments = computed<DepartmentRow[]>(() => {
    const q = departmentSearch.value.trim().toLowerCase();
    const matched = localDepartments.value.filter((dept) => {
        if (departmentStatusFilter.value === 'active' && dept.is_active === false) return false;
        if (departmentStatusFilter.value === 'inactive' && dept.is_active !== false) return false;
        if (departmentTypeFilter.value === 'root' && dept.parent_id !== null) return false;
        if (departmentTypeFilter.value === 'nested' && dept.parent_id === null) return false;
        if (departmentMembersFilter.value === 'with_users' && (dept.users_count ?? 0) <= 0) return false;
        if (departmentMembersFilter.value === 'empty' && (dept.users_count ?? 0) > 0) return false;

        if (!q) return true;

        const haystack = [
            dept.name,
            dept.description,
            ...(dept.users ?? []).map((u) => `${u.name} ${u.email}`),
            ...funnelBadgesFor(dept).map((f) => f.name),
        ].join(' ').toLowerCase();

        return haystack.includes(q);
    });

    if (!q) {
        return matched;
    }

    // При поиске добавляем родителей совпавших отделов, чтобы вложенный отдел
    // не отображался без контекста своей ветки.
    const byId = new Map(localDepartments.value.map((dept) => [dept.id, dept]));
    const ids = new Set(matched.map((dept) => dept.id));
    for (const dept of matched) {
        let parentId = dept.parent_id ?? null;
        while (parentId !== null) {
            const parent = byId.get(parentId);
            if (!parent) break;
            ids.add(parent.id);
            parentId = parent.parent_id ?? null;
        }
    }

    return localDepartments.value.filter((dept) => ids.has(dept.id));
});

const visibleDepartmentTree = computed<DepartmentNode[]>(() => buildDepartmentTree(visibleDepartments.value));

const hasDepartmentFilters = computed(() =>
    departmentSearch.value.trim() !== ''
    || departmentStatusFilter.value !== 'all'
    || departmentTypeFilter.value !== 'all'
    || departmentMembersFilter.value !== 'all',
);

function resetDepartmentFilters(): void {
    departmentSearch.value = '';
    departmentStatusFilter.value = 'all';
    departmentTypeFilter.value = 'all';
    departmentMembersFilter.value = 'all';
}

/**
 * Идентификаторы потомков отдела + его собственный id — нельзя выбирать как родителя
 * (создаст цикл). Возвращаем Set для O(1) проверки в селекторе.
 */
function descendantIdsAndSelf(rootId: number): Set<number> {
    const result = new Set<number>([rootId]);
    const queue: number[] = [rootId];
    while (queue.length > 0) {
        const current = queue.shift()!;
        for (const d of localDepartments.value) {
            if ((d.parent_id ?? null) === current && !result.has(d.id)) {
                result.add(d.id);
                queue.push(d.id);
            }
        }
    }
    return result;
}

/**
 * Список отделов, доступных как родитель для текущего редактируемого/создаваемого.
 * При создании — все. При редактировании — все, кроме самого себя и его потомков.
 */
const eligibleParents = computed<DepartmentNode[]>(() => {
    const blocked = editingId.value !== null ? descendantIdsAndSelf(editingId.value) : new Set<number>();
    return departmentTree.value.filter((node) => !blocked.has(node.dept.id));
});

function openCreate(presetParentId: number | null = null) {
    editingId.value = null;
    form.value = {
        name: '',
        description: '',
        parent_id: presetParentId,
        is_active: true,
        work_schedule_enabled: false,
        work_schedule_timezone: 'Asia/Almaty',
        work_schedule: defaultWorkWeek(),
    };
    selectedMemberIds.value = [];
    selectedFunnelIds.value = new Set();
    selectedStageIds.value = new Set();
    memberSearch.value = '';
    modalTab.value = 'members';
    validationErrors.value = {};
    modalOpen.value = true;
}

function openEdit(dept: DepartmentRow) {
    editingId.value = dept.id;
    form.value = {
        name: dept.name ?? '',
        description: dept.description ?? '',
        parent_id: dept.parent_id ?? null,
        is_active: dept.is_active !== false,
        work_schedule_enabled: dept.work_schedule_enabled === true,
        work_schedule_timezone: dept.work_schedule_timezone || 'Asia/Almaty',
        work_schedule: mergeWorkWeek(dept.work_schedule),
    };
    selectedMemberIds.value = (dept.users ?? []).map((u) => u.id);
    selectedFunnelIds.value = new Set((dept.funnel_ids ?? []).map((x) => Number(x)));
    selectedStageIds.value = new Set((dept.funnel_stage_ids ?? []).map((x) => Number(x)));
    memberSearch.value = '';
    modalTab.value = 'members';
    validationErrors.value = {};
    modalOpen.value = true;
}

/**
 * Включить/выключить воронку. При включении автоматически отмечаем ВСЕ её этапы
 * (типичный сценарий «отдел работает со всей воронкой»). При выключении — снимаем
 * всё, чтобы не оставить «висячих» этапов без активной воронки.
 */
function toggleFunnel(funnel: FunnelOption) {
    const next = new Set(selectedFunnelIds.value);
    const stages = new Set(selectedStageIds.value);

    if (next.has(funnel.id)) {
        next.delete(funnel.id);
        for (const s of funnel.stages) stages.delete(s.id);
    } else {
        next.add(funnel.id);
        for (const s of funnel.stages) stages.add(s.id);
    }

    selectedFunnelIds.value = next;
    selectedStageIds.value = stages;
}

/**
 * Отметить/снять конкретный этап. Если этап включают, а его воронка ещё не
 * отмечена — автоматически включаем и воронку. Если снимают последний этап
 * воронки — она остаётся «активной без этапов»: пользователь сам решит, удалить
 * её через переключатель воронки или добавить другие этапы.
 */
function toggleStage(funnel: FunnelOption, stage: FunnelStageOption) {
    const stages = new Set(selectedStageIds.value);
    const funnels = new Set(selectedFunnelIds.value);

    if (stages.has(stage.id)) {
        stages.delete(stage.id);
    } else {
        stages.add(stage.id);
        funnels.add(funnel.id);
    }

    selectedStageIds.value = stages;
    selectedFunnelIds.value = funnels;
}

function isFunnelChecked(funnel: FunnelOption): boolean {
    return selectedFunnelIds.value.has(funnel.id);
}
function isStageChecked(stage: FunnelStageOption): boolean {
    return selectedStageIds.value.has(stage.id);
}
function selectedStageCount(funnel: FunnelOption): number {
    let n = 0;
    for (const s of funnel.stages) {
        if (selectedStageIds.value.has(s.id)) n++;
    }
    return n;
}

function closeModal() {
    modalOpen.value = false;
    validationErrors.value = {};
}

function toggleMember(id: number) {
    const idx = selectedMemberIds.value.indexOf(id);
    if (idx === -1) {
        selectedMemberIds.value = [...selectedMemberIds.value, id];
    } else {
        selectedMemberIds.value = selectedMemberIds.value.filter((x) => x !== id);
    }
}

function applyValidationErrors(err: unknown): boolean {
    const e = err as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } };
    if (e.response?.status === 422 && e.response.data?.errors) {
        const flat: Record<string, string> = {};
        for (const [k, msgs] of Object.entries(e.response.data.errors)) {
            flat[k] = (msgs as string[]).join('\n');
        }
        validationErrors.value = flat;
        return true;
    }
    return false;
}

function fallbackErrorMessage(err: unknown): string {
    const e = err as { response?: { data?: { message?: string } } };
    return e.response?.data?.message || 'Ошибка сохранения';
}

async function saveModal() {
    if (!form.value.name.trim()) {
        validationErrors.value = { name: 'Укажите название отдела' };
        return;
    }
    if (saving.value) return;
    saving.value = true;
    validationErrors.value = {};

    try {
        const payload = {
            name: form.value.name.trim(),
            description: form.value.description.trim() || null,
            parent_id: form.value.parent_id,
            is_active: form.value.is_active,
            funnel_ids: Array.from(selectedFunnelIds.value),
            funnel_stage_ids: Array.from(selectedStageIds.value),
            work_schedule_enabled: form.value.work_schedule_enabled,
            work_schedule_timezone: form.value.work_schedule_enabled
                ? form.value.work_schedule_timezone
                : null,
            work_schedule: form.value.work_schedule_enabled ? form.value.work_schedule : null,
        };

        let deptId: number;
        if (editingId.value === null) {
            const { data } = await axios.post(route('settings.departments.store'), payload);
            deptId = data.department?.id as number;
        } else {
            const { data } = await axios.put(
                route('settings.departments.update', editingId.value),
                payload,
            );
            deptId = data.department?.id as number;
        }

        await axios.post(route('settings.departments.members.sync', deptId), {
            user_ids: selectedMemberIds.value,
        });

        closeModal();
        router.reload({ only: ['departments', 'users'] });
    } catch (err: unknown) {
        if (!applyValidationErrors(err)) {
            showToast({ message: fallbackErrorMessage(err), type: 'warning' });
        }
    } finally {
        saving.value = false;
    }
}

function closeDeptDelete(): void {
    if (deptDeleteBusy.value) return;
    deptDeleteOpen.value = false;
    deptDeleteTarget.value = null;
}

function requestRemoveDepartment(dept: DepartmentRow): void {
    deptDeleteTarget.value = dept;
    deptDeleteOpen.value = true;
}

const deptDeleteDescription = computed(() => {
    const dept = deptDeleteTarget.value;
    if (!dept) return '';
    const childCount = localDepartments.value.filter((d) => (d.parent_id ?? null) === dept.id).length;
    const note =
        childCount > 0
            ? `\n\nВнимание: у отдела есть ${childCount} дочерних отделов — они станут корневыми (не удаляются).`
            : '';
    return `Удалить отдел «${dept.name}»? У пользователей поле отдела будет очищено.${note}`;
});

async function confirmRemoveDepartment(): Promise<void> {
    const dept = deptDeleteTarget.value;
    if (!dept) return;
    deptDeleteBusy.value = true;
    try {
        await axios.delete(route('settings.departments.destroy', dept.id));
        deptDeleteOpen.value = false;
        deptDeleteTarget.value = null;
        router.reload({ only: ['departments', 'users'] });
    } catch (err: unknown) {
        showToast({ message: fallbackErrorMessage(err), type: 'warning' });
    } finally {
        deptDeleteBusy.value = false;
    }
}

function indentStyle(depth: number): Record<string, string> {
    return depth > 0
        ? { paddingLeft: `${depth * 1.5 + 1.25}rem` }
        : { paddingLeft: '1.25rem' };
}

/**
 * Названия других отделов пользователя (без редактируемого) — нужны для подписи
 * "Также состоит в: A, B" в чек-листе. Из плоских данных — ищем по id.
 */
/**
 * Сводка по подключённым воронкам отдела для бейджей в карточке:
 * `{ id, name, color, stagesPicked, stagesTotal }`. Сортировка — по позиции
 * воронки (как на странице «Воронки продаж»).
 */
function funnelBadgesFor(dept: DepartmentRow): Array<{ id: number; name: string; color: string; stagesPicked: number; stagesTotal: number }> {
    const ids = new Set((dept.funnel_ids ?? []).map((x) => Number(x)));
    if (ids.size === 0) return [];
    const pickedStages = new Set((dept.funnel_stage_ids ?? []).map((x) => Number(x)));
    const out: Array<{ id: number; name: string; color: string; stagesPicked: number; stagesTotal: number }> = [];
    for (const f of localFunnels.value) {
        if (!ids.has(f.id)) continue;
        let picked = 0;
        for (const s of f.stages) if (pickedStages.has(s.id)) picked++;
        out.push({
            id: f.id,
            name: f.name,
            color: f.color,
            stagesPicked: picked,
            stagesTotal: f.stages.length,
        });
    }
    return out;
}

function otherDeptNamesFor(u: AssignmentUser): string[] {
    const otherIds = (u.department_ids ?? [])
        .filter((id) => id !== editingId.value)
        .map((id) => Number(id));
    if (otherIds.length === 0) return [];
    return localDepartments.value
        .filter((d) => otherIds.includes(d.id))
        .map((d) => d.name);
}
</script>

<template>
    <Head title="Отделы" />
    <SettingsLayout title="Отделы" subtitle="Структура компании и распределение операторов">
        <template #actions>
            <button
                type="button"
                class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                :style="{ background: 'var(--ui-accent)', color: '#fff' }"
                @click="openCreate(null)"
            >
                + Отдел
            </button>
        </template>

        <div class="w-full px-6 py-6 space-y-4">
            <p class="text-sm text-[var(--ui-text-secondary)] max-w-3xl">
                Пользователь может состоять в нескольких отделах. Отделы могут быть вложенными — например,
                «Продажи» → «B2B» → «Регион Алматы». Дочерние отделы наследуют логику родителя
                только визуально (для группировки в списках); назначение пользователей и чатов
                остаётся независимым.
            </p>

            <div
                class="ui-filter-panel ui-filter-panel--departments"
            >
                <input
                    v-model="departmentSearch"
                    type="search"
                    class="settings-input"
                    placeholder="Поиск по отделу, описанию, сотруднику, воронке"
                />
                <select v-model="departmentStatusFilter" class="settings-input">
                    <option value="all">Любой статус</option>
                    <option value="active">Активные</option>
                    <option value="inactive">Неактивные</option>
                </select>
                <select v-model="departmentTypeFilter" class="settings-input">
                    <option value="all">Все уровни</option>
                    <option value="root">Только корневые</option>
                    <option value="nested">Только подотделы</option>
                </select>
                <select v-model="departmentMembersFilter" class="settings-input">
                    <option value="all">Любой состав</option>
                    <option value="with_users">Есть сотрудники</option>
                    <option value="empty">Без сотрудников</option>
                </select>
                <button
                    type="button"
                    class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95 disabled:opacity-50"
                    :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                    :disabled="!hasDepartmentFilters"
                    @click="resetDepartmentFilters"
                >
                    Сбросить
                </button>
            </div>

            <div class="flex items-center justify-between gap-3 text-xs text-[var(--ui-text-secondary)]">
                <span>Показано {{ visibleDepartmentTree.length }} из {{ localDepartments.length }}</span>
                <span v-if="hasDepartmentFilters">Фильтр активен</span>
            </div>

            <div
                class="rounded-lg border overflow-hidden"
                :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border)' }"
            >
                <div v-if="visibleDepartmentTree.length === 0" class="p-10 text-center text-[var(--ui-text-secondary)]">
                    {{ hasDepartmentFilters ? 'Отделы не найдены по текущему фильтру.' : 'Нет отделов. Нажмите «+ Отдел».' }}
                </div>
                <div
                    v-for="node in visibleDepartmentTree"
                    :key="node.dept.id"
                    class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 py-4 pr-5 border-b last:border-b-0"
                    :style="{ borderColor: 'var(--ui-border)', ...indentStyle(node.depth) }"
                >
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span
                                v-if="node.depth > 0"
                                class="text-[var(--ui-text-muted)] select-none"
                                aria-hidden="true"
                                title="Дочерний отдел"
                            >└─</span>
                            <h3 class="font-medium text-[var(--ui-text)] truncate">{{ node.dept.name }}</h3>
                            <span
                                v-if="node.dept.is_active === false"
                                class="text-[10px] uppercase tracking-wide px-2 py-0.5 rounded text-[var(--ui-text-muted)]"
                                style="background: color-mix(in srgb, var(--ui-text-muted) 16%, transparent)"
                            >Неактивен</span>
                            <span
                                v-if="node.depth > 0"
                                class="text-[10px] uppercase tracking-wide px-2 py-0.5 rounded text-[var(--ui-text-secondary)]"
                                style="background: color-mix(in srgb, var(--ui-text-secondary) 14%, transparent)"
                            >Уровень {{ node.depth + 1 }}</span>
                        </div>
                        <p v-if="node.dept.description" class="text-xs text-[var(--ui-text-secondary)] mt-0.5 line-clamp-2">
                            {{ node.dept.description }}
                        </p>
                        <p class="text-xs text-[var(--ui-text-secondary)] mt-1">
                            {{ node.dept.users_count }} {{ node.dept.users_count === 1 ? 'пользователь' : 'пользователей' }}
                            <template v-if="node.dept.users?.length">
                                ·
                                <span class="text-[var(--ui-text)]">{{ node.dept.users.map((u) => u.name).join(', ') }}</span>
                            </template>
                        </p>
                        <div
                            v-if="(node.dept.funnel_ids?.length ?? 0) > 0"
                            class="mt-1 flex flex-wrap gap-1"
                        >
                            <span
                                v-for="f in funnelBadgesFor(node.dept)"
                                :key="f.id"
                                class="inline-flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded-full"
                                :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                                :title="`${f.name}: этапов ${f.stagesPicked} из ${f.stagesTotal}`"
                            >
                                <span
                                    class="w-1.5 h-1.5 rounded-full"
                                    :style="{ background: f.color }"
                                ></span>
                                {{ f.name }}
                                <span class="text-[var(--ui-text-secondary)]">({{ f.stagesPicked }}/{{ f.stagesTotal }})</span>
                            </span>
                        </div>
                        <p
                            v-if="scheduleSummary(node.dept)"
                            class="text-[11px] text-[var(--ui-text-secondary)] mt-1"
                            title="Рабочий график для автоответов вне смены"
                        >
                            График: {{ scheduleSummary(node.dept) }}
                            <span v-if="node.dept.work_schedule_timezone" class="opacity-80">
                                ({{ node.dept.work_schedule_timezone }})
                            </span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button
                            type="button"
                            class="px-3 py-1.5 text-sm rounded-lg text-[var(--ui-text-secondary)] hover:bg-[var(--ui-surface-hover)]"
                            title="Создать дочерний отдел"
                            @click="openCreate(node.dept.id)"
                        >
                            + Подотдел
                        </button>
                        <button
                            type="button"
                            class="px-3 py-1.5 text-sm rounded-lg border hover:bg-[var(--ui-surface-hover)]"
                            :style="{ borderColor: 'var(--ui-border-strong)', color: 'var(--ui-text)' }"
                            @click="openEdit(node.dept)"
                        >
                            Редактировать
                        </button>
                        <button
                            type="button"
                            class="px-3 py-1.5 text-sm rounded-lg text-red-400 border border-red-500/30 hover:bg-red-500/10"
                            @click="requestRemoveDepartment(node.dept)"
                        >
                            Удалить
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div
                v-if="modalOpen"
                class="fixed inset-0 z-[2000] flex items-center justify-center p-4 bg-black/60"
                role="dialog"
                aria-modal="true"
                :aria-label="editingId === null ? 'Новый отдел' : 'Редактировать отдел'"
                @click.self="closeModal"
            >
                <div
                    class="w-full max-w-lg max-h-[min(90vh,720px)] overflow-hidden flex flex-col rounded-xl border shadow-2xl"
                    :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border-strong)' }"
                    @click.stop
                >
                    <div class="px-5 py-4 border-b shrink-0" :style="{ borderColor: 'var(--ui-border)' }">
                        <h3 class="text-base font-medium text-[var(--ui-text)]">
                            {{ editingId === null ? 'Новый отдел' : 'Редактировать отдел' }}
                        </h3>
                    </div>

                    <div class="flex-1 overflow-y-auto wa-scrollbar px-5 py-4 space-y-4">
                        <div>
                            <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">Название</label>
                            <input
                                v-model="form.name"
                                type="text"
                                class="settings-input"
                                :class="{ 'settings-input-error': validationErrors.name }"
                                maxlength="255"
                            />
                            <p v-if="validationErrors.name" class="text-xs text-red-400 mt-1 whitespace-pre-line">
                                {{ validationErrors.name }}
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">
                                Родительский отдел
                            </label>
                            <select
                                v-model="form.parent_id"
                                class="settings-input"
                                :class="{ 'settings-input-error': validationErrors.parent_id }"
                            >
                                <option :value="null">— Нет (корневой отдел) —</option>
                                <option
                                    v-for="node in eligibleParents"
                                    :key="node.dept.id"
                                    :value="node.dept.id"
                                >
                                    {{ '— '.repeat(node.depth) }}{{ node.dept.name }}
                                </option>
                            </select>
                            <p v-if="validationErrors.parent_id" class="text-xs text-red-400 mt-1 whitespace-pre-line">
                                {{ validationErrors.parent_id }}
                            </p>
                            <p class="text-[11px] text-[var(--ui-text-secondary)] mt-1">
                                В списке нельзя выбрать сам редактируемый отдел и его дочерние — это создаст цикл.
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">Описание</label>
                            <textarea
                                v-model="form.description"
                                rows="3"
                                class="settings-input resize-y min-h-[4rem]"
                                maxlength="1000"
                            />
                        </div>

                        <label class="flex items-center gap-2 cursor-pointer">
                            <UiCheckbox v-model="form.is_active" />
                            <span class="text-sm text-[var(--ui-text)]">Отдел активен (участвует в чатах и списках)</span>
                        </label>

                        <div class="pt-2 border-t" :style="{ borderColor: 'var(--ui-border)' }">
                            <div class="flex rounded-xl p-1 mb-4" :style="{ background: 'var(--ui-surface-muted)' }">
                                <button
                                    type="button"
                                    class="flex-1 rounded-lg px-3 py-2 text-sm font-medium transition"
                                    :style="{
                                        background: modalTab === 'members' ? 'var(--ui-accent)' : 'transparent',
                                        color: modalTab === 'members' ? '#fff' : 'var(--ui-text)',
                                    }"
                                    @click="modalTab = 'members'"
                                >
                                    Сотрудники отдела
                                    <span class="opacity-80">({{ selectedMemberIds.length }})</span>
                                </button>
                                <button
                                    type="button"
                                    class="flex-1 rounded-lg px-3 py-2 text-sm font-medium transition"
                                    :style="{
                                        background: modalTab === 'funnels' ? 'var(--ui-accent)' : 'transparent',
                                        color: modalTab === 'funnels' ? '#fff' : 'var(--ui-text)',
                                    }"
                                    @click="modalTab = 'funnels'"
                                >
                                    Воронки продаж
                                    <span class="opacity-80">({{ selectedFunnelIds.size }})</span>
                                </button>
                                <button
                                    type="button"
                                    class="flex-1 rounded-lg px-3 py-2 text-sm font-medium transition"
                                    :style="{
                                        background: modalTab === 'schedule' ? 'var(--ui-accent)' : 'transparent',
                                        color: modalTab === 'schedule' ? '#fff' : 'var(--ui-text)',
                                    }"
                                    @click="modalTab = 'schedule'"
                                >
                                    График
                                </button>
                            </div>
                        </div>

                        <div v-if="modalTab === 'schedule'" class="pt-2 space-y-4">
                            <p class="text-xs text-[var(--ui-text-secondary)]">
                                Вне рабочего времени бот вежливо сообщит клиенту, что отдел не на связи, и когда ждать ответ.
                                Пока график выключен — отдел считается доступным круглосуточно.
                            </p>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <UiCheckbox v-model="form.work_schedule_enabled" />
                                <span class="text-sm text-[var(--ui-text)]">Использовать рабочий график для автоответов</span>
                            </label>
                            <div v-if="form.work_schedule_enabled" class="space-y-3">
                                <div>
                                    <label class="block text-sm text-[var(--ui-text-secondary)] mb-1">Часовой пояс</label>
                                    <select v-model="form.work_schedule_timezone" class="settings-input">
                                        <option v-for="tz in TIMEZONE_OPTIONS" :key="tz" :value="tz">{{ tz }}</option>
                                    </select>
                                </div>
                                <div
                                    class="rounded-lg border divide-y"
                                    :style="{ borderColor: 'var(--ui-border)' }"
                                >
                                    <div
                                        v-for="day in WEEK_DAYS"
                                        :key="day.key"
                                        class="flex flex-wrap items-center gap-3 px-3 py-2"
                                    >
                                        <label class="flex items-center gap-2 min-w-[9.5rem] cursor-pointer">
                                            <UiCheckbox v-model="form.work_schedule[day.key].enabled" />
                                            <span class="text-sm text-[var(--ui-text)]">{{ day.label }}</span>
                                        </label>
                                        <input
                                            v-model="form.work_schedule[day.key].from"
                                            type="time"
                                            class="settings-input w-[7.5rem]"
                                            :disabled="!form.work_schedule[day.key].enabled"
                                        />
                                        <span class="text-[var(--ui-text-secondary)] text-sm">—</span>
                                        <input
                                            v-model="form.work_schedule[day.key].to"
                                            type="time"
                                            class="settings-input w-[7.5rem]"
                                            :disabled="!form.work_schedule[day.key].enabled"
                                        />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div v-if="modalTab === 'funnels'" class="pt-2">
                            <p class="text-sm font-medium text-[var(--ui-text)] mb-1">Воронки продаж</p>
                            <p class="text-xs text-[var(--ui-text-secondary)] mb-3">
                                Подключите одну или несколько воронок и отметьте этапы, с которыми
                                работает этот отдел. По умолчанию при включении воронки выбираются
                                все её этапы — снимите ненужные.
                            </p>

                            <div
                                v-if="localFunnels.length === 0"
                                class="text-xs text-[var(--ui-text-secondary)] italic"
                            >
                                Воронок пока нет. Создайте их в разделе «Воронки продаж».
                            </div>

                            <div
                                v-else
                                class="rounded-lg border divide-y wa-scrollbar overflow-y-auto max-h-72"
                                :style="{ borderColor: 'var(--ui-border)' }"
                            >
                                <div
                                    v-for="funnel in localFunnels"
                                    :key="funnel.id"
                                    class="px-3 py-2"
                                    :style="{ borderColor: 'var(--ui-border)' }"
                                >
                                    <div
                                        class="flex items-center gap-3 cursor-pointer"
                                        role="button"
                                        tabindex="0"
                                        @click="toggleFunnel(funnel)"
                                        @keydown.enter.prevent="toggleFunnel(funnel)"
                                    >
                                        <UiCheckbox
                                            :model-value="isFunnelChecked(funnel)"
                                            :aria-label="`Воронка ${funnel.name}`"
                                            @update:model-value="toggleFunnel(funnel)"
                                            @click.stop
                                        />
                                        <span
                                            class="w-2.5 h-2.5 rounded-full shrink-0"
                                            :style="{ background: funnel.color }"
                                        ></span>
                                        <span class="text-sm text-[var(--ui-text)] truncate flex-1">
                                            {{ funnel.name }}
                                            <span
                                                v-if="!funnel.is_active"
                                                class="text-[10px] px-1.5 py-0.5 rounded bg-red-500/10 text-red-400 ml-1"
                                            >неактивна</span>
                                        </span>
                                        <span
                                            v-if="isFunnelChecked(funnel) && funnel.stages.length > 0"
                                            class="text-[11px] text-[var(--ui-text-secondary)] shrink-0"
                                        >
                                            этапов: {{ selectedStageCount(funnel) }} / {{ funnel.stages.length }}
                                        </span>
                                    </div>

                                    <div
                                        v-if="isFunnelChecked(funnel)"
                                        class="pl-7 mt-2 space-y-1"
                                    >
                                        <div
                                            v-if="funnel.stages.length === 0"
                                            class="text-[11px] text-[var(--ui-text-secondary)] italic"
                                        >
                                            У воронки нет этапов — добавьте их в «Воронках продаж».
                                        </div>
                                        <div
                                            v-for="stage in funnel.stages"
                                            :key="stage.id"
                                            class="flex items-center gap-2 cursor-pointer text-xs"
                                            role="button"
                                            tabindex="0"
                                            :style="{ opacity: stage.is_active === false ? 0.6 : 1 }"
                                            @click="toggleStage(funnel, stage)"
                                            @keydown.enter.prevent="toggleStage(funnel, stage)"
                                        >
                                            <UiCheckbox
                                                size="sm"
                                                :model-value="isStageChecked(stage)"
                                                :aria-label="`Этап ${stage.name}`"
                                                @update:model-value="toggleStage(funnel, stage)"
                                                @click.stop
                                            />
                                            <span
                                                class="w-2 h-2 rounded-full shrink-0"
                                                :style="{ background: stage.color }"
                                            ></span>
                                            <span class="text-[var(--ui-text)] truncate">{{ stage.name }}</span>
                                            <span
                                                v-if="stage.is_active === false"
                                                class="text-[10px] text-[var(--ui-text-secondary)]"
                                            >(неактивен)</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <p
                                v-if="validationErrors.funnel_stage_ids"
                                class="text-xs text-red-400 mt-1 whitespace-pre-line"
                            >
                                {{ validationErrors.funnel_stage_ids }}
                            </p>
                        </div>

                        <div v-if="modalTab === 'members'" class="pt-2">
                            <p class="text-sm font-medium text-[var(--ui-text)] mb-1">Сотрудники отдела</p>
                            <p class="text-xs text-[var(--ui-text-secondary)] mb-3">
                                Отмеченные пользователи входят в этот отдел. Один сотрудник может состоять
                                одновременно в нескольких отделах.
                            </p>

                            <div v-if="showMemberSearch" class="mb-3">
                                <input
                                    v-model="memberSearch"
                                    type="search"
                                    placeholder="Поиск по имени или email…"
                                    class="settings-input"
                                    autocomplete="off"
                                />
                            </div>

                            <div class="max-h-52 overflow-y-auto rounded-lg border wa-scrollbar" :style="{ borderColor: 'var(--ui-border)' }">
                                <div
                                    v-for="u in filteredUsersForPicker"
                                    :key="u.id"
                                    class="flex items-start gap-3 px-3 py-2 border-b last:border-b-0 cursor-pointer hover:brightness-95"
                                    role="button"
                                    tabindex="0"
                                    :style="{
                                        borderColor: 'var(--ui-border)',
                                        opacity: u.is_active ? 1 : 0.55,
                                    }"
                                    @click="toggleMember(u.id)"
                                    @keydown.enter.prevent="toggleMember(u.id)"
                                >
                                    <UiCheckbox
                                        class="mt-0.5"
                                        :model-value="selectedMemberIds.includes(u.id)"
                                        :aria-label="`Сотрудник ${u.name}`"
                                        @update:model-value="toggleMember(u.id)"
                                        @click.stop
                                    />
                                    <div class="min-w-0 flex-1">
                                        <div class="text-sm text-[var(--ui-text)] truncate">{{ u.name }}</div>
                                        <div class="text-xs text-[var(--ui-text-secondary)] truncate">{{ u.email }}</div>
                                        <div
                                            v-if="otherDeptNamesFor(u).length > 0"
                                            class="text-[10px] mt-0.5"
                                            :style="{ color: 'var(--ui-text-secondary)' }"
                                        >
                                            Также состоит в: {{ otherDeptNamesFor(u).join(', ') }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        class="flex justify-end gap-2 px-5 py-3 border-t shrink-0"
                        :style="{ borderColor: 'var(--ui-border)' }"
                    >
                        <button
                            type="button"
                            class="ui-btn ui-btn--secondary"
                            @click="closeModal"
                        >
                            Отмена
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--primary"
                            :disabled="saving"
                            @click="saveModal"
                        >
                            {{ saving ? 'Сохранение…' : (editingId === null ? 'Создать' : 'Сохранить') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </SettingsLayout>

    <DangerConfirmModal
        :open="deptDeleteOpen"
        title="Удалить отдел?"
        :description="deptDeleteDescription"
        confirm-label="Удалить"
        :busy="deptDeleteBusy"
        confirm-variant="danger"
        @close="closeDeptDelete"
        @confirm="confirmRemoveDepartment"
    />
</template>

