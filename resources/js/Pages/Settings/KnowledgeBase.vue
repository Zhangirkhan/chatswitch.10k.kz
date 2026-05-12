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
const jsonText = ref('{}');
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
        title: 'База знаний: товары',
        subtitle: 'Каталог товаров, которые AI может учитывать в промпте',
        addLabel: 'Добавить товар',
        route: 'settings.knowledge.products',
        store: 'settings.knowledge.products.store',
        update: 'settings.knowledge.products.update',
        destroy: 'settings.knowledge.products.destroy',
    },
    services: {
        title: 'База знаний: услуги',
        subtitle: 'Услуги, длительность, цены и условия',
        addLabel: 'Добавить услугу',
        route: 'settings.knowledge.services',
        store: 'settings.knowledge.services.store',
        update: 'settings.knowledge.services.update',
        destroy: 'settings.knowledge.services.destroy',
    },
    rules: {
        title: 'База знаний: правила ответа',
        subtitle: 'Часы работы, ограничения, тон и политика AI',
        addLabel: 'Добавить правило',
        route: 'settings.knowledge.rules',
        store: 'settings.knowledge.rules.store',
        update: 'settings.knowledge.rules.update',
        destroy: 'settings.knowledge.rules.destroy',
    },
}[props.section]));

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
    jsonText.value = '{}';
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
    jsonText.value = JSON.stringify(props.section === 'services' ? (item.conditions ?? {}) : (item.attributes ?? {}), null, 2);
    showForm.value = true;
}

function payload(): Record<string, unknown> {
    let jsonPayload: Record<string, unknown> | null = null;
    const trimmedJson = jsonText.value.trim();
    if (trimmedJson !== '' && trimmedJson !== '{}') {
        try {
            jsonPayload = JSON.parse(trimmedJson);
        } catch {
            throw new Error('Проверьте блок "Дополнительно": характеристики должны быть в формате JSON или оставьте {}.');
        }
    }

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
            conditions: jsonPayload,
            sort_order: form.value.sort_order,
        };
    }

    return {
        ...base,
        name: form.value.name.trim(),
        sku: form.value.sku.trim() || null,
        description: form.value.description.trim() || null,
        attributes: jsonPayload,
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
    if (!confirm('Удалить запись из базы знаний?')) return;

    try {
        await axios.delete(route(meta.value.destroy, item.id));
        localItems.value = localItems.value.filter((existing) => existing.id !== item.id);
        showToast({ message: 'Запись удалена', duration: 3000 });
    } catch (error: any) {
        showToast({ message: error?.response?.data?.message || 'Не удалось удалить запись', duration: 4000 });
    }
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

const jsonPlaceholder = computed(() => {
    if (props.section === 'services') {
        return '{\n  "место": "салон",\n  "предоплата": "не требуется"\n}';
    }

    return '{\n  "цвет": "черный",\n  "размер": "42",\n  "материал": "кожа"\n}';
});
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
            <div class="flex gap-2">
                <Link :href="route('settings.knowledge.products')" class="tab" :class="{ active: section === 'products' }">Товары</Link>
                <Link :href="route('settings.knowledge.services')" class="tab" :class="{ active: section === 'services' }">Услуги</Link>
                <Link :href="route('settings.knowledge.rules')" class="tab" :class="{ active: section === 'rules' }">Правила ответа</Link>
            </div>

            <div class="overflow-hidden rounded-xl border border-[var(--wa-border)] bg-[var(--wa-panel)]">
                <table class="w-full text-sm">
                    <thead class="text-left text-[var(--wa-text-secondary)] border-b border-[var(--wa-border)]">
                        <tr>
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
                            <td class="px-4 py-3 text-[var(--wa-text)]">
                                <div class="font-medium">{{ itemTitle(item) }}</div>
                                <div class="text-xs text-[var(--wa-text-secondary)] truncate max-w-[520px]">
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
                            <td class="px-4 py-3 text-right space-x-2">
                                <button type="button" class="link-btn" @click="openEdit(item)">Изменить</button>
                                <button type="button" class="link-btn danger" @click="destroyItem(item)">Удалить</button>
                            </td>
                        </tr>
                        <tr v-if="localItems.length === 0">
                            <td colspan="6" class="px-4 py-10 text-center text-[var(--wa-text-secondary)]">
                                Пока нет записей.
                            </td>
                        </tr>
                    </tbody>
                </table>
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
                        <input v-model="form.sku" type="text" placeholder="Например: TAPKI-001" />
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
                                <textarea v-model="jsonText" rows="5" spellcheck="false" :placeholder="jsonPlaceholder"></textarea>
                                <p class="field-help">
                                    Для обычного заполнения оставьте {}. Это поле нужно только для дополнительных деталей, которые AI должен учитывать.
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
.tab {
    border: 1px solid var(--wa-border);
    border-radius: 999px;
    color: var(--wa-text-secondary);
    padding: 8px 14px;
}

.tab.active {
    background: var(--wa-green);
    border-color: var(--wa-green);
    color: white;
}

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
