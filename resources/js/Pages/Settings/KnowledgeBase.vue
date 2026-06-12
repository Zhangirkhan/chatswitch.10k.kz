<script setup lang="ts">
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { useI18n } from '@/composables/useI18n';
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
const { t, locale } = useI18n();
const kbModalPanelClass = '![background:var(--ui-surface)] ![border-color:var(--ui-border)] shadow-[var(--ui-shadow-soft)]';
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

const meta = computed(() => {
    const sections = {
        products: {
            title: t('settings.knowledgeBase.sections.products.title'),
            subtitle: t('settings.knowledgeBase.sections.products.subtitle'),
            addLabel: t('settings.knowledgeBase.sections.products.addLabel'),
            route: 'settings.knowledge.products',
            store: 'settings.knowledge.products.store',
            update: 'settings.knowledge.products.update',
            destroy: 'settings.knowledge.products.destroy',
            bulkPrompt: 'settings.knowledge.products.bulk-prompt',
        },
        services: {
            title: t('settings.knowledgeBase.sections.services.title'),
            subtitle: t('settings.knowledgeBase.sections.services.subtitle'),
            addLabel: t('settings.knowledgeBase.sections.services.addLabel'),
            route: 'settings.knowledge.services',
            store: 'settings.knowledge.services.store',
            update: 'settings.knowledge.services.update',
            destroy: 'settings.knowledge.services.destroy',
            bulkPrompt: 'settings.knowledge.services.bulk-prompt',
        },
        rules: {
            title: t('settings.knowledgeBase.sections.rules.title'),
            subtitle: t('settings.knowledgeBase.sections.rules.subtitle'),
            addLabel: t('settings.knowledgeBase.sections.rules.addLabel'),
            route: 'settings.knowledge.rules',
            store: 'settings.knowledge.rules.store',
            update: 'settings.knowledge.rules.update',
            destroy: 'settings.knowledge.rules.destroy',
            bulkPrompt: 'settings.knowledge.rules.bulk-prompt',
        },
    } as const;

    return sections[props.section];
});

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
const ragQuality = ref<{ low_quality_count: number; scored_chunks: number; avg_score: number | null } | null>(null);
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
            { label: t('settings.knowledgeBase.crossLinks.services'), href: route('settings.knowledge.services') },
            { label: t('settings.knowledgeBase.crossLinks.rules'), href: route('settings.knowledge.rules') },
        ];
    }
    if (props.section === 'services') {
        return [
            { label: t('settings.knowledgeBase.crossLinks.products'), href: route('settings.knowledge.products') },
            { label: t('settings.knowledgeBase.crossLinks.rules'), href: route('settings.knowledge.rules') },
        ];
    }

    return [
        { label: t('settings.knowledgeBase.crossLinks.products'), href: route('settings.knowledge.products') },
        { label: t('settings.knowledgeBase.crossLinks.services'), href: route('settings.knowledge.services') },
    ];
});

const promptReadyItems = computed(() => localItems.value.filter((item) => item.is_active && item.include_in_prompt));
const readinessChecks = computed(() => [
    {
        label: t('settings.knowledgeBase.readiness.contextReady'),
        ok: props.companies.length > 0,
        hint: props.companies.length > 0
            ? t('settings.knowledgeBase.readiness.contextReadyHint')
            : t('settings.knowledgeBase.readiness.contextMissingHint'),
    },
    {
        label: t('settings.knowledgeBase.readiness.hasPromptItems'),
        ok: promptReadyItems.value.length > 0,
        hint: promptReadyItems.value.length > 0
            ? t('settings.knowledgeBase.readiness.promptItemsHint', { count: promptReadyItems.value.length })
            : t('settings.knowledgeBase.readiness.noPromptItemsHint'),
    },
    {
        label: t('settings.knowledgeBase.readiness.previewAvailable'),
        ok: previewCompanyId.value !== null,
        hint: previewCompanyId.value !== null
            ? t('settings.knowledgeBase.readiness.previewAvailableHint')
            : t('settings.knowledgeBase.readiness.previewUnavailableHint'),
    },
]);
const dataWarnings = computed(() => {
    const warnings: string[] = [];
    if (localItems.value.length === 0) {
        warnings.push(t('settings.knowledgeBase.warnings.emptySection'));
    }
    if (localItems.value.some((item) => item.is_active && !item.include_in_prompt)) {
        warnings.push(t('settings.knowledgeBase.warnings.activeNotInPrompt'));
    }
    if (props.section === 'rules' && localItems.value.some((item) => !String(item.content ?? '').trim())) {
        warnings.push(t('settings.knowledgeBase.warnings.rulesMissingContent'));
    }
    if (props.section !== 'rules' && localItems.value.some((item) => !String(item.description ?? '').trim())) {
        warnings.push(t('settings.knowledgeBase.warnings.missingDescription'));
    }
    if (props.section !== 'rules' && localItems.value.some((item) => item.price === null || item.price === undefined || item.price === '')) {
        warnings.push(t('settings.knowledgeBase.warnings.missingPrice'));
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
        ragQuality.value = null;
        return;
    }
    try {
        const { data } = await axios.get(route('settings.knowledge.rag-status'), {
            params: { company_id: previewCompanyId.value },
        });
        ragStatus.value = (data.rag ?? null) as RagStatus | null;
        ragQuality.value = (data.quality ?? null) as typeof ragQuality.value;
    } catch {
        ragStatus.value = null;
        ragQuality.value = null;
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
            message: t('settings.knowledgeBase.rag.toastIndexed', {
                indexed: data.stats?.indexed ?? 0,
                skipped: data.stats?.skipped ?? 0,
            }),
            duration: 4000,
        });
        ragReindexSuggested.value = false;
    } catch (error: any) {
        showToast({
            message: error?.response?.data?.message || error?.message || t('settings.knowledgeBase.rag.errorReindex'),
            duration: 4000,
        });
    } finally {
        reindexLoading.value = false;
    }
}

async function loadPreview(query?: string | null): Promise<void> {
    if (previewCompanyId.value == null) {
        showToast({ message: t('settings.knowledgeBase.toasts.previewUnavailable'), duration: 3000 });
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
            message: error?.response?.data?.message || error?.message || t('settings.knowledgeBase.toasts.errorPreview'),
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
        testQuestionResult.value = t('settings.knowledgeBase.testQuestion.empty');
        return;
    }

    await loadPreview(question);

    if (previewUsedRag.value) {
        testQuestionResult.value = t('settings.knowledgeBase.testQuestion.ragMatched');
        return;
    }

    const context = previewText.value.toLowerCase();
    const keywords = question
        .toLowerCase()
        .split(/[^\p{L}\p{N}]+/u)
        .filter((word) => word.length >= 4);
    const matched = keywords.filter((word) => context.includes(word));

    if (matched.length > 0) {
        testQuestionResult.value = t('settings.knowledgeBase.testQuestion.keywordsMatched', {
            words: matched.slice(0, 5).join(', '),
        });
        return;
    }

    testQuestionResult.value = t('settings.knowledgeBase.testQuestion.noMatch');
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
        showToast({ message: t('settings.knowledgeBase.toasts.bulkPromptUpdated'), duration: 3000 });
        selectedIds.value = [];
        suggestRagReindex();
    } catch (error: any) {
        showToast({
            message: error?.response?.data?.message || error?.message || t('settings.knowledgeBase.toasts.errorBulk'),
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
        showToast({ message: t('settings.knowledgeBase.toasts.saved'), duration: 3000 });
        suggestRagReindex();
    } catch (error: any) {
        showToast({ message: error?.response?.data?.message || error?.message || t('settings.knowledgeBase.toasts.errorSave'), duration: 4000 });
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
    return value.trim() || (props.section === 'rules'
        ? t('settings.knowledgeBase.quality.ruleTextMissing')
        : t('settings.knowledgeBase.quality.descriptionMissing'));
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
        flags.push(t('settings.knowledgeBase.quality.disabled'));
    }
    if (!item.include_in_prompt) {
        flags.push(t('settings.knowledgeBase.quality.notInAi'));
    }
    if (props.section !== 'rules' && !String(item.description ?? '').trim()) {
        flags.push(t('settings.knowledgeBase.quality.noDescription'));
    }
    if (props.section !== 'rules' && (item.price === null || item.price === undefined || item.price === '')) {
        flags.push(t('settings.knowledgeBase.quality.noPrice'));
    }

    return flags;
}

const numberLocale = computed(() => {
    if (locale.value === 'kk') {
        return 'kk-KZ';
    }
    if (locale.value === 'en') {
        return 'en-US';
    }

    return 'ru-KZ';
});

function formatTenge(price: KnowledgeItem['price']): string {
    if (price === null || price === undefined || price === '') {
        return '—';
    }

    const value = Number(price);
    if (!Number.isFinite(value)) {
        return `${price} ₸`;
    }

    return `${new Intl.NumberFormat(numberLocale.value, { maximumFractionDigits: 2 }).format(value)} ₸`;
}

const detailsPlaceholder = computed(() =>
    props.section === 'services'
        ? t('settings.knowledgeBase.form.detailsPlaceholderServices')
        : t('settings.knowledgeBase.form.detailsPlaceholderProducts'),
);

function detailsTextToObject(value: string): Record<string, unknown> | null {
    const trimmed = value.trim();
    if (trimmed === '' || trimmed === '{}') {
        return null;
    }

    if (trimmed.startsWith('{')) {
        try {
            return JSON.parse(trimmed) as Record<string, unknown>;
        } catch {
            throw new Error(t('settings.knowledgeBase.form.jsonError'));
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
        created: t('settings.knowledgeBase.audit.actions.created'),
        updated: t('settings.knowledgeBase.audit.actions.updated'),
        deleted: t('settings.knowledgeBase.audit.actions.deleted'),
        bulk_prompt: t('settings.knowledgeBase.audit.actions.bulkPrompt'),
    };

    return map[action] ?? action;
}

function formatAuditWhen(iso: string): string {
    const dateLocale = locale.value === 'kk' ? 'kk-KZ' : locale.value === 'en' ? 'en-US' : 'ru-RU';

    try {
        return new Intl.DateTimeFormat(dateLocale, { dateStyle: 'short', timeStyle: 'short' }).format(new Date(iso));
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
            message: error?.response?.data?.message || error?.message || t('settings.knowledgeBase.audit.errorLoad'),
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
        showToast({ message: t('settings.knowledgeBase.deleteModal.nameMismatch'), duration: 4000 });
        return;
    }

    try {
        await axios.delete(route(meta.value.destroy, item.id));
        localItems.value = localItems.value.filter((existing) => existing.id !== item.id);
        closeDeleteModal();
        showToast({ message: t('settings.knowledgeBase.toasts.deleted'), duration: 3000 });
        suggestRagReindex();
    } catch (error: any) {
        showToast({ message: error?.response?.data?.message || t('settings.knowledgeBase.toasts.errorDelete'), duration: 4000 });
    }
}
</script>

<template>
    <Head :title="meta.title" />

    <SettingsLayout :title="meta.title" :subtitle="meta.subtitle">
        <template #actions>
            <button type="button" class="ui-btn ui-btn--primary" @click="openAdd">
                {{ meta.addLabel }}
            </button>
        </template>

        <div class="p-4 md:p-6 space-y-4">
            <section class="kb-hero ui-panel p-4 md:p-5">
                <div class="flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
                    <div class="max-w-3xl">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-[var(--ui-accent)]">{{ t('settings.knowledgeBase.hero.badge') }}</p>
                        <h2 class="mt-1 text-xl font-semibold text-[var(--ui-text)]">{{ meta.title }}</h2>
                        <p class="mt-1 text-sm text-[var(--ui-text-secondary)]">
                            {{ t('settings.knowledgeBase.hero.description') }}
                        </p>
                    </div>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 xl:min-w-[520px]">
                        <div class="kb-stat">
                            <span>{{ t('settings.knowledgeBase.stats.total') }}</span>
                            <strong>{{ localItems.length }}</strong>
                        </div>
                        <div class="kb-stat">
                            <span>{{ t('settings.knowledgeBase.stats.active') }}</span>
                            <strong>{{ activeCount }}</strong>
                        </div>
                        <div class="kb-stat">
                            <span>{{ t('settings.knowledgeBase.stats.inAi') }}</span>
                            <strong>{{ promptCount }}</strong>
                        </div>
                        <div class="kb-stat" :class="missingDescriptionCount + missingPriceCount > 0 ? 'warn' : ''">
                            <span>{{ t('settings.knowledgeBase.stats.gaps') }}</span>
                            <strong>{{ missingDescriptionCount + missingPriceCount }}</strong>
                        </div>
                    </div>
                </div>
            </section>

            <section class="kb-control-panel ui-panel p-3 md:p-4">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <div class="grid flex-1 gap-2 md:grid-cols-[minmax(220px,1fr)_170px_170px]">
                        <label class="kb-search relative block min-w-0">
                            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--ui-text-secondary)]" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 1 1 0-15 7.5 7.5 0 0 1 0 15Z" />
                            </svg>
                            <input
                                v-model="searchQuery"
                                type="search"
                                class="ui-search-input--boxed w-full !pl-10"
                                :placeholder="t('settings.knowledgeBase.searchPlaceholder')"
                            />
                        </label>
                        <select v-model="statusFilter" class="settings-input">
                            <option value="all">{{ t('settings.knowledgeBase.filters.statusAll') }}</option>
                            <option value="active">{{ t('settings.knowledgeBase.filters.statusActive') }}</option>
                            <option value="inactive">{{ t('settings.knowledgeBase.filters.statusInactive') }}</option>
                        </select>
                        <select v-model="promptFilter" class="settings-input">
                            <option value="all">{{ t('settings.knowledgeBase.filters.promptAll') }}</option>
                            <option value="included">{{ t('settings.knowledgeBase.filters.promptIncluded') }}</option>
                            <option value="excluded">{{ t('settings.knowledgeBase.filters.promptExcluded') }}</option>
                        </select>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            class="ui-btn ui-btn--ghost ui-btn--sm"
                            :disabled="previewCompanyId == null || previewLoading"
                            @click="() => loadPreview()"
                        >
                            {{ previewLoading ? t('settings.knowledgeBase.toolbar.previewLoading') : t('settings.knowledgeBase.toolbar.preview') }}
                        </button>
                        <button
                            type="button"
                            class="ui-btn ui-btn--ghost ui-btn--sm"
                            @click="aiToolsOpen = !aiToolsOpen"
                        >
                            {{ aiToolsOpen ? t('settings.knowledgeBase.toolbar.hideAiTools') : t('settings.knowledgeBase.toolbar.showAiTools') }}
                        </button>
                        <button type="button" class="ui-btn ui-btn--primary" @click="openAdd">
                            {{ meta.addLabel }}
                        </button>
                    </div>
                </div>

                <div v-if="selectedIds.length > 0" class="kb-selection-bar mt-3 flex flex-wrap items-center gap-2 rounded-xl px-3 py-2">
                    <span class="text-sm text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.selection.count', { count: selectedIds.length }) }}</span>
                    <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" @click="bulkSetPrompt(true)">{{ t('settings.knowledgeBase.selection.addToAi') }}</button>
                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="bulkSetPrompt(false)">{{ t('settings.knowledgeBase.selection.removeFromAi') }}</button>
                    <button type="button" class="link-btn px-2 text-sm" @click="clearSelection">{{ t('settings.knowledgeBase.selection.clear') }}</button>
                </div>
            </section>

            <section v-if="aiToolsOpen" class="grid gap-4 lg:grid-cols-3">
                <div class="kb-card rounded-2xl border p-4">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.knowledgeBase.readiness.title') }}</h3>
                            <p class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.readiness.subtitle') }}</p>
                        </div>
                        <span
                            class="rounded-full px-2.5 py-1 text-xs"
                            :class="readinessChecks.every((check) => check.ok) ? 'bg-[var(--ui-accent-soft)] text-[var(--ui-accent)]' : 'bg-amber-500/15 text-amber-500'"
                        >
                            {{ readinessChecks.every((check) => check.ok) ? t('settings.knowledgeBase.readiness.ready') : t('settings.knowledgeBase.readiness.needsAttention') }}
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
                            <h3 class="text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.knowledgeBase.rag.title') }}</h3>
                            <p class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.rag.subtitle') }}</p>
                        </div>
                        <button
                            type="button"
                            class="ui-btn ui-btn--ghost ui-btn--sm"
                            :disabled="previewCompanyId == null || reindexLoading"
                            @click="reindexEmbeddings"
                        >
                            {{ reindexLoading ? t('settings.knowledgeBase.rag.reindexing') : t('settings.knowledgeBase.rag.reindex') }}
                        </button>
                    </div>
                    <p v-if="ragStatus" class="kb-inset rounded-lg px-3 py-2 text-xs text-[var(--ui-text-secondary)]">
                        <span v-if="!ragStatus.enabled">{{ t('settings.knowledgeBase.rag.statusDisabled') }}</span>
                        <span v-else-if="ragStatus.ready">{{ t('settings.knowledgeBase.rag.statusReady', { count: ragStatus.with_embedding }) }}</span>
                        <span v-else>{{ t('settings.knowledgeBase.rag.statusNeedsIndex', { indexed: ragStatus.indexed, withEmbedding: ragStatus.with_embedding }) }}</span>
                    </p>
                    <p v-if="ragQuality && ragQuality.scored_chunks > 0" class="mt-2 text-xs text-[var(--ui-text-muted)]">
                        {{ t('settings.knowledgeBase.rag.qualitySummary', {
                            avg: ragQuality.avg_score ?? '—',
                            low: ragQuality.low_quality_count,
                            total: ragQuality.scored_chunks,
                        }) }}
                    </p>
                    <p
                        v-if="ragReindexSuggested && ragStatus?.enabled"
                        class="ui-alert ui-alert--warn kb-inset mt-2 text-xs"
                    >
                        {{ t('settings.knowledgeBase.rag.reindexSuggested') }}
                    </p>
                </div>

                <div class="kb-card rounded-2xl border p-4">
                    <h3 class="text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.knowledgeBase.testQuestion.title') }}</h3>
                    <p class="mt-1 text-xs text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.testQuestion.subtitle') }}</p>
                    <div class="mt-3 flex gap-2">
                        <input
                            v-model="testQuestion"
                            class="settings-input flex-1"
                            type="text"
                            :placeholder="t('settings.knowledgeBase.testQuestion.placeholder')"
                            @keydown.enter.prevent="runTestQuestion"
                        />
                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" :disabled="previewCompanyId == null || previewLoading" @click="runTestQuestion">
                            {{ t('settings.knowledgeBase.testQuestion.check') }}
                        </button>
                    </div>
                    <p v-if="testQuestionResult" class="kb-inset mt-3 rounded-lg px-3 py-2 text-xs text-[var(--ui-text-secondary)]">{{ testQuestionResult }}</p>
                </div>

                <div class="kb-card rounded-2xl border p-4 lg:col-span-3">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.knowledgeBase.catalogAudit.title') }}</h3>
                            <p class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.catalogAudit.subtitle') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="flex items-center gap-1.5 text-xs text-[var(--ui-text-secondary)]">
                                <UiCheckbox v-model="catalogAuditUseLlm" size="sm" />
                                {{ t('settings.knowledgeBase.catalogAudit.aiAnalysis') }}
                            </label>
                            <button
                                type="button"
                                class="ui-btn ui-btn--ghost ui-btn--sm"
                                :disabled="previewCompanyId == null || catalogAuditLoading"
                                @click="loadCatalogAudit"
                            >
                                {{ catalogAuditLoading ? t('settings.knowledgeBase.catalogAudit.checking') : t('settings.knowledgeBase.catalogAudit.refresh') }}
                            </button>
                        </div>
                    </div>
                    <p v-if="catalogAuditLlmUsed" class="mb-2 text-xs text-[var(--ui-accent)]">{{ t('settings.knowledgeBase.catalogAudit.llmReportAdded') }}</p>
                    <p v-if="catalogAuditSummary && catalogAuditSummary.total === 0" class="kb-inset rounded-lg px-3 py-2 text-xs text-[var(--ui-text-secondary)]">
                        {{ t('settings.knowledgeBase.catalogAudit.noIssues') }}
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
                    <p v-else-if="!catalogAuditLoading" class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.catalogAudit.clickRefresh') }}</p>
                </div>

                <div class="kb-card rounded-2xl border p-4 lg:col-span-3">
                    <button type="button" class="flex w-full items-center justify-between gap-3 text-left" @click="toggleAudit">
                        <div>
                            <h3 class="text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.knowledgeBase.audit.title') }}</h3>
                            <p class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.audit.subtitle') }}</p>
                        </div>
                        <span class="text-xs text-[var(--ui-text-secondary)] shrink-0">{{ auditOpen ? t('settings.knowledgeBase.audit.hide') : t('settings.knowledgeBase.audit.show') }}</span>
                    </button>
                    <div v-if="auditOpen" class="mt-3 space-y-2">
                        <p v-if="previewCompanyId == null" class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.audit.unavailable') }}</p>
                        <p v-else-if="auditLoading" class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.audit.loading') }}</p>
                        <ul v-else class="wa-scrollbar max-h-56 space-y-2 overflow-y-auto text-xs">
                            <li v-for="row in auditEntries" :key="row.id" class="kb-inset rounded-lg border px-3 py-2">
                                <div class="flex flex-wrap items-baseline justify-between gap-2">
                                    <span class="font-medium text-[var(--ui-text)]">{{ formatAuditAction(row.action) }} <span class="font-normal text-[var(--ui-text-secondary)]">· {{ row.entity_label || '#' + row.entity_id }}</span></span>
                                    <span class="text-[var(--ui-text-secondary)]">{{ formatAuditWhen(row.created_at) }}</span>
                                </div>
                                <div class="mt-0.5 text-[var(--ui-text-secondary)]">{{ row.user?.name ?? '—' }}</div>
                            </li>
                        </ul>
                        <p v-if="!auditLoading && previewCompanyId != null && auditEntries.length === 0" class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.audit.empty') }}</p>
                    </div>
                </div>
            </section>

            <div v-if="dataWarnings.length > 0" class="ui-alert ui-alert--warn">
                <p class="mb-2 text-xs font-semibold">{{ t('settings.knowledgeBase.warnings.title') }}</p>
                <ul class="space-y-1 text-xs">
                    <li v-for="warning in dataWarnings" :key="warning">• {{ warning }}</li>
                </ul>
            </div>

            <div v-if="localItems.length === 0" class="kb-card rounded-2xl border px-6 py-10 text-center">
                <p class="text-[15px] text-[var(--ui-text)]">{{ t('settings.knowledgeBase.empty.title') }}</p>
                <p class="mx-auto mt-2 max-w-xl text-sm text-[var(--ui-text-secondary)]">
                    {{ t('settings.knowledgeBase.empty.hint') }}
                </p>
                <div class="mt-6 flex flex-wrap justify-center gap-3">
                    <button type="button" class="ui-btn ui-btn--primary" @click="openAdd">{{ meta.addLabel }}</button>
                </div>
                <div class="mt-6 flex flex-wrap justify-center gap-4 text-sm">
                    <Link v-for="link in emptyCrossLinks" :key="link.href" :href="link.href" class="link-btn">{{ link.label }}</Link>
                </div>
            </div>

            <div v-else-if="filteredItems.length === 0" class="kb-card rounded-2xl border px-6 py-8 text-center">
                <p class="text-sm text-[var(--ui-text)]">{{ t('settings.knowledgeBase.filteredEmpty.title') }}</p>
                <button type="button" class="mt-3 text-sm text-[var(--ui-accent)]" @click="searchQuery = ''; statusFilter = 'all'; promptFilter = 'all'">{{ t('settings.knowledgeBase.filteredEmpty.reset') }}</button>
            </div>

            <section v-else class="kb-list-panel ui-panel overflow-hidden">
                    <div class="kb-list-header flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3">
                        <div class="flex items-center gap-2 text-sm text-[var(--ui-text-secondary)]">
                            <UiCheckbox :model-value="allSelected" :aria-label="t('settings.knowledgeBase.list.selectShownAria')" @update:model-value="toggleSelectAll" />
                            <span
                                class="cursor-pointer select-none"
                                role="button"
                                tabindex="0"
                                @click="toggleSelectAll"
                                @keydown.enter.prevent="toggleSelectAll"
                            >
                                {{ t('settings.knowledgeBase.list.selectShown') }}
                            </span>
                        </div>
                    <span class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.list.shownCount', { shown: filteredItems.length, total: localItems.length }) }}</span>
                </div>

                <div class="divide-y divide-[var(--ui-border)]">
                    <article v-for="item in filteredItems" :key="item.id" class="kb-row">
                        <div class="flex items-start gap-3">
                            <UiCheckbox
                                class="mt-0.5"
                                :model-value="selectedIds.includes(item.id)"
                                @update:model-value="toggleRowSelection(item.id)"
                            />
                            <div v-if="section === 'products'" class="kb-product-thumb">
                                <img v-if="item.image_url" :src="item.image_url" :alt="itemTitle(item)" />
                                <span v-else>{{ t('settings.knowledgeBase.list.photo') }}</span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="min-w-0 truncate text-sm font-semibold text-[var(--ui-text)]">{{ itemTitle(item) }}</h3>
                                    <span v-if="item.sku" class="ui-badge ui-badge--neutral">{{ item.sku }}</span>
                                    <span v-for="flag in itemQualityFlags(item)" :key="flag" class="ui-badge ui-badge--warn">{{ flag }}</span>
                                </div>
                                <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-[var(--ui-text-secondary)]">{{ itemDescription(item) }}</p>
                                <div v-if="compactDetails(item).length" class="mt-2 flex flex-wrap gap-1.5">
                                    <span v-for="detail in compactDetails(item)" :key="detail" class="ui-badge ui-badge--neutral text-[11px]">{{ detail }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="kb-row-side">
                            <div class="text-right">
                                <div v-if="section !== 'rules'" class="text-sm font-semibold text-[var(--ui-text)]">{{ formatTenge(item.price) }}</div>
                                <div v-if="section === 'services' && item.duration_minutes" class="text-xs text-[var(--ui-text-secondary)]">{{ t('settings.knowledgeBase.list.minutes', { count: item.duration_minutes }) }}</div>
                            </div>
                            <button
                                type="button"
                                class="ui-chip"
                                :class="{ 'is-active': item.include_in_prompt }"
                                @click="togglePrompt(item)"
                            >
                                {{ item.include_in_prompt ? t('settings.knowledgeBase.list.inAi') : t('settings.knowledgeBase.list.notInAi') }}
                            </button>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="openEdit(item)">{{ t('settings.knowledgeBase.list.edit') }}</button>
                            <button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm" @click="destroyItem(item)">{{ t('settings.knowledgeBase.list.delete') }}</button>
                        </div>
                    </article>
                </div>
            </section>
        </div>

        <UiModal
            :open="!!deleteTarget"
            :title="t('settings.knowledgeBase.deleteModal.title')"
            max-width="md"
            :z-index="55"
            :panel-class="kbModalPanelClass"
            body-class="p-5"
            @close="closeDeleteModal"
        >
            <p class="text-sm text-[var(--ui-text-secondary)]">
                {{ t('settings.knowledgeBase.deleteModal.description') }}
                <span class="font-medium text-[var(--ui-text)]">{{ deleteTarget ? itemTitle(deleteTarget) : '' }}</span>
            </p>
            <input
                v-model="deleteConfirmInput"
                type="text"
                class="settings-input mt-4 w-full"
                :placeholder="t('settings.knowledgeBase.deleteModal.confirmPlaceholder')"
                autocomplete="off"
            />
            <template #footer>
                <button type="button" class="ui-btn ui-btn--secondary" @click="closeDeleteModal">{{ t('common.cancel') }}</button>
                <button type="button" class="ui-btn ui-btn--danger" @click="confirmDelete">{{ t('common.delete') }}</button>
            </template>
        </UiModal>

        <UiModal
            :open="previewOpen"
            max-width="3xl"
            :z-index="60"
            :panel-class="kbModalPanelClass"
            body-class="p-0 flex flex-col min-h-0"
            @close="closePreview"
        >
            <template #header>
                <div>
                    <h3 class="text-lg text-[var(--ui-text)] m-0">{{ t('settings.knowledgeBase.previewModal.title') }}</h3>
                    <p v-if="previewCounts" class="mt-1 text-xs text-[var(--ui-text-secondary)] mb-0">
                        {{ t('settings.knowledgeBase.previewModal.counts', { rules: previewCounts.rules, products: previewCounts.products, services: previewCounts.services }) }}
                        <span v-if="previewUsedRag">{{ t('settings.knowledgeBase.previewModal.ragSuffix') }}</span>
                        <span v-if="previewTruncated">{{ t('settings.knowledgeBase.previewModal.truncated') }}</span>
                    </p>
                </div>
            </template>
            <p v-if="previewHint" class="border-b border-[var(--ui-border)] px-5 py-2 text-xs text-[var(--ui-text-secondary)] shrink-0">{{ previewHint }}</p>
            <div class="wa-scrollbar flex-1 overflow-y-auto px-5 py-4 min-h-0">
                <pre class="kb-pre">{{ previewText }}</pre>
            </div>
        </UiModal>

        <UiModal
            :open="showForm"
            max-width="4xl"
            :panel-class="kbModalPanelClass"
            body-class="p-0 flex flex-col min-h-0"
            @close="closeForm"
        >
            <template #header>
                <div>
                    <h3 class="text-lg font-semibold text-[var(--ui-text)] m-0">{{ editing ? t('settings.knowledgeBase.form.editTitle') : meta.addLabel }}</h3>
                    <p class="mt-1 text-xs text-[var(--ui-text-secondary)] mb-0">
                        {{ t('settings.knowledgeBase.form.hint') }}
                    </p>
                </div>
            </template>

            <div class="wa-scrollbar flex-1 overflow-y-auto px-5 py-4 min-h-0">
                    <div class="grid gap-4 lg:grid-cols-[1fr_320px]">
                        <section class="space-y-4">
                            <div class="kb-form-section ui-settings-section">
                                <h4 class="mb-3 text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.knowledgeBase.form.main') }}</h4>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <label v-if="section !== 'rules'" class="field sm:col-span-2">
                                        <span>{{ section === 'services' ? t('settings.knowledgeBase.form.serviceName') : t('settings.knowledgeBase.form.productName') }}</span>
                                        <input v-model="form.name" type="text" :placeholder="t('settings.knowledgeBase.form.exampleProductName')" />
                                    </label>

                                    <label v-if="section === 'rules'" class="field sm:col-span-2">
                                        <span>{{ t('settings.knowledgeBase.form.ruleTitle') }}</span>
                                        <input v-model="form.title" type="text" :placeholder="t('settings.knowledgeBase.form.exampleRuleTitle')" />
                                    </label>

                                    <label v-if="section === 'products'" class="field">
                                        <span>{{ t('settings.knowledgeBase.form.sku') }} <small>{{ t('settings.knowledgeBase.form.skuOptional') }}</small></span>
                                        <input v-model="form.sku" type="text" :placeholder="t('settings.knowledgeBase.form.exampleSku')" />
                                    </label>

                                    <label v-if="section === 'rules'" class="field">
                                        <span>{{ t('settings.knowledgeBase.form.type') }}</span>
                                        <input v-model="form.type" type="text" placeholder="complaint, refund, warranty, ai_guardrail" />
                                    </label>

                                    <label v-if="section === 'rules'" class="field">
                                        <span>{{ t('settings.knowledgeBase.form.priority') }}</span>
                                        <input v-model.number="form.priority" type="number" min="1" />
                                    </label>

                                    <label v-if="section !== 'rules'" class="field">
                                        <span>{{ t('settings.knowledgeBase.form.price') }}</span>
                                        <input v-model="form.price" type="number" min="0" step="0.01" :placeholder="t('settings.knowledgeBase.form.pricePlaceholder')" />
                                    </label>

                                    <label v-if="section === 'services'" class="field">
                                        <span>{{ t('settings.knowledgeBase.form.duration') }}</span>
                                        <input v-model="form.duration_minutes" type="number" min="1" :placeholder="t('settings.knowledgeBase.form.exampleDuration')" />
                                    </label>
                                </div>
                            </div>

                            <div class="kb-form-section ui-settings-section">
                                <h4 class="mb-1 text-sm font-semibold text-[var(--ui-text)]">
                                    {{ section === 'rules' ? t('settings.knowledgeBase.form.ruleContent') : t('settings.knowledgeBase.form.description') }}
                                </h4>
                                <p class="mb-3 text-xs text-[var(--ui-text-secondary)]">
                                    {{ section === 'rules' ? t('settings.knowledgeBase.form.ruleContentHint') : t('settings.knowledgeBase.form.descriptionHint') }}
                                </p>
                                <label class="field">
                                    <textarea
                                        v-if="section === 'rules'"
                                        v-model="form.content"
                                        rows="7"
                                        :placeholder="t('settings.knowledgeBase.form.ruleContentPlaceholder')"
                                    ></textarea>
                                    <textarea
                                        v-else
                                        v-model="form.description"
                                        rows="7"
                                        :placeholder="t('settings.knowledgeBase.form.descriptionPlaceholder')"
                                    ></textarea>
                                </label>
                            </div>

                            <div v-if="section === 'products'" class="kb-form-section ui-settings-section">
                                <h4 class="mb-1 text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.knowledgeBase.form.photoTitle') }}</h4>
                                <p class="mb-3 text-xs text-[var(--ui-text-secondary)]">
                                    {{ t('settings.knowledgeBase.form.photoHint') }}
                                </p>
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                    <div class="kb-product-photo-preview">
                                        <img v-if="productImagePreview" :src="productImagePreview" :alt="t('settings.knowledgeBase.form.photoTitle')" />
                                        <span v-else>{{ t('settings.knowledgeBase.form.noPhoto') }}</span>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <label class="ui-btn ui-btn--ghost ui-btn--sm cursor-pointer">
                                            {{ t('settings.knowledgeBase.form.pickPhoto') }}
                                            <input class="sr-only" type="file" accept="image/jpeg,image/png,image/webp" @change="selectProductImage" />
                                        </label>
                                        <button
                                            v-if="productImagePreview || editing?.image_url"
                                            type="button"
                                            class="ui-btn ui-btn--ghost ui-btn--sm"
                                            @click="removeProductImage"
                                        >
                                            {{ t('settings.knowledgeBase.form.removePhoto') }}
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div v-if="section !== 'rules'" class="kb-form-section ui-settings-section">
                                <h4 class="mb-1 text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.knowledgeBase.form.factsTitle') }}</h4>
                                <p class="mb-3 text-xs text-[var(--ui-text-secondary)]">
                                    {{ t('settings.knowledgeBase.form.factsHint') }}
                                </p>
                                <label class="field">
                                    <textarea v-model="detailsText" rows="6" spellcheck="false" :placeholder="detailsPlaceholder"></textarea>
                                </label>
                            </div>
                        </section>

                        <aside class="space-y-4">
                            <div class="kb-form-section ui-settings-section">
                                <h4 class="mb-3 text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.knowledgeBase.form.publish') }}</h4>
                                <div class="space-y-3">
                                    <div class="ui-check-row">
                                        <span>{{ t('settings.knowledgeBase.form.includeInAi') }}</span>
                                        <UiCheckbox v-model="form.include_in_prompt" :aria-label="t('settings.knowledgeBase.form.includeInAiAria')" />
                                    </div>
                                    <div class="ui-check-row">
                                        <span>{{ t('settings.knowledgeBase.form.active') }}</span>
                                        <UiCheckbox v-model="form.is_active" :aria-label="t('settings.knowledgeBase.form.activeAria')" />
                                    </div>
                                    <label v-if="section !== 'rules'" class="field">
                                        <span>{{ t('settings.knowledgeBase.form.sortOrder') }}</span>
                                        <input v-model.number="form.sort_order" type="number" min="0" />
                                        <p class="field-help">{{ t('settings.knowledgeBase.form.sortOrderHint') }}</p>
                                    </label>
                                </div>
                            </div>

                            <div class="kb-form-section ui-settings-section">
                                <h4 class="mb-2 text-sm font-semibold text-[var(--ui-text)]">{{ t('settings.knowledgeBase.form.tipsTitle') }}</h4>
                                <ul class="space-y-2 text-xs leading-relaxed text-[var(--ui-text-secondary)]">
                                    <li>{{ t('settings.knowledgeBase.form.tip1') }}</li>
                                    <li>{{ t('settings.knowledgeBase.form.tip2') }}</li>
                                    <li>{{ t('settings.knowledgeBase.form.tip3') }}</li>
                                </ul>
                            </div>
                        </aside>
                    </div>
                </div>

            <template #footer>
                <button type="button" class="ui-btn ui-btn--secondary" @click="closeForm">{{ t('common.cancel') }}</button>
                <button type="button" class="ui-btn ui-btn--primary" @click="save">{{ t('common.save') }}</button>
            </template>
        </UiModal>
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
    background: color-mix(in srgb, var(--ui-accent) 6%, var(--ui-surface));
    border-color: var(--ui-accent-border);
}

.kb-list-header {
    background: var(--ui-surface-muted);
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
    border-radius: var(--primitive-radius-lg, 12px);
    background: var(--ui-surface-muted);
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
    color: var(--wa-chroma-yellow-fg);
}

.kb-stat:hover {
    border-color: var(--ui-accent-border);
}

.kb-search input {
    color: var(--ui-text);
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
    background: var(--ui-surface-hover);
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
    background: var(--ui-surface-muted);
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
