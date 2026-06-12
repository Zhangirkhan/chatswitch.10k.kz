<script setup lang="ts">
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import SuperAdminPageHeader from '@/Components/SuperAdmin/SuperAdminPageHeader.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';

const { t } = useI18n();

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
    interval: 'month' as 'month' | 'year' | 'once',
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

function formatInterval(interval: string): string {
    if (interval === 'once') {
        return t('superAdmin.plans.intervalOnce');
    }

    if (interval === 'year') {
        return t('superAdmin.plans.intervalYear');
    }

    return t('superAdmin.plans.intervalMonth');
}

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
    editForm.interval = plan.interval as 'month' | 'year' | 'once';
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
        <Head :title="t('superAdmin.plans.pageTitle')" />

        <SuperAdminPageHeader
            accent-group="billing"
            :eyebrow="t('superAdmin.layout.navGroups.billing')"
            :title="t('superAdmin.plans.heading')"
            :subtitle="t('superAdmin.plans.defaultHint', { price: '40 000 ₸', days: 14 })"
        >
            <template #actions>
                <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" @click="openCreateModal">
                    {{ t('superAdmin.plans.addPlan') }}
                </button>
            </template>
        </SuperAdminPageHeader>

        <div v-if="plans.length === 0" class="ui-empty-state ui-empty-state--dashed">
            <p>{{ t('superAdmin.plans.empty') }}</p>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm mt-4" @click="openCreateModal">
                {{ t('superAdmin.plans.createFirst') }}
            </button>
        </div>

        <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <div v-for="p in plans" :key="p.id" class="ui-super-admin-plan-card">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="font-medium">{{ p.name }}</div>
                    <span
                        class="ui-badge"
                        :class="p.is_active ? 'ui-badge--success' : 'ui-badge--neutral'"
                    >
                        {{ p.is_active ? t('superAdmin.plans.statusActive') : t('superAdmin.plans.statusInactive') }}
                    </span>
                </div>
                <div class="mt-1 font-mono text-xs text-ui-text-muted">{{ p.code }}</div>
                <div class="mt-2 text-lg font-semibold text-ui-accent">
                    {{ formatPrice(p.price_cents) }}
                    <span class="text-sm font-normal text-ui-text-secondary">
                        / {{ formatInterval(p.interval) }}
                    </span>
                </div>
                <div class="mt-1 text-xs text-ui-text-muted">
                    {{ t('superAdmin.plans.trialDaysWithCurrency', { days: p.trial_days, currency: p.currency }) }}
                </div>
                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm mt-3" @click="openEditModal(p)">
                    {{ t('superAdmin.plans.edit') }}
                </button>
            </div>
        </div>

        <UiModal
            :open="showEditModal"
            :title="t('superAdmin.plans.editModalTitle')"
            :subtitle="editingPlan?.name ?? ''"
            max-width="md"
            @close="closeEditModal"
        >
            <form id="plan-edit-form" class="space-y-4 px-5 py-4" @submit.prevent="submitEdit">
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.plans.fieldCode') }}</span>
                    <input v-model="editForm.code" type="text" required class="ui-input mt-1" />
                    <p v-if="editForm.errors.code" class="mt-1 text-xs text-ui-danger">{{ editForm.errors.code }}</p>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.plans.fieldName') }}</span>
                    <input v-model="editForm.name" type="text" required class="ui-input mt-1" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.plans.fieldPrice') }}</span>
                    <input v-model.number="editPriceTenge" type="number" min="0" class="ui-input mt-1" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.plans.fieldTrialDays') }}</span>
                    <input v-model.number="editForm.trial_days" type="number" min="0" max="90" class="ui-input mt-1" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.plans.fieldInterval') }}</span>
                    <select v-model="editForm.interval" class="ui-select mt-1 w-full">
                        <option value="month">{{ t('superAdmin.plans.intervalMonthly') }}</option>
                        <option value="year">{{ t('superAdmin.plans.intervalYearly') }}</option>
                        <option value="once">{{ t('superAdmin.plans.intervalOneTime') }}</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <UiCheckbox v-model="editForm.is_active" size="sm" />
                    {{ t('superAdmin.plans.fieldIsActive') }}
                </label>
            </form>
            <template #footer>
                <button type="button" class="ui-btn ui-btn--secondary" @click="closeEditModal">{{ t('superAdmin.common.cancel') }}</button>
                <button type="submit" form="plan-edit-form" class="ui-btn ui-btn--primary" :disabled="editForm.processing">
                    {{ t('superAdmin.common.save') }}
                </button>
            </template>
        </UiModal>

        <UiModal
            :open="showCreateModal"
            :title="t('superAdmin.plans.createModalTitle')"
            :subtitle="t('superAdmin.plans.createModalSubtitle')"
            max-width="md"
            @close="closeCreateModal"
        >
            <form id="plan-create-form" class="space-y-4 px-5 py-4" @submit.prevent="submitCreate">
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.plans.fieldCodeLatin') }}</span>
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
                    <span class="text-ui-text-secondary">{{ t('superAdmin.plans.fieldName') }}</span>
                    <input
                        v-model="form.name"
                        type="text"
                        required
                        :placeholder="t('superAdmin.plans.placeholderName')"
                        class="ui-input mt-1"
                    />
                    <p v-if="form.errors.name" class="mt-1 text-xs text-ui-danger">{{ form.errors.name }}</p>
                </label>

                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.plans.fieldPricePerMonth') }}</span>
                    <input
                        v-model.number="priceTenge"
                        type="number"
                        min="0"
                        step="1000"
                        required
                        class="ui-input mt-1"
                    />
                    <p class="mt-1 text-xs text-ui-text-muted">{{ t('superAdmin.plans.pricePreview', { price: pricePreview }) }}</p>
                    <p v-if="form.errors.price_cents" class="mt-1 text-xs text-ui-danger">{{ form.errors.price_cents }}</p>
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="text-ui-text-secondary">{{ t('superAdmin.plans.fieldTrialDays') }}</span>
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
                        <span class="text-ui-text-secondary">{{ t('superAdmin.plans.fieldInterval') }}</span>
                        <select v-model="form.interval" class="ui-select mt-1 w-full">
                            <option value="month">{{ t('superAdmin.plans.intervalMonthly') }}</option>
                            <option value="year">{{ t('superAdmin.plans.intervalYearly') }}</option>
                            <option value="once">{{ t('superAdmin.plans.intervalOneTime') }}</option>
                        </select>
                    </label>
                </div>

                <label class="flex items-center gap-2 text-sm text-ui-text-secondary">
                    <UiCheckbox v-model="form.is_active" size="sm" />
                    {{ t('superAdmin.plans.fieldIsActiveSelectable') }}
                </label>
            </form>

            <template #footer>
                <button
                    type="button"
                    class="ui-btn ui-btn--secondary"
                    :disabled="form.processing"
                    @click="closeCreateModal"
                >
                    {{ t('superAdmin.common.cancel') }}
                </button>
                <button
                    type="submit"
                    form="plan-create-form"
                    class="ui-btn ui-btn--primary"
                    :disabled="form.processing"
                >
                    {{ form.processing ? t('superAdmin.common.saving') : t('superAdmin.plans.createSubmit') }}
                </button>
            </template>
        </UiModal>
    
    </SuperAdminLayout>
</template>
