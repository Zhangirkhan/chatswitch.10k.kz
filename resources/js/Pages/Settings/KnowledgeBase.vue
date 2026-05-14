<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { useToastStore } from '@/stores/toast';

type Section = 'products' | 'services' | 'rules';

type CompanyOption = {
    id: number;
    name: string;
};

type KnowledgeItem = {
    id: number;
    company_id: number;
    company?: CompanyOption;
    name?: string;
    title?: string;
    sku?: string | null;
    description?: string | null;
    content?: string | null;
    price?: string | number | null;
    duration_minutes?: number | null;
    type?: string | null;
    priority?: number | null;
    attributes?: Record<string, unknown> | null;
    conditions?: Record<string, unknown> | null;
    is_active: boolean;
    include_in_prompt: boolean;
    sort_order?: number;
};

const props = defineProps<{
    section: Section;
    items: KnowledgeItem[];
    companies: CompanyOption[];
}>();

const { show: showToast } = useToastStore();
const localItems = ref<KnowledgeItem[]>([...props.items]);
const editing = ref<KnowledgeItem | null>(null);
const showForm = ref(false);
const detailsText = ref('');
const form = ref({
    company_id: (props.companies[0]?.id ?? null) as number | null,
    name: '',
    title: '',
    sku: '',
    description: '',
    content: '',
    price: '',
    duration_minutes: '',
    type: 'general',
    priority: 100,
    sort_order: 0,
    is_active: true,
    include_in_prompt: true,
});

watch(
    () => props.items,
    (items) => {
        localItems.value = [...items];
    },
    { deep: true },
);

const meta = computed(() => ({
    products: {
        title: 'Товары',
        subtitle: 'Каталог товаров, которые AI может учитывать в промпте',
        addLabel: 'Добавить товар',
        route: 'settings.knowledge.products',
        store: 'settings.knowledge.products.store',
        update: 'settings.knowledge.products.update',
        destroy: 'settings.knowledge.products.destroy',
        bulkPrompt: 'settings.knowledge.products.bulk-prompt',
    },
    services: {
        title: 'Услуги',
        subtitle: 'Услуги, длительность, цены и условия',
        addLabel: 'Добавить услугу',
        route: 'settings.knowledge.services',
        store: 'settings.knowledge.services.store',
        update: 'settings.knowledge.services.update',
        destroy: 'settings.knowledge.services.destroy',
        bulkPrompt: 'settings.knowledge.services.bulk-prompt',
    },
    rules: {
        title: 'База знаний',
        subtitle: 'Правила ответа: часы работы, ограничения, тон и политика AI',
        addLabel: 'Добавить правило',
        route: 'settings.knowledge.rules',
        store: 'settings.knowledge.rules.store',
        update: 'settings.knowledge.rules.update',
        destroy: 'settings.knowledge.rules.destroy',
        bulkPrompt: 'settings.knowledge.rules.bulk-prompt',
    },
}[props.section]));

type PreviewCounts = {
    rules: number;
    products: number;
    services: number;
};

const previewCompanyId = ref<number | null>(props.companies[0]?.id ?? null);
const previewOpen = ref(false);
const previewLoading = ref(false);
const previewText = ref('');
const previewTruncated = ref(false);
const previewCounts = ref<PreviewCounts | null>(null);
const previewHint = ref('');
const testQuestion = ref('');
const testQuestionResult = ref('');

type AuditRow = {
    id: number;
    action: string;
    entity_type: string;
    entity_id: number;
    entity_label: string | null;
    changes: Record<string, unknown> | null;
    created_at: string;
    user?: { id: number; name: string } | null;
};

const auditOpen = ref(false);
const auditLoading = ref(false);
const auditEntries = ref<AuditRow[]>([]);
const deleteTarget = ref<KnowledgeItem | null>(null);
const deleteConfirmInput = ref('');

const auditEntityType = computed(() =>
    props.section === 'products' ? 'product' : props.section === 'services' ? 'service' : 'rule',
);

const selectedIds = ref<number[]>([]);

watch(
    () => props.companies,
    (companies) => {
        if (previewCompanyId.value == null && companies[0]) {
            previewCompanyId.value = companies[0].id;
        }
    },
    { deep: true },
);

watch(
    () => localItems.value.map((i) => i.id).join(','),
    () => {
        const allowed = new Set(localItems.value.map((i) => i.id));
        selectedIds.value = selectedIds.value.filter((id) => allowed.has(id));
    },
);

const allSelected = computed(
    () => localItems.value.length > 0 && localItems.value.every((i) => selectedIds.value.includes(i.id)),
);

const emptyCrossLinks = computed((): { label: string; href: string }[] => {
    if (props.section === 'products') {
        return [
            { label: 'Услуги', href: route('settings.knowledge.services') },
            { label: 'Правила ответа', href: route('settings.knowledge.rules') },
        ];
    }
    if (props.section === 'services') {
        return [
            { label: 'Товары', href: route('settings.knowledge.products') },
            { label: 'Правила ответа', href: route('settings.knowledge.rules') },
        ];
    }

    return [
        { label: 'Товары', href: route('settings.knowledge.products') },
        { label: 'Услуги', href: route('settings.knowledge.services') },
    ];
});

const promptReadyItems = computed(() => localItems.value.filter((item) => item.is_active && item.include_in_prompt));
const readinessChecks = computed(() => [
    {
        label: 'Есть компания',
        ok: props.companies.length > 0,
        hint: props.companies.length > 0 ? 'Можно собирать контекст по компании.' : 'Создайте компанию или назначьте записи компании.',
    },
    {
        label: 'Есть активные записи в промпте',
        ok: promptReadyItems.value.length > 0,
        hint: promptReadyItems.value.length > 0 ? `В промпт попадёт записей: ${promptReadyItems.value.length}.` : 'Включите хотя бы одну активную запись в промпт.',
    },
    {
        label: 'Предпросмотр доступен',
        ok: previewCompanyId.value !== null,
        hint: previewCompanyId.value !== null ? 'Можно проверить, что увидит AI.' : 'Выберите компанию для предпросмотра.',
    },
]);
const dataWarnings = computed(() => {
    const warnings: string[] = [];
    if (localItems.value.length === 0) {
        warnings.push('В разделе пока нет записей.');
    }
    if (localItems.value.some((item) => item.is_active && !item.include_in_prompt)) {
        warnings.push('Есть активные записи, которые не попадают в AI-промпт.');
    }
    if (props.section === 'rules' && localItems.value.some((item) => !String(item.content ?? '').trim())) {
        warnings.push('У части правил не заполнен текст правила.');
    }
    if (props.section !== 'rules' && localItems.value.some((item) => !String(item.description ?? '').trim())) {
        warnings.push('У части записей не заполнено описание, AI может отвечать слишком общо.');
    }
    if (props.section !== 'rules' && localItems.value.some((item) => item.price === null || item.price === undefined || item.price === '')) {
        warnings.push('У части товаров/услуг нет цены. Если цену нельзя называть, укажите это в описании.');
    }

    return warnings;
});

function toggleSelectAll(): void {
    if (localItems.value.length === 0) {
        return;
    }
    if (allSelected.value) {
        selectedIds.value = [];
        return;
    }
    selectedIds.value = localItems.value.map((i) => i.id);
}

function toggleRowSelection(id: number): void {
    const set = new Set(selectedIds.value);
    if (set.has(id)) {
        set.delete(id);
    } else {
        set.add(id);
    }
    selectedIds.value = Array.from(set);
}

function clearSelection(): void {
    selectedIds.value = [];
}

async function loadPreview(): Promise<void> {
    if (previewCompanyId.value == null) {
        showToast({ message: 'Выберите компанию', duration: 3000 });
        return;
    }
    previewLoading.value = true;
    try {
        const { data } = await axios.get(route('settings.knowledge.prompt-preview'), {
            params: { company_id: previewCompanyId.value },
        });
        previewText.value = String(data.text ?? '');
        previewTruncated.value = Boolean(data.truncated);
        previewCounts.value = data.counts as PreviewCounts;
        previewHint.value = String(data.hint ?? '');
        previewOpen.value = true;
    } catch (error: any) {
        showToast({
            message: error?.response?.data?.message || error?.message || 'Не удалось загрузить предпросмотр',
            duration: 4000,
        });
    } finally {
        previewLoading.value = false;
    }
}

function closePreview(): void {
    previewOpen.value = false;
}

async function runTestQuestion(): Promise<void> {
    const question = testQuestion.value.trim();
    if (question === '') {
        testQuestionResult.value = 'Введите вопрос, который клиент может задать AI.';
        return;
    }

    if (previewText.value.trim() === '') {
        await loadPreview();
    }

    const context = previewText.value.toLowerCase();
    const keywords = question
        .toLowerCase()
        .split(/[^\p{L}\p{N}]+/u)
        .filter((word) => word.length >= 4);
    const matched = keywords.filter((word) => context.includes(word));

    if (matched.length > 0) {
        testQuestionResult.value = `В контексте AI найдены совпадения: ${matched.slice(0, 5).join(', ')}. Проверьте предпросмотр перед сохранением.`;
        return;
    }

    testQuestionResult.value = 'В текущем AI-контексте нет явных совпадений по вопросу. Добавьте товар, услугу или правило с нужными формулировками.';
}

async function bulkSetPrompt(include: boolean): Promise<void> {
    if (selectedIds.value.length === 0) {
        return;
    }
    try {
        const { data } = await axios.post(route(meta.value.bulkPrompt), {
            ids: selectedIds.value,
            include_in_prompt: include,
        });
        const items = data.items as KnowledgeItem[];
        const map = new Map(items.map((item) => [item.id, item]));
        localItems.value = localItems.value.map((row) => map.get(row.id) ?? row);
        showToast({ message: 'Колонка «В промпте» обновлена', duration: 3000 });
        selectedIds.value = [];
    } catch (error: any) {
        showToast({
            message: error?.response?.data?.message || error?.message || 'Не удалось обновить записи',
            duration: 4000,
        });
    }
}

function resetForm(): void {
    editing.value = null;
    form.value = {
        company_id: props.companies[0]?.id ?? null,
        name: '',
        title: '',
        sku: '',
        description: '',
        content: '',
        price: '',
        duration_minutes: '',
        type: 'general',
        priority: 100,
        sort_order: 0,
        is_active: true,
        include_in_prompt: true,
    };
    detailsText.value = '';
}

function openAdd(): void {
    resetForm();
    showForm.value = true;
}

function openEdit(item: KnowledgeItem): void {
    editing.value = item;
    form.value = {
        company_id: item.company_id,
        name: item.name ?? '',
        title: item.title ?? '',
        sku: item.sku ?? '',
        description: item.description ?? '',
        content: item.content ?? '',
        price: item.price != null ? String(item.price) : '',
        duration_minutes: item.duration_minutes != null ? String(item.duration_minutes) : '',
        type: item.type ?? 'general',
        priority: item.priority ?? 100,
        sort_order: item.sort_order ?? 0,
        is_active: item.is_active,
        include_in_prompt: item.include_in_prompt,
    };
    detailsText.value = detailsObjectToText(props.section === 'services' ? (item.conditions ?? {}) : (item.attributes ?? {}));
    showForm.value = true;
}

function payload(): Record<string, unknown> {
    const detailsPayload = detailsTextToObject(detailsText.value);

    const base: Record<string, unknown> = {
        company_id: form.value.company_id,
        price: form.value.price !== '' ? form.value.price : null,
        is_active: form.value.is_active,
        include_in_prompt: form.value.include_in_prompt,
    };

    if (props.section === 'rules') {
        return {
            ...base,
            title: form.value.title.trim(),
            type: form.value.type.trim() || 'general',
            content: form.value.content.trim(),
            priority: form.value.priority,
        };
    }

    if (props.section === 'services') {
        return {
            ...base,
            name: form.value.name.trim(),
            description: form.value.description.trim() || null,
            duration_minutes: form.value.duration_minutes !== '' ? Number(form.value.duration_minutes) : null,
            conditions: detailsPayload,
            sort_order: form.value.sort_order,
        };
    }

    return {
        ...base,
        name: form.value.name.trim(),
        sku: form.value.sku.trim() || null,
        description: form.value.description.trim() || null,
        attributes: detailsPayload,
        sort_order: form.value.sort_order,
    };
}

async function save(): Promise<void> {
    try {
        const data = payload();
        const response = editing.value
            ? await axios.put(route(meta.value.update, editing.value.id), data)
            : await axios.post(route(meta.value.store), data);
        const item = response.data.item as KnowledgeItem;
        if (editing.value) {
            localItems.value = localItems.value.map((existing) => existing.id === item.id ? item : existing);
        } else {
            localItems.value = [item, ...localItems.value];
        }
        showForm.value = false;
        showToast({ message: 'Запись сохранена', duration: 3000 });
    } catch (error: any) {
        showToast({ message: error?.response?.data?.message || error?.message || 'Не удалось сохранить запись', duration: 4000 });
    }
}

async function destroyItem(item: KnowledgeItem): Promise<void> {
    openDeleteModal(item);
}

async function togglePrompt(item: KnowledgeItem): Promise<void> {
    const data = {
        ...item,
        name: item.name ?? item.title,
        title: item.title ?? item.name,
        include_in_prompt: !item.include_in_prompt,
    };
    const response = await axios.put(route(meta.value.update, item.id), data);
    const updated = response.data.item as KnowledgeItem;
    localItems.value = localItems.value.map((existing) => existing.id === updated.id ? updated : existing);
}

function itemTitle(item: KnowledgeItem): string {
    return item.name || item.title || `#${item.id}`;
}

function formatTenge(price: KnowledgeItem['price']): string {
    if (price === null || price === undefined || price === '') {
        return '—';
    }

    const value = Number(price);
    if (!Number.isFinite(value)) {
        return `${price} ₸`;
    }

    return `${new Intl.NumberFormat('ru-KZ', { maximumFractionDigits: 2 }).format(value)} ₸`;
}

const detailsPlaceholder = computed(() => {
    if (props.section === 'services') {
        return 'место: салон\nпредоплата: не требуется\nограничение: только по записи';
    }

    return 'цвет: черный\nразмер: 42\nматериал: кожа';
});

function detailsTextToObject(value: string): Record<string, unknown> | null {
    const trimmed = value.trim();
    if (trimmed === '' || trimmed === '{}') {
        return null;
    }

    if (trimmed.startsWith('{')) {
        try {
            return JSON.parse(trimmed) as Record<string, unknown>;
        } catch {
            throw new Error('Проверьте блок "Дополнительно": JSON написан с ошибкой. Можно просто заполнить строками вида "цвет: черный".');
        }
    }

    const result: Record<string, unknown> = {};
    const notes: string[] = [];

    trimmed
        .split(/\r?\n/)
        .map((line) => line.trim().replace(/^[-•]\s*/u, ''))
        .filter(Boolean)
        .forEach((line) => {
            const match = /^([^:=—-]+)\s*[:=—-]\s*(.+)$/u.exec(line);
            if (!match) {
                notes.push(line);
                return;
            }

            const key = match[1]?.trim();
            const rawValue = match[2]?.trim();
            if (!key || !rawValue) {
                notes.push(line);
                return;
            }

            result[key] = rawValue;
        });

    if (notes.length > 0) {
        result['заметки'] = notes.length === 1 ? notes[0] : notes;
    }

    return Object.keys(result).length > 0 ? result : null;
}

function detailsObjectToText(value: Record<string, unknown> | null): string {
    if (!value || Object.keys(value).length === 0) {
        return '';
    }

    return Object.entries(value)
        .map(([key, entry]) => {
            if (Array.isArray(entry)) {
                return `${key}: ${entry.join(', ')}`;
            }
            if (entry !== null && typeof entry === 'object') {
                return `${key}: ${JSON.stringify(entry, null, 2)}`;
            }

            return `${key}: ${String(entry)}`;
        })
        .join('\n');
}

function formatAuditAction(action: string): string {
    const map: Record<string, string> = {
        created: 'Создано',
        updated: 'Изменено',
        deleted: 'Удалено',
        bulk_prompt: 'Массово «В промпте»',
    };

    return map[action] ?? action;
}

function formatAuditWhen(iso: string): string {
    try {
        return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));
    } catch {
        return iso;
    }
}

function summarizeAuditChanges(row: AuditRow): string {
    const c = row.changes;
    if (!c || typeof c !== 'object') {
        return '';
    }

    if (c.diff && typeof c.diff === 'object') {
        return JSON.stringify(c.diff, null, 0);
    }

    return JSON.stringify(c, null, 0);
}

async function loadAudit(): Promise<void> {
    if (previewCompanyId.value == null) {
        return;
    }
    auditLoading.value = true;
    try {
        const { data } = await axios.get(route('settings.knowledge.audit'), {
            params: {
                company_id: previewCompanyId.value,
                entity_type: auditEntityType.value,
                per_page: 30,
            },
        });
        auditEntries.value = (data.data ?? []) as AuditRow[];
    } catch (error: any) {
        showToast({
            message: error?.response?.data?.message || error?.message || 'Не удалось загрузить аудит',
            duration: 4000,
        });
    } finally {
        auditLoading.value = false;
    }
}

async function toggleAudit(): Promise<void> {
    auditOpen.value = !auditOpen.value;
    if (auditOpen.value) {
        await loadAudit();
    }
}

function openDeleteModal(item: KnowledgeItem): void {
    deleteTarget.value = item;
    deleteConfirmInput.value = '';
}

function closeDeleteModal(): void {
    deleteTarget.value = null;
    deleteConfirmInput.value = '';
}

async function confirmDelete(): Promise<void> {
    const item = deleteTarget.value;
    if (!item) {
        return;
    }
    const expected = itemTitle(item).trim();
    if (deleteConfirmInput.value.trim() !== expected) {
        showToast({ message: 'Введите название записи точно, как в списке', duration: 4000 });
        return;
    }

    try {
        await axios.delete(route(meta.value.destroy, item.id));
        localItems.value = localItems.value.filter((existing) => existing.id !== item.id);
        closeDeleteModal();
        showToast({ message: 'Запись удалена', duration: 3000 });
    } catch (error: any) {
        showToast({ message: error?.response?.data?.message || 'Не удалось удалить запись', duration: 4000 });
    }
}
</script>

<template>
    <Head :title="meta.title" />

    <SettingsLayout :title="meta.title" :subtitle="meta.subtitle">
        <template #actions>
            <button type="button" class="px-4 py-2 rounded-lg bg-[var(--wa-green)] text-white text-sm" @click="openAdd">
                {{ meta.addLabel }}
            </button>
        </template>

        <div class="p-6 space-y-4">
            <div class="kb-toolbar flex flex-col gap-3 rounded-xl border border-[var(--wa-border)] bg-[var(--wa-panel)] p-4 md:flex-row md:flex-wrap md:items-end md:justify-between">
                <div class="flex flex-wrap items-end gap-3">
                    <label class="field kb-toolbar-field">
                        <span>Компания для предпросмотра</span>
                        <select v-model.number="previewCompanyId">
                            <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </label>
                    <button
                        type="button"
                        class="px-4 py-2 rounded-lg border border-[var(--wa-border)] text-sm text-[var(--wa-text)]"
                        :disabled="previewCompanyId == null || previewLoading"
                        @click="loadPreview"
                    >
                        {{ previewLoading ? 'Загрузка…' : 'Предпросмотр для AI' }}
                    </button>
                </div>
                <div v-if="selectedIds.length > 0" class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-[var(--wa-text-secondary)]">Выбрано: {{ selectedIds.length }}</span>
                    <button type="button" class="px-3 py-2 rounded-lg bg-[var(--wa-green)] text-white text-sm" @click="bulkSetPrompt(true)">В промпте: да</button>
                    <button type="button" class="px-3 py-2 rounded-lg border border-[var(--wa-border)] text-sm" @click="bulkSetPrompt(false)">В промпте: нет</button>
                    <button type="button" class="link-btn px-2 text-sm" @click="clearSelection">Снять выделение</button>
                </div>
            </div>

            <div class="rounded-xl border border-[var(--wa-border)] bg-[var(--wa-panel)] p-4">
                <button
                    type="button"
                    class="flex w-full items-center justify-between gap-3 text-left"
                    @click="toggleAudit"
                >
                    <div>
                        <h3 class="text-sm font-semibold text-[var(--wa-text)]">История изменений</h3>
                        <p class="text-xs text-[var(--wa-text-secondary)]">
                            Аудит по выбранной компании для этого раздела (создание, правки, удаление, массовое «В промпте»).
                        </p>
                    </div>
                    <span class="text-xs text-[var(--wa-text-secondary)] shrink-0">{{ auditOpen ? '▼' : '▶' }}</span>
                </button>
                <div v-if="auditOpen" class="mt-3 space-y-2">
                    <p v-if="previewCompanyId == null" class="text-xs text-[var(--wa-text-secondary)]">Выберите компанию в блоке выше.</p>
                    <p v-else-if="auditLoading" class="text-xs text-[var(--wa-text-secondary)]">Загрузка…</p>
                    <ul v-else class="wa-scrollbar max-h-72 space-y-2 overflow-y-auto text-xs">
                        <li
                            v-for="row in auditEntries"
                            :key="row.id"
                            class="rounded-lg border border-[var(--wa-border)] bg-[var(--wa-bg)] px-3 py-2"
                        >
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="font-medium text-[var(--wa-text)]">
                                    {{ formatAuditAction(row.action) }}
                                    <span class="font-normal text-[var(--wa-text-secondary)]"> · {{ row.entity_label || '#' + row.entity_id }}</span>
                                </span>
                                <span class="text-[var(--wa-text-secondary)]">{{ formatAuditWhen(row.created_at) }}</span>
                            </div>
                            <div class="mt-0.5 text-[var(--wa-text-secondary)]">{{ row.user?.name ?? '—' }}</div>
                            <pre
                                v-if="summarizeAuditChanges(row)"
                                class="mt-1 max-h-28 overflow-auto rounded bg-black/5 p-2 text-[10px] leading-snug text-[var(--wa-text)]"
                            >{{ summarizeAuditChanges(row) }}</pre>
                        </li>
                    </ul>
                    <p
                        v-if="!auditLoading && previewCompanyId != null && auditEntries.length === 0"
                        class="text-xs text-[var(--wa-text-secondary)]"
                    >
                        Пока нет записей аудита.
                    </p>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-[1.2fr_1fr]">
                <section class="rounded-xl border border-[var(--wa-border)] bg-[var(--wa-panel)] p-4">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--wa-text)]">Готовность AI</h3>
                            <p class="text-xs text-[var(--wa-text-secondary)]">Быстрая проверка, увидит ли AI полезные данные.</p>
                        </div>
                        <span class="rounded-full px-2.5 py-1 text-xs" :class="readinessChecks.every((check) => check.ok) ? 'bg-emerald-500/15 text-emerald-600' : 'bg-amber-500/15 text-amber-600'">
                            {{ readinessChecks.every((check) => check.ok) ? 'Готово' : 'Нужно внимание' }}
                        </span>
                    </div>
                    <div class="space-y-2">
                        <div v-for="check in readinessChecks" :key="check.label" class="flex gap-2 rounded-lg bg-[var(--wa-bg)] px-3 py-2">
                            <span class="mt-0.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="check.ok ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                            <div>
                                <p class="text-sm text-[var(--wa-text)]">{{ check.label }}</p>
                                <p class="text-xs text-[var(--wa-text-secondary)]">{{ check.hint }}</p>
                            </div>
                        </div>
                    </div>
                    <div v-if="dataWarnings.length > 0" class="mt-3 rounded-lg border border-amber-500/25 bg-amber-500/10 p-3">
                        <p class="mb-2 text-xs font-semibold text-amber-700">Предупреждения по данным</p>
                        <ul class="space-y-1 text-xs text-[var(--wa-text)]">
                            <li v-for="warning in dataWarnings" :key="warning">• {{ warning }}</li>
                        </ul>
                    </div>
                </section>

                <section class="rounded-xl border border-[var(--wa-border)] bg-[var(--wa-panel)] p-4">
                    <h3 class="text-sm font-semibold text-[var(--wa-text)]">Тестовый вопрос</h3>
                    <p class="mt-1 text-xs text-[var(--wa-text-secondary)]">
                        Проверьте, есть ли в AI-контексте данные для типичного вопроса клиента.
                    </p>
                    <div class="mt-3 flex gap-2">
                        <input
                            v-model="testQuestion"
                            class="flex-1 rounded-lg border border-[var(--wa-border)] bg-[var(--wa-bg)] px-3 py-2 text-sm text-[var(--wa-text)]"
                            type="text"
                            placeholder="Например: сколько стоит доставка?"
                            @keydown.enter.prevent="runTestQuestion"
                        />
                        <button
                            type="button"
                            class="rounded-lg border border-[var(--wa-border)] px-3 py-2 text-sm text-[var(--wa-text)]"
                            :disabled="previewCompanyId == null || previewLoading"
                            @click="runTestQuestion"
                        >
                            Проверить
                        </button>
                    </div>
                    <p v-if="testQuestionResult" class="mt-3 rounded-lg bg-[var(--wa-bg)] px-3 py-2 text-xs text-[var(--wa-text-secondary)]">
                        {{ testQuestionResult }}
                    </p>
                </section>
            </div>

            <div v-if="localItems.length === 0" class="rounded-xl border border-[var(--wa-border)] bg-[var(--wa-panel)] px-6 py-10 text-center">
                <p class="text-[15px] text-[var(--wa-text)]">Пока нет записей в этом разделе.</p>
                <p class="mx-auto mt-2 max-w-xl text-sm text-[var(--wa-text-secondary)]">
                    Добавьте данные: в промпт попадают только активные записи с включённым «В промпте» — их можно посмотреть кнопкой «Предпросмотр для AI».
                </p>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    <button type="button" class="px-4 py-2 rounded-lg bg-[var(--wa-green)] text-white text-sm" @click="openAdd">{{ meta.addLabel }}</button>
                </div>
                <div class="mt-6 flex flex-wrap justify-center gap-4 text-sm">
                    <Link v-for="link in emptyCrossLinks" :key="link.href" :href="link.href" class="link-btn">{{ link.label }}</Link>
                </div>
            </div>

            <div v-else class="overflow-hidden rounded-xl border border-[var(--wa-border)] bg-[var(--wa-panel)]">
                <table class="w-full text-sm">
                    <thead class="text-left text-[var(--wa-text-secondary)] border-b border-[var(--wa-border)]">
                        <tr>
                            <th class="w-10 px-3 py-3">
                                <input type="checkbox" class="kb-checkbox" :checked="allSelected" @click.prevent="toggleSelectAll" />
                            </th>
                            <th class="px-4 py-3">Название</th>
                            <th class="px-4 py-3">Компания</th>
                            <th class="px-4 py-3">Цена, ₸</th>
                            <th class="px-4 py-3">В промпте</th>
                            <th class="px-4 py-3">Статус</th>
                            <th class="px-4 py-3 text-right">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in localItems" :key="item.id" class="border-b border-[var(--wa-border)] last:border-0">
                            <td class="px-3 py-3 align-top">
                                <input
                                    type="checkbox"
                                    class="kb-checkbox"
                                    :checked="selectedIds.includes(item.id)"
                                    @click.prevent="toggleRowSelection(item.id)"
                                />
                            </td>
                            <td class="px-4 py-3 text-[var(--wa-text)]">
                                <div class="font-medium">{{ itemTitle(item) }}</div>
                                <div class="max-w-[520px] truncate text-xs text-[var(--wa-text-secondary)]">
                                    {{ item.description || item.content || item.sku || '—' }}
                                </div>
                            </td>
                            <td class="px-4 py-3 text-[var(--wa-text-secondary)]">{{ item.company?.name || `#${item.company_id}` }}</td>
                            <td class="px-4 py-3 text-[var(--wa-text-secondary)]">{{ formatTenge(item.price) }}</td>
                            <td class="px-4 py-3">
                                <button type="button" class="switch" :class="{ on: item.include_in_prompt }" @click="togglePrompt(item)">
                                    {{ item.include_in_prompt ? 'Да' : 'Нет' }}
                                </button>
                            </td>
                            <td class="px-4 py-3 text-[var(--wa-text-secondary)]">{{ item.is_active ? 'Активна' : 'Отключена' }}</td>
                            <td class="space-x-2 px-4 py-3 text-right">
                                <button type="button" class="link-btn" @click="openEdit(item)">Изменить</button>
                                <button type="button" class="link-btn danger" @click="destroyItem(item)">Удалить</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="deleteTarget" class="fixed inset-0 z-[55] flex items-center justify-center bg-black/50 p-4" @click.self="closeDeleteModal">
            <div class="w-full max-w-md rounded-2xl border border-[var(--wa-border)] bg-[var(--wa-panel)] p-5 shadow-xl">
                <h3 class="text-lg text-[var(--wa-text)]">Удалить запись?</h3>
                <p class="mt-2 text-sm text-[var(--wa-text-secondary)]">
                    Это действие необратимо. Чтобы подтвердить, введите название записи целиком:
                    <span class="font-medium text-[var(--wa-text)]">{{ itemTitle(deleteTarget) }}</span>
                </p>
                <input
                    v-model="deleteConfirmInput"
                    type="text"
                    class="mt-4 w-full rounded-lg border border-[var(--wa-border)] bg-[var(--wa-bg)] px-3 py-2 text-sm text-[var(--wa-text)]"
                    placeholder="Название для подтверждения"
                    autocomplete="off"
                />
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="px-4 py-2 rounded-lg border border-[var(--wa-border)] text-sm" @click="closeDeleteModal">Отмена</button>
                    <button type="button" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm" @click="confirmDelete">Удалить</button>
                </div>
            </div>
        </div>

        <div v-if="previewOpen" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" @click.self="closePreview">
            <div class="flex max-h-[calc(100vh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-2xl bg-[var(--wa-panel)] shadow-xl">
                <div class="flex shrink-0 items-center justify-between border-b border-[var(--wa-border)] px-5 py-4">
                    <div>
                        <h3 class="text-lg text-[var(--wa-text)]">Предпросмотр блока для AI</h3>
                        <p v-if="previewCounts" class="mt-1 text-xs text-[var(--wa-text-secondary)]">
                            Правил: {{ previewCounts.rules }}, товаров: {{ previewCounts.products }}, услуг: {{ previewCounts.services }}
                            <span v-if="previewTruncated"> · показ обрезан для экрана</span>
                        </p>
                    </div>
                    <button type="button" class="text-[var(--wa-text-secondary)]" @click="closePreview">Закрыть</button>
                </div>
                <p v-if="previewHint" class="border-b border-[var(--wa-border)] px-5 py-2 text-xs text-[var(--wa-text-secondary)]">{{ previewHint }}</p>
                <div class="wa-scrollbar flex-1 overflow-y-auto px-5 py-4">
                    <pre class="kb-pre">{{ previewText }}</pre>
                </div>
            </div>
        </div>

        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="flex max-h-[calc(100vh-2rem)] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-[var(--wa-panel)] shadow-xl">
                <div class="flex shrink-0 items-center justify-between border-b border-[var(--wa-border)] px-5 py-4">
                    <h3 class="text-lg text-[var(--wa-text)]">{{ editing ? 'Редактировать' : meta.addLabel }}</h3>
                    <button type="button" class="text-[var(--wa-text-secondary)]" @click="showForm = false">Закрыть</button>
                </div>

                <div class="grid flex-1 grid-cols-2 gap-3 overflow-y-auto px-5 py-4 wa-scrollbar">
                    <label class="field col-span-2">
                        <span>Компания</span>
                        <select v-model="form.company_id">
                            <option v-for="company in companies" :key="company.id" :value="company.id">{{ company.name }}</option>
                        </select>
                    </label>

                    <label v-if="section !== 'rules'" class="field col-span-2">
                        <span>Название</span>
                        <input v-model="form.name" type="text" />
                    </label>

                    <label v-if="section === 'rules'" class="field col-span-2">
                        <span>Заголовок</span>
                        <input v-model="form.title" type="text" />
                    </label>

                    <label v-if="section === 'products'" class="field">
                        <span>Артикул <small>необязательно</small></span>
                        <input v-model="form.sku" type="text" placeholder="Например: ART-001" />
                        <p class="field-help">Внутренний код товара. Если не используете артикулы, оставьте пустым.</p>
                    </label>

                    <label v-if="section === 'rules'" class="field">
                        <span>Тип</span>
                        <input v-model="form.type" type="text" />
                    </label>

                    <label v-if="section === 'rules'" class="field">
                        <span>Приоритет</span>
                        <input v-model.number="form.priority" type="number" min="1" />
                    </label>

                    <label v-if="section !== 'rules'" class="field">
                        <span>Цена, ₸</span>
                        <input v-model="form.price" type="number" min="0" step="0.01" />
                    </label>

                    <label v-if="section === 'services'" class="field">
                        <span>Длительность, мин.</span>
                        <input v-model="form.duration_minutes" type="number" min="1" />
                    </label>

                    <label class="field col-span-2">
                        <span>{{ section === 'rules' ? 'Содержание правила' : 'Описание' }}</span>
                        <textarea v-if="section === 'rules'" v-model="form.content" rows="5"></textarea>
                        <textarea v-else v-model="form.description" rows="5"></textarea>
                    </label>

                    <details v-if="section !== 'rules'" class="advanced col-span-2">
                        <summary>Дополнительно</summary>
                        <div class="advanced-body">
                            <label class="field">
                                <span>Сортировка</span>
                                <input v-model.number="form.sort_order" type="number" min="0" />
                                <p class="field-help">Чем меньше число, тем выше запись в списке. Обычно можно оставить 0.</p>
                            </label>

                            <label class="field">
                                <span>{{ section === 'services' ? 'Условия' : 'Характеристики' }} <small>необязательно</small></span>
                                <textarea v-model="detailsText" rows="5" spellcheck="false" :placeholder="detailsPlaceholder"></textarea>
                                <p class="field-help">
                                    Пишите обычным текстом по строкам. Например: "цвет: черный". Система сама сохранит это в правильном формате для AI.
                                </p>
                            </label>
                        </div>
                    </details>

                    <label class="check">
                        <input v-model="form.include_in_prompt" type="checkbox" />
                        <span>Учитывать в промпте</span>
                    </label>
                    <label class="check">
                        <input v-model="form.is_active" type="checkbox" />
                        <span>Активна</span>
                    </label>
                </div>

                <div class="flex shrink-0 justify-end gap-2 border-t border-[var(--wa-border)] px-5 py-4">
                    <button type="button" class="px-4 py-2 rounded-lg border border-[var(--wa-border)]" @click="showForm = false">Отмена</button>
                    <button type="button" class="px-4 py-2 rounded-lg bg-[var(--wa-green)] text-white" @click="save">Сохранить</button>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>

<style scoped>
.link-btn {
    color: var(--wa-green);
}

.link-btn.danger {
    color: var(--wa-danger);
}

.switch {
    border: 1px solid var(--wa-border);
    border-radius: 999px;
    color: var(--wa-text-secondary);
    padding: 4px 10px;
}

.switch.on {
    background: color-mix(in srgb, var(--wa-green) 16%, transparent);
    color: var(--wa-green);
}

.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    color: var(--wa-text-secondary);
    font-size: 13px;
}

.field small {
    color: var(--wa-text-secondary);
    font-size: 11px;
    font-weight: 400;
}

.field-help {
    color: var(--wa-text-secondary);
    font-size: 12px;
    line-height: 1.35;
}

.field input,
.field select,
.field textarea {
    background: var(--wa-bg);
    border: 1px solid var(--wa-border);
    border-radius: 10px;
    color: var(--wa-text);
    padding: 10px 12px;
}

.kb-toolbar-field {
    max-width: 280px;
    min-width: 200px;
}

.kb-pre {
    color: var(--wa-text);
    font-size: 12px;
    line-height: 1.45;
    white-space: pre-wrap;
    word-break: break-word;
}

.kb-checkbox {
    accent-color: var(--wa-green);
    height: 16px;
    width: 16px;
}

.check {
    align-items: center;
    color: var(--wa-text);
    display: flex;
    gap: 8px;
}

.advanced {
    border: 1px solid var(--wa-border);
    border-radius: 12px;
    color: var(--wa-text);
    padding: 10px 12px;
}

.advanced summary {
    color: var(--wa-text);
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
}

.advanced-body {
    display: grid;
    gap: 12px;
    margin-top: 12px;
}
</style>
