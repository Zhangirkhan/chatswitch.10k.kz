<script setup lang="ts">
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface PlanRow {
    id: number;
    code: string;
    name: string;
    price_cents: number;
    currency: string;
    interval: string;
    trial_days: number;
    is_active: boolean;
}

defineProps<{ plans: PlanRow[] }>();

const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingPlan = ref<PlanRow | null>(null);
const editPriceTenge = ref(40_000);

const defaultForm = () => ({
    code: '',
    name: '',
    price_cents: 4_000_000,
    currency: 'KZT',
    interval: 'month' as 'month' | 'year',
    trial_days: 14,
    is_active: true,
});

const form = useForm(defaultForm());
const editForm = useForm(defaultForm());

const priceTenge = ref(40_000);

const pricePreview = computed(() => {
    const tenge = Math.max(0, Number(priceTenge.value) || 0);
    return new Intl.NumberFormat('ru-RU').format(tenge) + ' ₸';
});

function formatPrice(cents: number): string {
    const tenge = Math.round(cents / 100);
    return new Intl.NumberFormat('ru-RU').format(tenge) + ' ₸';
}

function openCreateModal(): void {
    form.clearErrors();
    form.reset();
    Object.assign(form, defaultForm());
    priceTenge.value = 40_000;
    showCreateModal.value = true;
}

function closeCreateModal(): void {
    if (form.processing) return;
    showCreateModal.value = false;
}

function syncPriceCents(): void {
    const tenge = Math.max(0, Number(priceTenge.value) || 0);
    form.price_cents = Math.round(tenge * 100);
}

watch(priceTenge, syncPriceCents, { immediate: true });

function submitCreate(): void {
    syncPriceCents();
    form.post('/plans', {
        preserveScroll: true,
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
            Object.assign(form, defaultForm());
            priceTenge.value = 40_000;
        },
    });
}

function openEditModal(plan: PlanRow): void {
    editingPlan.value = plan;
    editForm.clearErrors();
    editForm.code = plan.code;
    editForm.name = plan.name;
    editForm.price_cents = plan.price_cents;
    editForm.currency = plan.currency;
    editForm.interval = plan.interval as 'month' | 'year';
    editForm.trial_days = plan.trial_days;
    editForm.is_active = plan.is_active;
    editPriceTenge.value = Math.round(plan.price_cents / 100);
    showEditModal.value = true;
}

function closeEditModal(): void {
    if (editForm.processing) return;
    showEditModal.value = false;
    editingPlan.value = null;
}

function syncEditPriceCents(): void {
    const tenge = Math.max(0, Number(editPriceTenge.value) || 0);
    editForm.price_cents = Math.round(tenge * 100);
}

watch(editPriceTenge, syncEditPriceCents);

function submitEdit(): void {
    if (!editingPlan.value) return;
    syncEditPriceCents();
    editForm.put(`/plans/${editingPlan.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            editingPlan.value = null;
        },
    });
}
</script>

<template>
    <SuperAdminLayout>
        <Head title="Тарифы" />

        <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="mb-1 text-2xl font-bold">Тарифы</h1>
                <p class="text-sm text-ui-text-secondary">
                    Базовый тариф: <strong class="text-ui-accent">40 000 ₸ / месяц</strong>, триал
                    <strong class="text-ui-text">14 дней</strong>.
                </p>
            </div>
            <button type="button" class="ui-btn ui-btn--primary" @click="openCreateModal">
                Добавить тариф
            </button>
        </div>

        <div v-if="plans.length === 0" class="ui-empty-state ui-empty-state--dashed">
            <p>Тарифов пока нет</p>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm mt-4" @click="openCreateModal">
                Создать первый тариф
            </button>
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="p in plans" :key="p.id" class="ui-panel px-4 py-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="font-medium">{{ p.name }}</div>
                    <span
                        class="ui-badge"
                        :class="p.is_active ? 'ui-badge--success' : 'ui-badge--neutral'"
                    >
                        {{ p.is_active ? 'активен' : 'выкл' }}
                    </span>
                </div>
                <div class="mt-1 font-mono text-xs text-ui-text-muted">{{ p.code }}</div>
                <div class="mt-2 text-lg font-semibold text-ui-accent">
                    {{ formatPrice(p.price_cents) }}
                    <span class="text-sm font-normal text-ui-text-secondary">/ {{ p.interval === 'month' ? 'мес.' : 'год' }}</span>
                </div>
                <div class="mt-1 text-xs text-ui-text-muted">Триал: {{ p.trial_days }} дн. · {{ p.currency }}</div>
                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm mt-3" @click="openEditModal(p)">
                    Редактировать
                </button>
            </div>
        </div>

        <UiModal
            :open="showEditModal"
            title="Редактировать тариф"
            :subtitle="editingPlan?.name ?? ''"
            max-width="md"
            @close="closeEditModal"
        >
            <form id="plan-edit-form" class="space-y-4 px-5 py-4" @submit.prevent="submitEdit">
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">Код</span>
                    <input v-model="editForm.code" type="text" required class="ui-input mt-1" />
                    <p v-if="editForm.errors.code" class="mt-1 text-xs text-ui-danger">{{ editForm.errors.code }}</p>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">Название</span>
                    <input v-model="editForm.name" type="text" required class="ui-input mt-1" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">Цена, ₸</span>
                    <input v-model.number="editPriceTenge" type="number" min="0" class="ui-input mt-1" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">Триал, дней</span>
                    <input v-model.number="editForm.trial_days" type="number" min="0" max="90" class="ui-input mt-1" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">Период</span>
                    <select v-model="editForm.interval" class="ui-select mt-1 w-full">
                        <option value="month">Ежемесячно</option>
                        <option value="year">Ежегодно</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <UiCheckbox v-model="editForm.is_active" size="sm" />
                    Тариф активен
                </label>
            </form>
            <template #footer>
                <button type="button" class="ui-btn ui-btn--secondary" @click="closeEditModal">Отмена</button>
                <button type="submit" form="plan-edit-form" class="ui-btn ui-btn--primary" :disabled="editForm.processing">
                    Сохранить
                </button>
            </template>
        </UiModal>

        <UiModal
            :open="showCreateModal"
            title="Новый тариф"
            subtitle="Тариф появится в списке и будет доступен при создании компаний."
            max-width="md"
            @close="closeCreateModal"
        >
            <form id="plan-create-form" class="space-y-4 px-5 py-4" @submit.prevent="submitCreate">
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">Код (латиница)</span>
                    <input
                        v-model="form.code"
                        type="text"
                        required
                        pattern="[a-z0-9_-]+"
                        placeholder="standard"
                        class="ui-input mt-1"
                        autocomplete="off"
                    />
                    <p v-if="form.errors.code" class="mt-1 text-xs text-ui-danger">{{ form.errors.code }}</p>
                </label>

                <label class="block text-sm">
                    <span class="text-ui-text-secondary">Название</span>
                    <input
                        v-model="form.name"
                        type="text"
                        required
                        placeholder="Стандарт"
                        class="ui-input mt-1"
                    />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-ui-danger">{{ form.errors.name }}</p>
                </label>

                <label class="block text-sm">
                    <span class="text-ui-text-secondary">Цена, ₸ / месяц</span>
                    <input
                        v-model.number="priceTenge"
                        type="number"
                        min="0"
                        step="1000"
                        required
                        class="ui-input mt-1"
                    />
                    <p class="mt-1 text-xs text-ui-text-muted">Отображение: {{ pricePreview }}</p>
                    <p v-if="form.errors.price_cents" class="mt-1 text-xs text-ui-danger">{{ form.errors.price_cents }}</p>
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="text-ui-text-secondary">Триал, дней</span>
                        <input
                            v-model.number="form.trial_days"
                            type="number"
                            min="0"
                            max="90"
                            required
                            class="ui-input mt-1"
                        />
                        <p v-if="form.errors.trial_days" class="mt-1 text-xs text-ui-danger">{{ form.errors.trial_days }}</p>
                    </label>

                    <label class="block text-sm">
                        <span class="text-ui-text-secondary">Период</span>
                        <select v-model="form.interval" class="ui-select mt-1 w-full">
                            <option value="month">Ежемесячно</option>
                            <option value="year">Ежегодно</option>
                        </select>
                    </label>
                </div>

                <label class="flex items-center gap-2 text-sm text-ui-text-secondary">
                    <UiCheckbox v-model="form.is_active" size="sm" />
                    Тариф активен (доступен для выбора)
                </label>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="ui-btn ui-btn--secondary"
                    :disabled="form.processing"
                    @click="closeCreateModal"
                >
                    Отмена
                </button>
                <button
                    type="submit"
                    form="plan-create-form"
                    class="ui-btn ui-btn--primary"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Сохранение…' : 'Создать тариф' }}
                </button>
            </template>
        </UiModal>
    </SuperAdminLayout>
</template>
