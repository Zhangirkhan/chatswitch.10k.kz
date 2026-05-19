<script setup lang="ts">
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
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
    image_path?: string | null;
    image_url?: string | null;
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
    image_file: null as File | null,
    remove_image: false,
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
const previewUsedRag = ref(false);
const testQuestion = ref('');
const testQuestionResult = ref('');

type RagStatus = {
    enabled: boolean;
    indexed: number;
    with_embedding: number;
    ready: boolean;
};

const ragStatus = ref<RagStatus | null>(null);
const reindexLoading = ref(false);
const ragReindexSuggested = ref(false);

type CatalogAuditFinding = {
    key: string;
    severity: 'critical' | 'warning' | 'info';
    category: string;
    title: string;
    description: string;
    action: string;
};

type CatalogAuditSummary = {
    critical: number;
    warning: number;
    info: number;
    total: number;
};

const catalogAuditLoading = ref(false);
const catalogAuditFindings = ref<CatalogAuditFinding[]>([]);
const catalogAuditSummary = ref<CatalogAuditSummary | null>(null);
const catalogAuditUseLlm = ref(false);
const catalogAuditLlmUsed = ref(false);

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
const productImagePreview = ref<string | null>(null);

const auditEntityType = computed(() =>
    props.section === 'products' ? 'product' : props.section === 'services' ? 'service' : 'rule',
);

const selectedIds = ref<number[]>([]);
const searchQuery = ref('');
const statusFilter = ref<'all' | 'active' | 'inactive'>('all');
const promptFilter = ref<'all' | 'included' | 'excluded'>('all');
const aiToolsOpen = ref(false);

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
    () => filteredItems.value.length > 0 && filteredItems.value.every((i) => selectedIds.value.includes(i.id)),
);

const filteredItems = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();

    return localItems.value.filter((item) => {
        if (statusFilter.value === 'active' && !item.is_active) return false;
        if (statusFilter.value === 'inactive' && item.is_active) return false;
        if (promptFilter.value === 'included' && !item.include_in_prompt) return false;
        if (promptFilter.value === 'excluded' && item.include_in_prompt) return false;

        if (q === '') return true;

        const haystack = [
            item.name,
            item.title,
            item.sku,
            item.description,
            item.content,
            item.company?.name,
            item.price,
            item.duration_minutes,
            item.type,
            JSON.stringify(item.attributes ?? item.conditions ?? {}),
        ]
            .filter((value) => value !== null && value !== undefined)
            .join(' ')
            .toLowerCase();

        return haystack.includes(q);
    });
});

const activeCount = computed(() => localItems.value.filter((item) => item.is_active).length);
const promptCount = computed(() => localItems.value.filter((item) => item.is_active && item.include_in_prompt).length);
const missingDescriptionCount = computed(() =>
    localItems.value.filter((item) => props.section !== 'rules' && !String(item.description ?? '').trim()).length,
);
const missingPriceCount = computed(() =>
    localItems.value.filter((item) => props.section !== 'rules' && (item.price === null || item.price === undefined || item.price === '')).length,
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
        label: 'Контекст готов',
        ok: props.companies.length > 0,
        hint: props.companies.length > 0 ? 'Можно собирать контекст для AI.' : 'Контекст AI ещё не подготовлен.',
    },
    {
        label: 'Есть активные записи в промпте',
        ok: promptReadyItems.value.length > 0,
        hint: promptReadyItems.value.length > 0 ? `В промпт попадёт записей: ${promptReadyItems.value.length}.` : 'Включите хотя бы одну активную запись в промпт.',
    },
    {
        label: 'Предпросмотр доступен',
        ok: previewCompanyId.value !== null,
        hint: previewCompanyId.value !== null ? 'Можно проверить, что увидит AI.' : 'Предпросмотр пока недоступен.',
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
    if (filteredItems.value.length === 0) {
        return;
    }
    if (allSelected.value) {
        const visible = new Set(filteredItems.value.map((i) => i.id));
        selectedIds.value = selectedIds.value.filter((id) => !visible.has(id));
        return;
    }
    selectedIds.value = Array.from(new Set([...selectedIds.value, ...filteredItems.value.map((i) => i.id)]));
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

async function loadRagStatus(): Promise<void> {
    if (previewCompanyId.value == null) {
        ragStatus.value = null;
        return;
    }
    try {
        const { data } = await axios.get(route('settings.knowledge.rag-status'), {
            params: { company_id: previewCompanyId.value },
        });
        ragStatus.value = (data.rag ?? null) as RagStatus | null;
    } catch {
        ragStatus.value = null;
    }
}

function suggestRagReindex(): void {
    if (ragStatus.value?.enabled) {
        ragReindexSuggested.value = true;
    }
}

async function reindexEmbeddings(): Promise<void> {
    if (previewCompanyId.value == null) {
        return;
    }
    reindexLoading.value = true;
    try {
        const { data } = await axios.post(route('settings.knowledge.reindex-embeddings'), {
            company_id: previewCompanyId.value,
        });
        ragStatus.value = (data.rag ?? null) as RagStatus | null;
        showToast({
            message: `Индексация: +${data.stats?.indexed ?? 0}, пропущено ${data.stats?.skipped ?? 0}`,
            duration: 4000,
        });
        ragReindexSuggested.value = false;
    } catch (error: any) {
        showToast({
            message: error?.response?.data?.message || error?.message || 'Не удалось проиндексировать',
            duration: 4000,
        });
    } finally {
        reindexLoading.value = false;
    }
}

async function loadPreview(query?: string | null): Promise<void> {
    if (previewCompanyId.value == null) {
        showToast({ message: 'Предпросмотр пока недоступен', duration: 3000 });
        return;
    }
    previewLoading.value = true;
    const trimmedQuery = (query ?? testQuestion.value).trim();
    try {
        const { data } = await axios.get(route('settings.knowledge.prompt-preview'), {
            params: {
                company_id: previewCompanyId.value,
                ...(trimmedQuery !== '' ? { query: trimmedQuery } : {}),
            },
        });
        previewText.value = String(data.text ?? '');
        previewTruncated.value = Boolean(data.truncated);
        previewCounts.value = data.counts as PreviewCounts;
        previewHint.value = String(data.hint ?? '');
        previewUsedRag.value = Boolean(data.used_rag);
        if (data.rag) {
            ragStatus.value = data.rag as RagStatus;
        }
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

    await loadPreview(question);

    if (previewUsedRag.value) {
        testQuestionResult.value = 'RAG подобрал релевантные записи по вопросу — откройте предпросмотр.';
        return;
    }

    const context = previewText.value.toLowerCase();
    const keywords = question
        .toLowerCase()
        .split(/[^\p{L}\p{N}]+/u)
        .filter((word) => word.length >= 4);
    const matched = keywords.filter((word) => context.includes(word));

    if (matched.length > 0) {
        testQuestionResult.value = `В полном контексте AI найдены совпадения: ${matched.slice(0, 5).join(', ')}. Для точной проверки включите RAG и проиндексируйте базу.`;
        return;
    }

    testQuestionResult.value = 'В контексте нет явных совпадений. Добавьте запись с нужными формулировками или проиндексируйте RAG.';
}

watch(previewCompanyId, () => {
    void loadRagStatus();
    if (aiToolsOpen.value) {
        void loadCatalogAudit();
    }
});

watch(aiToolsOpen, (open) => {
    if (open) {
        void loadRagStatus();
        void loadCatalogAudit();
    }
});

function catalogSeverityClass(severity: CatalogAuditFinding['severity']): string {
    if (severity === 'critical') {
        return 'bg-red-500/15 text-red-500';
    }
    if (severity === 'warning') {
        return 'bg-amber-500/15 text-amber-500';
    }
    return 'bg-[var(--ui-accent-soft)] text-[var(--ui-accent)]';
}

async function loadCatalogAudit(): Promise<void> {
    if (previewCompanyId.value == null) {
        catalogAuditFindings.value = [];
        catalogAuditSummary.value = null;
        return;
    }
    catalogAuditLoading.value = true;
    try {
        const { data } = await axios.get(route('settings.knowledge.catalog-audit'), {
            params: {
                company_id: previewCompanyId.value,
                llm: catalogAuditUseLlm.value ? 1 : 0,
                refresh_llm: catalogAuditUseLlm.value ? 1 : 0,
            },
        });
        catalogAuditFindings.value = (data.findings ?? []) as CatalogAuditFinding[];
        catalogAuditSummary.value = (data.summary ?? null) as CatalogAuditSummary | null;
        catalogAuditLlmUsed.value = Boolean(data.llm_used);
    } catch {
        catalogAuditFindings.value = [];
        catalogAuditSummary.value = null;
    } finally {
        catalogAuditLoading.value = false;
    }
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
        suggestRagReindex();
    } catch (error: any) {
        showToast({
            message: error?.response?.data?.message || error?.message || 'Не удалось обновить записи',
            duration: 4000,
        });
    }
}

function resetForm(): void {
    editing.value = null;
    revokeProductImagePreview();
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
        image_file: null,
        remove_image: false,
    };
    detailsText.value = '';
    productImagePreview.value = null;
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
        image_file: null,
        remove_image: false,
    };
    revokeProductImagePreview();
    productImagePreview.value = item.image_url ?? null;
    detailsText.value = detailsObjectToText(props.section === 'services' ? (item.conditions ?? {}) : (item.attributes ?? {}));
    showForm.value = true;
}

function revokeProductImagePreview(): void {
    if (productImagePreview.value?.startsWith('blob:')) {
        URL.revokeObjectURL(productImagePreview.value);
    }
}

function selectProductImage(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    revokeProductImagePreview();
    form.value.image_file = file;
    form.value.remove_image = false;
    productImagePreview.value = file ? URL.createObjectURL(file) : editing.value?.image_url ?? null;
}

function removeProductImage(): void {
    revokeProductImagePreview();
    form.value.image_file = null;
    form.value.remove_image = true;
    productImagePreview.value = null;
}

function closeForm(): void {
    revokeProductImagePreview();
    productImagePreview.value = null;
    showForm.value = false;
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
        remove_image: form.value.remove_image,
    };
}

function appendFormData(formData: FormData, key: string, value: unknown): void {
    if (value === undefined) {
        return;
    }

    if (value instanceof File) {
        formData.append(key, value);
        return;
    }

    if (value === null) {
        formData.append(key, '');
        return;
    }

    if (typeof value === 'boolean') {
        formData.append(key, value ? '1' : '0');
        return;
    }

    if (Array.isArray(value)) {
        value.forEach((entry, index) => appendFormData(formData, `${key}[${index}]`, entry));
        return;
    }

    if (typeof value === 'object') {
        Object.entries(value as Record<string, unknown>).forEach(([childKey, childValue]) => {
            appendFormData(formData, `${key}[${childKey}]`, childValue);
        });
        return;
    }

    formData.append(key, String(value));
}

function productFormData(data: Record<string, unknown>, method?: 'PUT'): FormData {
    const formData = new FormData();

    Object.entries(data).forEach(([key, value]) => appendFormData(formData, key, value));

    if (form.value.image_file) {
        formData.append('image', form.value.image_file);
    }

    if (method) {
        formData.append('_method', method);
    }

    return formData;
}

async function save(): Promise<void> {
    try {
        const data = payload();
        const response = props.section === 'products' && form.value.image_file
            ? editing.value
                ? await axios.post(route(meta.value.update, editing.value.id), productFormData(data, 'PUT'))
                : await axios.post(route(meta.value.store), productFormData(data))
            : editing.value
                ? await axios.put(route(meta.value.update, editing.value.id), data)
                : await axios.post(route(meta.value.store), data);
        const item = response.data.item as KnowledgeItem;
        if (editing.value) {
            localItems.value = localItems.value.map((existing) => existing.id === item.id ? item : existing);
        } else {
            localItems.value = [item, ...localItems.value];
        }
        revokeProductImagePreview();
        productImagePreview.value = null;
        showForm.value = false;
        showToast({ message: 'Запись сохранена', duration: 3000 });
        suggestRagReindex();
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
    suggestRagReindex();
}

function itemTitle(item: KnowledgeItem): string {
    return item.name || item.title || `#${item.id}`;
}

function itemDescription(item: KnowledgeItem): string {
    const value = item.description || item.content || '';
    return value.trim() || (props.section === 'rules' ? 'Текст правила не заполнен' : 'Описание не заполнено');
}

function compactDetails(item: KnowledgeItem): string[] {
    const raw = props.section === 'services' ? item.conditions : item.attributes;
    if (!raw || props.section === 'rules') {
        return [];
    }

    return Object.entries(raw)
        .filter(([, value]) => value !== null && value !== '')
        .slice(0, 4)
        .map(([key, value]) => {
            const display = Array.isArray(value) ? value.join(', ') : typeof value === 'object' ? JSON.stringify(value) : String(value);
            return `${key}: ${display}`;
        });
}

function itemQualityFlags(item: KnowledgeItem): string[] {
    const flags: string[] = [];
    if (!item.is_active) {
        flags.push('Отключено');
    }
    if (!item.include_in_prompt) {
        flags.push('Не в AI');
    }
    if (props.section !== 'rules' && !String(item.description ?? '').trim()) {
        flags.push('Нет описания');
    }
    if (props.section !== 'rules' && (item.price === null || item.price === undefined || item.price === '')) {
        flags.push('Нет цены');
    }

    return flags;
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
        suggestRagReindex();
    } catch (error: any) {
        showToast({ message: error?.response?.data?.message || 'Не удалось удалить запись', duration: 4000 });
    }
}
</script>

<template>
    <Head :title="meta.title" />

    <SettingsLayout :title="meta.title" :subtitle="meta.subtitle">
        <template #actions>
            <button type="button" class="px-4 py-2 rounded-lg bg-[var(--ui-accent)] text-white text-sm hover:bg-[var(--ui-accent-hover)]" @click="openAdd">
                {{ meta.addLabel }}
            </button>
        </template>

        <div class="p-4 md:p-6 space-y-4">
            <section class="kb-hero rounded-2xl border border-[var(--ui-border)] bg-[var(--ui-surface)] p-4 md:p-5">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div class="max-w-3xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--ui-accent)]">Каталог для AI</p>
                        <h2 class="mt-1 text-xl font-semibold text-[var(--ui-text)]">{{ meta.title }}</h2>
                        <p class="mt-1 text-sm text-[var(--ui-text-secondary)]">
                            Заполняйте короткие факты: что это, цена, условия и важные ограничения. AI будет использовать данные точечно в ответе клиенту, а не рисовать одинаковые карточки.
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:min-w-[520px]">
                        <div class="kb-stat">
                            <span>Всего</span>
                            <strong>{{ localItems.length }}</strong>
                        </div>
                        <div class="kb-stat">
                            <span>Активны</span>
                            <strong>{{ activeCount }}</strong>
                        </div>
                        <div class="kb-stat">
                            <span>В AI</span>
                            <strong>{{ promptCount }}</strong>
                        </div>
                        <div class="kb-stat" :class="missingDescriptionCount + missingPriceCount > 0 ? 'warn' : ''">
                            <span>Пробелы</span>
                            <strong>{{ missingDescriptionCount + missingPriceCount }}</strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="kb-control-panel rounded-2xl border border-[var(--ui-border)] bg-[var(--ui-surface)] p-3 md:p-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="grid flex-1 gap-2 md:grid-cols-[minmax(220px,1fr)_170px_170px]">
                        <label class="kb-search">
                            <svg class="h-4 w-4 shrink-0 text-[var(--ui-text-secondary)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" />
                            </svg>
                            <input v-model="searchQuery" type="search" placeholder="Поиск по названию, цене, описанию, характеристикам" />
                        </label>
                        <select v-model="statusFilter" class="kb-filter">
                            <option value="all">Все статусы</option>
                            <option value="active">Только активные</option>
                            <option value="inactive">Отключённые</option>
                        </select>
                        <select v-model="promptFilter" class="kb-filter">
                            <option value="all">AI: все</option>
                            <option value="included">В промпте</option>
                            <option value="excluded">Не в промпте</option>
                        </select>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="rounded-lg border border-[var(--ui-border-strong)] px-3 py-2 text-sm text-[var(--ui-text)]"
                            :disabled="previewCompanyId == null || previewLoading"
                            @click="() => loadPreview()"
                        >
                            {{ previewLoading ? 'Загрузка…' : 'Что увидит AI' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-[var(--ui-border-strong)] px-3 py-2 text-sm text-[var(--ui-text)]"
                            @click="aiToolsOpen = !aiToolsOpen"
                        >
                            {{ aiToolsOpen ? 'Скрыть проверку' : 'Проверка AI' }}
                        </button>
                        <button type="button" class="rounded-lg bg-[var(--ui-accent)] px-4 py-2 text-sm text-white hover:bg-[var(--ui-accent-hover)]" @click="openAdd">
                            {{ meta.addLabel }}
                        </button>
                    </div>
                </div>

                <div v-if="selectedIds.length > 0" class="kb-selection-bar mt-3 flex flex-wrap items-center gap-2 rounded-xl px-3 py-2">
                    <span class="text-sm text-[var(--ui-text-secondary)]">Выбрано: {{ selectedIds.length }}</span>
                    <button type="button" class="rounded-lg bg-[var(--ui-accent)] px-3 py-2 text-sm text-white hover:bg-[var(--ui-accent-hover)]" @click="bulkSetPrompt(true)">Добавить в AI</button>
                    <button type="button" class="rounded-lg border border-[var(--ui-border-strong)] px-3 py-2 text-sm text-[var(--ui-text)]" @click="bulkSetPrompt(false)">Убрать из AI</button>
                    <button type="button" class="link-btn px-2 text-sm" @click="clearSelection">Снять выделение</button>
                </div>
            </section>

            <section v-if="aiToolsOpen" class="grid gap-4 lg:grid-cols-3">
                <div class="kb-card rounded-2xl border p-4">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--ui-text)]">Готовность AI</h3>
                            <p class="text-xs text-[var(--ui-text-secondary)]">Минимальная проверка качества данных.</p>
                        </div>
                        <span
                            class="rounded-full px-2.5 py-1 text-xs"
                            :class="readinessChecks.every((check) => check.ok) ? 'bg-[var(--ui-accent-soft)] text-[var(--ui-accent)]' : 'bg-amber-500/15 text-amber-500'"
                        >
                            {{ readinessChecks.every((check) => check.ok) ? 'Готово' : 'Нужно внимание' }}
                        </span>
                    </div>
                    <div class="space-y-2">
                        <div v-for="check in readinessChecks" :key="check.label" class="kb-inset flex gap-2 rounded-lg px-3 py-2">
                            <span class="mt-1 h-2.5 w-2.5 shrink-0 rounded-full" :class="check.ok ? 'bg-[var(--ui-accent)]' : 'bg-amber-500'"></span>
                            <div>
                                <p class="text-sm text-[var(--ui-text)]">{{ check.label }}</p>
                                <p class="text-xs text-[var(--ui-text-secondary)]">{{ check.hint }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="kb-card rounded-2xl border p-4">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--ui-text)]">RAG-поиск</h3>
                            <p class="text-xs text-[var(--ui-text-secondary)]">Embeddings для подбора релевантных записей в промпт.</p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg border border-[var(--ui-border-strong)] px-3 py-1.5 text-xs text-[var(--ui-text)]"
                            :disabled="previewCompanyId == null || reindexLoading"
                            @click="reindexEmbeddings"
                        >
                            {{ reindexLoading ? 'Индексация…' : 'Переиндексировать' }}
                        </button>
                    </div>
                    <p v-if="ragStatus" class="kb-inset rounded-lg px-3 py-2 text-xs text-[var(--ui-text-secondary)]">
                        <span v-if="!ragStatus.enabled">RAG отключён.</span>
                        <span v-else-if="ragStatus.ready">Готово: {{ ragStatus.with_embedding }} фрагментов с embeddings.</span>
                        <span v-else>Нужна индексация (в базе {{ ragStatus.indexed }} фрагментов, с embeddings: {{ ragStatus.with_embedding }}).</span>
                    </p>
                    <p
                        v-if="ragReindexSuggested && ragStatus?.enabled"
                        class="kb-inset mt-2 rounded-lg border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-xs text-amber-800 dark:text-amber-200"
                    >
                        Каталог изменён: индекс обновится в фоне. Для немедленной проверки RAG нажмите «Переиндексировать».
                    </p>
                </div>

                <div class="kb-card rounded-2xl border p-4">
                    <h3 class="text-sm font-semibold text-[var(--ui-text)]">Тестовый вопрос</h3>
                    <p class="mt-1 text-xs text-[var(--ui-text-secondary)]">Проверьте RAG: вопрос подберёт релевантные записи в предпросмотре.</p>
                    <div class="mt-3 flex gap-2">
                        <input
                            v-model="testQuestion"
                            class="flex-1 rounded-lg border border-[var(--ui-border-strong)] bg-[var(--ui-input-bg)] px-3 py-2 text-sm text-[var(--ui-text)]"
                            type="text"
                            placeholder="Например: сколько стоит доставка?"
                            @keydown.enter.prevent="runTestQuestion"
                        />
                        <button type="button" class="rounded-lg border border-[var(--ui-border-strong)] px-3 py-2 text-sm text-[var(--ui-text)]" :disabled="previewCompanyId == null || previewLoading" @click="runTestQuestion">
                            Проверить
                        </button>
                    </div>
                    <p v-if="testQuestionResult" class="kb-inset mt-3 rounded-lg px-3 py-2 text-xs text-[var(--ui-text-secondary)]">{{ testQuestionResult }}</p>
                </div>

                <div class="kb-card rounded-2xl border p-4 lg:col-span-3">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--ui-text)]">Аудит каталога</h3>
                            <p class="text-xs text-[var(--ui-text-secondary)]">Дубли, цены, пробелы и противоречия.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="flex items-center gap-1.5 text-xs text-[var(--ui-text-secondary)]">
                                <UiCheckbox v-model="catalogAuditUseLlm" size="sm" />
                                AI-анализ
                            </label>
                            <button
                                type="button"
                                class="rounded-lg border border-[var(--ui-border-strong)] px-3 py-1.5 text-xs text-[var(--ui-text)]"
                                :disabled="previewCompanyId == null || catalogAuditLoading"
                                @click="loadCatalogAudit"
                            >
                                {{ catalogAuditLoading ? 'Проверка…' : 'Обновить' }}
                            </button>
                        </div>
                    </div>
                    <p v-if="catalogAuditLlmUsed" class="mb-2 text-xs text-[var(--ui-accent)]">В отчёт добавлен AI-анализ формулировок.</p>
                    <p v-if="catalogAuditSummary && catalogAuditSummary.total === 0" class="kb-inset rounded-lg px-3 py-2 text-xs text-[var(--ui-text-secondary)]">
                        Замечаний не найдено.
                    </p>
                    <ul v-else-if="catalogAuditFindings.length > 0" class="wa-scrollbar max-h-64 space-y-2 overflow-y-auto">
                        <li v-for="item in catalogAuditFindings" :key="item.key" class="kb-inset rounded-lg border px-3 py-2 text-xs">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 font-medium" :class="catalogSeverityClass(item.severity)">{{ item.severity }}</span>
                                <span class="text-[var(--ui-text-secondary)]">{{ item.category }}</span>
                            </div>
                            <p class="mt-1 font-medium text-[var(--ui-text)]">{{ item.title }}</p>
                            <p class="mt-0.5 text-[var(--ui-text-secondary)]">{{ item.description }}</p>
                            <p class="mt-1 text-[var(--ui-text)]">{{ item.action }}</p>
                        </li>
                    </ul>
                    <p v-else-if="!catalogAuditLoading" class="text-xs text-[var(--ui-text-secondary)]">Нажмите «Обновить» для проверки.</p>
                </div>

                <div class="kb-card rounded-2xl border p-4 lg:col-span-3">
                    <button type="button" class="flex w-full items-center justify-between gap-3 text-left" @click="toggleAudit">
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--ui-text)]">История изменений</h3>
                            <p class="text-xs text-[var(--ui-text-secondary)]">Создание, правки, удаление и массовые изменения видимости для AI.</p>
                        </div>
                        <span class="text-xs text-[var(--ui-text-secondary)] shrink-0">{{ auditOpen ? 'Скрыть' : 'Показать' }}</span>
                    </button>
                    <div v-if="auditOpen" class="mt-3 space-y-2">
                        <p v-if="previewCompanyId == null" class="text-xs text-[var(--ui-text-secondary)]">История изменений пока недоступна.</p>
                        <p v-else-if="auditLoading" class="text-xs text-[var(--ui-text-secondary)]">Загрузка…</p>
                        <ul v-else class="wa-scrollbar max-h-56 space-y-2 overflow-y-auto text-xs">
                            <li v-for="row in auditEntries" :key="row.id" class="kb-inset rounded-lg border px-3 py-2">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="font-medium text-[var(--ui-text)]">{{ formatAuditAction(row.action) }} <span class="font-normal text-[var(--ui-text-secondary)]">· {{ row.entity_label || '#' + row.entity_id }}</span></span>
                                    <span class="text-[var(--ui-text-secondary)]">{{ formatAuditWhen(row.created_at) }}</span>
                                </div>
                                <div class="mt-0.5 text-[var(--ui-text-secondary)]">{{ row.user?.name ?? '—' }}</div>
                            </li>
                        </ul>
                        <p v-if="!auditLoading && previewCompanyId != null && auditEntries.length === 0" class="text-xs text-[var(--ui-text-secondary)]">Пока нет записей аудита.</p>
                    </div>
                </div>
            </section>

            <div v-if="dataWarnings.length > 0" class="rounded-2xl border border-amber-500/25 bg-amber-500/10 p-3">
                <p class="mb-2 text-xs font-semibold text-amber-500">Что мешает AI отвечать точно</p>
                <ul class="space-y-1 text-xs text-[var(--ui-text)]">
                    <li v-for="warning in dataWarnings" :key="warning">• {{ warning }}</li>
                </ul>
            </div>

            <div v-if="localItems.length === 0" class="kb-card rounded-2xl border px-6 py-10 text-center">
                <p class="text-[15px] text-[var(--ui-text)]">Пока нет записей в этом разделе.</p>
                <p class="mx-auto mt-2 max-w-xl text-sm text-[var(--ui-text-secondary)]">
                    Начните с реальных фактов: название, цена, условия, ограничения. Не нужно писать рекламную карточку — AI сам сформулирует ответ под вопрос клиента.
                </p>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    <button type="button" class="rounded-lg bg-[var(--ui-accent)] px-4 py-2 text-sm text-white hover:bg-[var(--ui-accent-hover)]" @click="openAdd">{{ meta.addLabel }}</button>
                </div>
                <div class="mt-6 flex flex-wrap justify-center gap-4 text-sm">
                    <Link v-for="link in emptyCrossLinks" :key="link.href" :href="link.href" class="link-btn">{{ link.label }}</Link>
                </div>
            </div>

            <div v-else-if="filteredItems.length === 0" class="kb-card rounded-2xl border px-6 py-8 text-center">
                <p class="text-sm text-[var(--ui-text)]">По текущим фильтрам ничего не найдено.</p>
                <button type="button" class="mt-3 text-sm text-[var(--ui-accent)]" @click="searchQuery = ''; statusFilter = 'all'; promptFilter = 'all'">Сбросить фильтры</button>
            </div>

            <section v-else class="kb-list-panel rounded-2xl border">
                <div class="kb-list-header flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                    <div
                        class="flex items-center gap-2 text-sm text-[var(--ui-text-secondary)] cursor-pointer"
                        role="button"
                        tabindex="0"
                        @click="toggleSelectAll"
                        @keydown.enter.prevent="toggleSelectAll"
                    >
                        <UiCheckbox :checked="allSelected" aria-label="Выбрать показанные" @click.stop.prevent="toggleSelectAll" />
                        Выбрать показанные
                    </div>
                    <span class="text-xs text-[var(--ui-text-secondary)]">Показано {{ filteredItems.length }} из {{ localItems.length }}</span>
                </div>

                <div class="divide-y divide-[var(--ui-border)]">
                    <article v-for="item in filteredItems" :key="item.id" class="kb-row">
                        <div class="flex items-start gap-3">
                            <UiCheckbox
                                class="mt-0.5"
                                :checked="selectedIds.includes(item.id)"
                                @click.prevent="toggleRowSelection(item.id)"
                            />
                            <div v-if="section === 'products'" class="kb-product-thumb">
                                <img v-if="item.image_url" :src="item.image_url" :alt="itemTitle(item)" />
                                <span v-else>Фото</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="min-w-0 truncate text-sm font-semibold text-[var(--ui-text)]">{{ itemTitle(item) }}</h3>
                                    <span v-if="item.sku" class="kb-chip">{{ item.sku }}</span>
                                    <span v-for="flag in itemQualityFlags(item)" :key="flag" class="kb-chip warn">{{ flag }}</span>
                                </div>
                                <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-[var(--ui-text-secondary)]">{{ itemDescription(item) }}</p>
                                <div v-if="compactDetails(item).length" class="mt-2 flex flex-wrap gap-1.5">
                                    <span v-for="detail in compactDetails(item)" :key="detail" class="kb-detail">{{ detail }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="kb-row-side">
                            <div class="text-right">
                                <div v-if="section !== 'rules'" class="text-sm font-semibold text-[var(--ui-text)]">{{ formatTenge(item.price) }}</div>
                                <div v-if="section === 'services' && item.duration_minutes" class="text-xs text-[var(--ui-text-secondary)]">{{ item.duration_minutes }} мин.</div>
                            </div>
                            <button type="button" class="switch" :class="{ on: item.include_in_prompt }" @click="togglePrompt(item)">
                                {{ item.include_in_prompt ? 'В AI' : 'Не в AI' }}
                            </button>
                            <button type="button" class="link-btn text-sm" @click="openEdit(item)">Изменить</button>
                            <button type="button" class="link-btn danger text-sm" @click="destroyItem(item)">Удалить</button>
                        </div>
                    </article>
                </div>
            </section>
        </div>

        <div v-if="deleteTarget" class="fixed inset-0 z-[55] flex items-center justify-center bg-black/50 p-4" @click.self="closeDeleteModal">
            <div class="kb-modal-card w-full max-w-md rounded-2xl border p-5 shadow-xl">
                <h3 class="text-lg text-[var(--ui-text)]">Удалить запись?</h3>
                <p class="mt-2 text-sm text-[var(--ui-text-secondary)]">
                    Это действие необратимо. Чтобы подтвердить, введите название записи целиком:
                    <span class="font-medium text-[var(--ui-text)]">{{ itemTitle(deleteTarget) }}</span>
                </p>
                <input
                    v-model="deleteConfirmInput"
                    type="text"
                    class="mt-4 w-full rounded-lg border border-[var(--ui-border-strong)] bg-[var(--ui-input-bg)] px-3 py-2 text-sm text-[var(--ui-text)]"
                    placeholder="Название для подтверждения"
                    autocomplete="off"
                />
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="px-4 py-2 rounded-lg border border-[var(--ui-border-strong)] text-sm" @click="closeDeleteModal">Отмена</button>
                    <button type="button" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm" @click="confirmDelete">Удалить</button>
                </div>
            </div>
        </div>

        <div v-if="previewOpen" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" @click.self="closePreview">
            <div class="kb-modal-card flex max-h-[calc(100vh-2rem)] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border shadow-xl">
                <div class="flex shrink-0 items-center justify-between border-b border-[var(--ui-border)] px-5 py-4">
                    <div>
                        <h3 class="text-lg text-[var(--ui-text)]">Предпросмотр блока для AI</h3>
                        <p v-if="previewCounts" class="mt-1 text-xs text-[var(--ui-text-secondary)]">
                            Правил: {{ previewCounts.rules }}, товаров: {{ previewCounts.products }}, услуг: {{ previewCounts.services }}
                            <span v-if="previewUsedRag"> · RAG</span>
                            <span v-if="previewTruncated"> · показ обрезан для экрана</span>
                        </p>
                    </div>
                    <button type="button" class="text-[var(--ui-text-secondary)]" @click="closePreview">Закрыть</button>
                </div>
                <p v-if="previewHint" class="border-b border-[var(--ui-border)] px-5 py-2 text-xs text-[var(--ui-text-secondary)]">{{ previewHint }}</p>
                <div class="wa-scrollbar flex-1 overflow-y-auto px-5 py-4">
                    <pre class="kb-pre">{{ previewText }}</pre>
                </div>
            </div>
        </div>

        <div v-if="showForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3 md:p-4">
            <div class="kb-modal-card flex max-h-[calc(100vh-1.5rem)] w-full max-w-4xl flex-col overflow-hidden rounded-2xl border shadow-xl">
                <div class="flex shrink-0 items-start justify-between gap-4 border-b border-[var(--ui-border)] px-5 py-4">
                    <div>
                        <h3 class="text-lg font-semibold text-[var(--ui-text)]">{{ editing ? 'Редактировать запись' : meta.addLabel }}</h3>
                        <p class="mt-1 text-xs text-[var(--ui-text-secondary)]">
                            Пишите факты для ответа клиенту. Не нужна рекламная карточка: AI сам соберёт короткий ответ под конкретный вопрос.
                        </p>
                    </div>
                    <button type="button" class="rounded-lg px-3 py-2 text-sm text-[var(--ui-text-secondary)] hover:bg-[var(--ui-surface-muted)]" @click="closeForm">Закрыть</button>
                </div>

                <div class="wa-scrollbar flex-1 overflow-y-auto px-5 py-4">
                    <div class="grid gap-4 lg:grid-cols-[1fr_320px]">
                        <section class="space-y-4">
                            <div class="kb-form-section rounded-xl border p-4">
                                <h4 class="mb-3 text-sm font-semibold text-[var(--ui-text)]">Основное</h4>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label v-if="section !== 'rules'" class="field sm:col-span-2">
                                        <span>{{ section === 'services' ? 'Название услуги' : 'Название товара' }}</span>
                                        <input v-model="form.name" type="text" placeholder="Например: Кухня на заказ" />
                                    </label>

                                    <label v-if="section === 'rules'" class="field sm:col-span-2">
                                        <span>Заголовок правила</span>
                                        <input v-model="form.title" type="text" placeholder="Например: Как отвечать про сроки" />
                                    </label>

                                    <label v-if="section === 'products'" class="field">
                                        <span>Артикул <small>необязательно</small></span>
                                        <input v-model="form.sku" type="text" placeholder="Например: KITCHEN-CUSTOM" />
                                    </label>

                                    <label v-if="section === 'rules'" class="field">
                                        <span>Тип</span>
                                        <input v-model="form.type" type="text" placeholder="sales, delivery, tone" />
                                    </label>

                                    <label v-if="section === 'rules'" class="field">
                                        <span>Приоритет</span>
                                        <input v-model.number="form.priority" type="number" min="1" />
                                    </label>

                                    <label v-if="section !== 'rules'" class="field">
                                        <span>Цена, ₸</span>
                                        <input v-model="form.price" type="number" min="0" step="0.01" placeholder="Если цена договорная, оставьте пустым" />
                                    </label>

                                    <label v-if="section === 'services'" class="field">
                                        <span>Длительность, мин.</span>
                                        <input v-model="form.duration_minutes" type="number" min="1" placeholder="Например: 90" />
                                    </label>
                                </div>
                            </div>

                            <div class="kb-form-section rounded-xl border p-4">
                                <h4 class="mb-1 text-sm font-semibold text-[var(--ui-text)]">
                                    {{ section === 'rules' ? 'Текст правила' : 'Как объяснять клиенту' }}
                                </h4>
                                <p class="mb-3 text-xs text-[var(--ui-text-secondary)]">
                                    {{ section === 'rules' ? 'Конкретное правило поведения AI.' : '1-3 предложения: что это, кому подходит, важные ограничения. Без маркетинговой воды.' }}
                                </p>
                                <label class="field">
                                    <textarea
                                        v-if="section === 'rules'"
                                        v-model="form.content"
                                        rows="7"
                                        placeholder="Например: если клиент спрашивает про сроки, сначала уточни город и объём, затем дай диапазон."
                                    ></textarea>
                                    <textarea
                                        v-else
                                        v-model="form.description"
                                        rows="7"
                                        placeholder="Например: Индивидуальное изготовление кухни под размеры клиента. Цена зависит от материалов, фурнитуры и сложности проекта. Перед расчётом нужен замер."
                                    ></textarea>
                                </label>
                            </div>

                            <div v-if="section === 'products'" class="kb-form-section rounded-xl border p-4">
                                <h4 class="mb-1 text-sm font-semibold text-[var(--ui-text)]">Фото товара</h4>
                                <p class="mb-3 text-xs text-[var(--ui-text-secondary)]">
                                    Покажите внешний вид товара в карточке. Поддерживаются JPG, PNG и WebP до 5 МБ.
                                </p>
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                    <div class="kb-product-photo-preview">
                                        <img v-if="productImagePreview" :src="productImagePreview" alt="Фото товара" />
                                        <span v-else>Нет фото</span>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <label class="cursor-pointer rounded-lg border border-[var(--ui-border-strong)] px-3 py-2 text-sm text-[var(--ui-text)] hover:border-[var(--ui-accent-border)]">
                                            Выбрать фото
                                            <input class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" @change="selectProductImage" />
                                        </label>
                                        <button
                                            v-if="productImagePreview || editing?.image_url"
                                            type="button"
                                            class="rounded-lg border border-[var(--ui-border-strong)] px-3 py-2 text-sm text-[var(--ui-text-secondary)]"
                                            @click="removeProductImage"
                                        >
                                            Убрать фото
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div v-if="section !== 'rules'" class="kb-form-section rounded-xl border p-4">
                                <h4 class="mb-1 text-sm font-semibold text-[var(--ui-text)]">Факты для AI</h4>
                                <p class="mb-3 text-xs text-[var(--ui-text-secondary)]">
                                    Каждая строка: ключ и значение. Это не карточка для клиента, а быстрые факты для точного ответа.
                                </p>
                                <label class="field">
                                    <textarea v-model="detailsText" rows="6" spellcheck="false" :placeholder="detailsPlaceholder"></textarea>
                                </label>
                            </div>
                        </section>

                        <aside class="space-y-4">
                            <div class="kb-form-section rounded-xl border p-4">
                                <h4 class="mb-3 text-sm font-semibold text-[var(--ui-text)]">Публикация</h4>
                                <div class="space-y-3">
                                    <div class="check justify-between rounded-lg border border-[var(--ui-border)] bg-[var(--ui-surface)] px-3 py-2">
                                        <span>Учитывать в AI</span>
                                        <UiCheckbox v-model="form.include_in_prompt" aria-label="Учитывать в AI" />
                                    </div>
                                    <div class="check justify-between rounded-lg border border-[var(--ui-border)] bg-[var(--ui-surface)] px-3 py-2">
                                        <span>Активна</span>
                                        <UiCheckbox v-model="form.is_active" aria-label="Активна" />
                                    </div>
                                    <label v-if="section !== 'rules'" class="field">
                                        <span>Сортировка</span>
                                        <input v-model.number="form.sort_order" type="number" min="0" />
                                        <p class="field-help">Меньше число - выше в списке.</p>
                                    </label>
                                </div>
                            </div>

                            <div class="kb-form-section rounded-xl border p-4">
                                <h4 class="mb-2 text-sm font-semibold text-[var(--ui-text)]">Подсказка</h4>
                                <ul class="space-y-2 text-xs leading-relaxed text-[var(--ui-text-secondary)]">
                                    <li>• Название должно быть коротким и узнаваемым.</li>
                                    <li>• Описание отвечает на вопрос клиента, а не продаёт всё сразу.</li>
                                    <li>• Если цены нет, прямо напишите, от чего она зависит.</li>
                                </ul>
                            </div>
                        </aside>
                    </div>
                </div>

                <div class="flex shrink-0 justify-end gap-2 border-t border-[var(--ui-border)] px-5 py-4">
                    <button type="button" class="rounded-lg border border-[var(--ui-border-strong)] px-4 py-2 text-sm text-[var(--ui-text)]" @click="closeForm">Отмена</button>
                    <button type="button" class="rounded-lg bg-[var(--ui-accent)] px-4 py-2 text-sm font-medium text-white hover:bg-[var(--ui-accent-hover)]" @click="save">Сохранить</button>
                </div>
            </div>
        </div>
    </SettingsLayout>
</template>

<style scoped>
.kb-hero,
.kb-control-panel,
.kb-card,
.kb-list-panel,
.kb-modal-card {
    background: var(--ui-surface);
    border-color: var(--ui-border);
    box-shadow: var(--ui-shadow-soft);
}

.kb-hero {
    background: color-mix(in srgb, var(--ui-accent) 4%, var(--ui-surface-raised));
    border-color: var(--ui-accent-border);
    box-shadow: var(--ui-shadow-soft), inset 0 1px 0 color-mix(in srgb, var(--ui-accent) 28%, transparent);
}

.kb-control-panel,
.kb-list-header {
    background: var(--ui-surface-raised);
    border-color: var(--ui-border);
}

.kb-selection-bar,
.kb-inset,
.kb-form-section {
    background: var(--ui-surface-inset);
    border-color: var(--ui-border);
}

.link-btn {
    color: var(--ui-accent);
}

.link-btn.danger {
    color: var(--ui-danger);
}

.switch {
    border: 1px solid var(--ui-border-strong);
    border-radius: 999px;
    color: var(--ui-text-secondary);
    padding: 4px 10px;
}

.switch.on {
    background: color-mix(in srgb, var(--ui-accent) 18%, transparent);
    border-color: var(--ui-accent-border);
    color: var(--ui-accent);
}

.kb-stat {
    border: 1px solid var(--ui-border);
    border-radius: 14px;
    background: var(--ui-surface-inset);
    padding: 12px 14px;
}

.kb-stat span {
    display: block;
    color: var(--ui-text-secondary);
    font-size: 11px;
}

.kb-stat strong {
    color: var(--ui-text);
    display: block;
    font-size: 20px;
    line-height: 1.1;
    margin-top: 4px;
}

.kb-stat.warn strong {
    color: #f59e0b;
}

.kb-stat:hover {
    border-color: var(--ui-accent-border);
}

.kb-search {
    align-items: center;
    background: var(--ui-input-bg);
    border: 1px solid var(--ui-border-strong);
    border-radius: 10px;
    display: flex;
    gap: 8px;
    padding: 0 10px;
}

.kb-search input,
.kb-filter {
    background: transparent;
    border: 0;
    color: var(--ui-text);
    min-height: 40px;
    outline: none;
    width: 100%;
}

.kb-filter {
    background: var(--ui-input-bg);
    border: 1px solid var(--ui-border-strong);
    border-radius: 10px;
    padding: 0 10px;
}

.kb-row {
    background: var(--ui-surface);
    display: grid;
    gap: 14px;
    grid-template-columns: minmax(0, 1fr) auto;
    padding: 14px 16px;
    transition: background-color 0.15s ease;
}

.kb-row:hover {
    background: color-mix(in srgb, var(--ui-accent) 7%, var(--ui-surface-raised));
}

.kb-row-side {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: flex-end;
}

.kb-product-thumb {
    align-items: center;
    background: var(--ui-surface-inset);
    border: 1px solid var(--ui-border);
    border-radius: 12px;
    color: var(--ui-text-secondary);
    display: flex;
    font-size: 10px;
    height: 56px;
    justify-content: center;
    overflow: hidden;
    width: 56px;
}

.kb-product-thumb img,
.kb-product-photo-preview img {
    height: 100%;
    object-fit: cover;
    width: 100%;
}

.kb-product-photo-preview {
    align-items: center;
    background: var(--ui-surface);
    border: 1px dashed var(--ui-border-strong);
    border-radius: 14px;
    color: var(--ui-text-secondary);
    display: flex;
    font-size: 12px;
    height: 112px;
    justify-content: center;
    overflow: hidden;
    width: 150px;
}

.kb-chip,
.kb-detail {
    border: 1px solid var(--ui-border);
    border-radius: 999px;
    color: var(--ui-text-secondary);
    display: inline-flex;
    max-width: 260px;
    overflow: hidden;
    padding: 3px 8px;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.kb-chip.warn {
    border-color: rgba(245, 158, 11, 0.35);
    color: #f59e0b;
}

.kb-detail {
    background: var(--ui-surface-inset);
    font-size: 11px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 6px;
    color: var(--ui-text-secondary);
    font-size: 13px;
}

.field small {
    color: var(--ui-text-secondary);
    font-size: 11px;
    font-weight: 400;
}

.field-help {
    color: var(--ui-text-secondary);
    font-size: 12px;
    line-height: 1.35;
}

.field input,
.field select,
.field textarea {
    background: var(--ui-input-bg);
    border: 1px solid var(--ui-border-strong);
    border-radius: 10px;
    color: var(--ui-text);
    padding: 10px 12px;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}

.field input:focus,
.field select:focus,
.field textarea:focus {
    border-color: var(--ui-accent);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--ui-accent) 14%, transparent);
    outline: none;
}

.kb-toolbar-field {
    max-width: 280px;
    min-width: 200px;
}

.kb-pre {
    color: var(--ui-text);
    font-size: 12px;
    line-height: 1.45;
    white-space: pre-wrap;
    word-break: break-word;
}

.check {
    align-items: center;
    color: var(--ui-text);
    display: flex;
    gap: 8px;
}

.advanced {
    border: 1px solid var(--ui-border);
    border-radius: 12px;
    color: var(--ui-text);
    padding: 10px 12px;
}

.advanced summary {
    color: var(--ui-text);
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
