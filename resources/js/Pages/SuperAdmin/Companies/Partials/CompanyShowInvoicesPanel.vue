<script setup lang="ts">
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import InputError from '@/Components/InputError.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiFilterField from '@/Components/Ui/UiFilterField.vue';
import UiFilterPanel from '@/Components/Ui/UiFilterPanel.vue';
import { useI18n } from '@/composables/useI18n';
import {
    invoiceStatusBadgeClass,
    invoiceStatusLabel,
    paymentMethodLabel,
} from '@/utils/superAdminInvoiceBadge';
import { router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const { t } = useI18n();

interface PaymentRow {
    id: number;
    amount_cents: number;
    method: string;
    paid_at: string;
}

export interface InvoiceRow {
    id: number;
    number: string;
    amount_cents: number;
    status: string;
    issued_at: string | null;
    paid_at: string | null;
    notes: string | null;
    payments: PaymentRow[];
}

const props = defineProps<{
    companyId: number;
    invoices: InvoiceRow[];
    defaultAmountCents: number;
}>();

const filterUnpaidOnly = ref(false);
const filterFrom = ref('');
const filterTo = ref('');

const paymentTargetId = ref<number | null>(null);

const invoiceAmountTenge = ref(Math.round(props.defaultAmountCents / 100));
const invoiceForm = useForm({
    number: '',
    amount_cents: props.defaultAmountCents,
    currency: 'KZT',
    notes: '',
    send_email: false,
});

watch(invoiceAmountTenge, (tenge) => {
    invoiceForm.amount_cents = Math.max(0, Math.round((Number(tenge) || 0) * 100));
});

const filteredInvoices = computed(() => {
    let list = [...props.invoices];
    if (filterUnpaidOnly.value) {
        list = list.filter((i) => i.status === 'issued');
    }
    if (filterFrom.value) {
        const from = new Date(filterFrom.value).getTime();
        list = list.filter((i) => i.issued_at && new Date(i.issued_at).getTime() >= from);
    }
    if (filterTo.value) {
        const to = new Date(filterTo.value).getTime() + 86400000;
        list = list.filter((i) => i.issued_at && new Date(i.issued_at).getTime() < to);
    }
    return list;
});

function formatPrice(cents: number): string {
    return new Intl.NumberFormat('ru-RU').format(Math.round(cents / 100)) + ' ₸';
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

function suggestedInvoiceNumber(): string {
    const y = new Date().getFullYear();
    const seq = String(props.invoices.length + 1).padStart(3, '0');
    return `INV-${y}-${seq}`;
}

function initInvoiceForm(): void {
    invoiceForm.number = suggestedInvoiceNumber();
    invoiceAmountTenge.value = Math.round(props.defaultAmountCents / 100);
    invoiceForm.amount_cents = props.defaultAmountCents;
}

initInvoiceForm();

function submitInvoice(): void {
    invoiceForm.post(`/companies/${props.companyId}/invoices`, {
        preserveScroll: true,
        onSuccess: () => {
            invoiceForm.reset();
            initInvoiceForm();
        },
    });
}

const voidTarget = ref<InvoiceRow | null>(null);
const showVoidConfirm = ref(false);

function requestVoidInvoice(inv: InvoiceRow): void {
    voidTarget.value = inv;
    showVoidConfirm.value = true;
}

function confirmVoidInvoice(): void {
    const inv = voidTarget.value;
    if (!inv) return;
    router.put(`/invoices/${inv.id}`, { status: 'void' }, {
        preserveScroll: true,
        onFinish: () => {
            showVoidConfirm.value = false;
            voidTarget.value = null;
        },
    });
}

function emailInvoice(inv: InvoiceRow): void {
    router.post(`/invoices/${inv.id}/email`, {}, { preserveScroll: true });
}

const paymentForm = useForm({
    amount_cents: 0,
    method: 'bank_transfer' as 'bank_transfer' | 'kaspi' | 'cash' | 'other',
    external_ref: '',
});

const paymentAmountTenge = computed({
    get: () => Math.round(paymentForm.amount_cents / 100),
    set: (v: number) => {
        paymentForm.amount_cents = Math.max(0, Math.round((Number(v) || 0) * 100));
    },
});

function openPaymentForm(inv: InvoiceRow): void {
    paymentTargetId.value = inv.id;
    paymentForm.amount_cents = inv.amount_cents;
    paymentForm.external_ref = '';
}

function submitPayment(invoiceId: number): void {
    paymentForm.post(`/invoices/${invoiceId}/payments`, {
        preserveScroll: true,
        onSuccess: () => {
            paymentTargetId.value = null;
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <UiFilterPanel as="div" compact>
            <UiFilterField inline wide>
                <UiCheckbox v-model="filterUnpaidOnly" size="sm" />
                <span class="ui-filter-field__label">{{ t('superAdmin.companies.invoices.filterUnpaidOnly') }}</span>
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.invoices.filterFrom')">
                <input v-model="filterFrom" type="date" class="ui-input" />
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.invoices.filterTo')">
                <input v-model="filterTo" type="date" class="ui-input" />
            </UiFilterField>
        </UiFilterPanel>

        <div class="ui-panel overflow-hidden p-0">
            <div class="border-b border-ui-border px-4 py-3">
                <h2 class="font-medium">{{ t('superAdmin.companies.invoices.title', { count: filteredInvoices.length }) }}</h2>
            </div>
            <div class="ui-table-panel">
                <table class="min-w-[800px] w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th>{{ t('superAdmin.companies.invoices.tableNumber') }}</th>
                            <th>{{ t('superAdmin.companies.invoices.tableAmount') }}</th>
                            <th>{{ t('superAdmin.companies.invoices.tableStatus') }}</th>
                            <th>{{ t('superAdmin.companies.invoices.tableIssued') }}</th>
                            <th class="text-right">{{ t('superAdmin.common.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="inv in filteredInvoices" :key="inv.id">
                            <tr>
                                <td class="!text-ui-text font-medium">{{ inv.number }}</td>
                                <td>{{ formatPrice(inv.amount_cents) }}</td>
                                <td>
                                    <span :class="invoiceStatusBadgeClass(inv.status)">
                                        {{ invoiceStatusLabel(inv.status, t) }}
                                    </span>
                                </td>
                                <td class="!text-ui-text-muted">{{ formatDate(inv.issued_at) }}</td>
                                <td class="text-right">
                                    <div class="flex flex-wrap justify-end gap-1">
                                        <a
                                            :href="`/invoices/${inv.id}/print`"
                                            target="_blank"
                                            rel="noopener"
                                            class="ui-btn ui-btn--ghost ui-btn--sm"
                                        >
                                            {{ t('superAdmin.companies.invoices.openPdf') }}
                                        </a>
                                        <button
                                            type="button"
                                            class="ui-btn ui-btn--ghost ui-btn--sm"
                                            @click="emailInvoice(inv)"
                                        >
                                            Email
                                        </button>
                                        <button
                                            v-if="inv.status === 'issued'"
                                            type="button"
                                            class="ui-btn ui-btn--primary ui-btn--sm"
                                            @click="openPaymentForm(inv)"
                                        >
                                            {{ t('superAdmin.companies.invoices.pay') }}
                                        </button>
                                        <button
                                            v-if="inv.status === 'issued'"
                                            type="button"
                                            class="ui-btn ui-btn--ghost ui-btn--sm"
                                            @click="requestVoidInvoice(inv)"
                                        >
                                            {{ t('superAdmin.companies.invoices.void') }}
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <tr v-if="inv.payments.length > 0">
                                <td colspan="5" class="!bg-ui-surface-inset !py-2 !text-xs text-ui-text-secondary">
                                    <span
                                        v-for="(p, idx) in inv.payments"
                                        :key="p.id"
                                        class="mr-2"
                                    >
                                        {{ formatPrice(p.amount_cents) }} · {{ paymentMethodLabel(p.method, t) }} · {{ formatDate(p.paid_at) }}<template v-if="idx < inv.payments.length - 1">;</template>
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="paymentTargetId === inv.id">
                                <td colspan="5" class="!bg-ui-surface-muted">
                                    <form class="mx-auto max-w-lg space-y-3 py-2" @submit.prevent="submitPayment(inv.id)">
                                        <p class="text-sm font-medium">{{ t('superAdmin.companies.invoices.paymentFormTitle', { number: inv.number }) }}</p>
                                        <p class="text-xs text-ui-text-muted">
                                            {{ t('superAdmin.companies.invoices.paymentFormHint') }}
                                        </p>
                                        <div class="grid gap-3 sm:grid-cols-2">
                                            <label class="block text-sm">
                                                <span class="text-ui-text-secondary">{{ t('superAdmin.companies.invoices.paymentAmount') }}</span>
                                                <input v-model.number="paymentAmountTenge" type="number" min="0" class="ui-input mt-1" required />
                                            </label>
                                            <label class="block text-sm">
                                                <span class="text-ui-text-secondary">{{ t('superAdmin.companies.invoices.paymentMethod') }}</span>
                                                <select v-model="paymentForm.method" class="ui-select mt-1 w-full">
                                                    <option value="bank_transfer">{{ t('superAdmin.invoice.paymentMethod.bank_transfer') }}</option>
                                                    <option value="kaspi">{{ t('superAdmin.invoice.paymentMethod.kaspi') }}</option>
                                                    <option value="cash">{{ t('superAdmin.invoice.paymentMethod.cash') }}</option>
                                                    <option value="other">{{ t('superAdmin.invoice.paymentMethod.other') }}</option>
                                                </select>
                                            </label>
                                        </div>
                                        <button type="submit" class="ui-btn ui-btn--primary ui-btn--sm" :disabled="paymentForm.processing">
                                            {{ t('superAdmin.companies.invoices.paymentConfirm') }}
                                        </button>
                                        <InputError :message="paymentForm.errors.amount_cents" />
                                    </form>
                                </td>
                            </tr>
                        </template>
                        <tr v-if="filteredInvoices.length === 0">
                            <td colspan="5" class="!py-8 text-center !text-ui-text-muted">{{ t('superAdmin.companies.invoices.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <section class="ui-settings-section max-w-xl">
            <h2 class="mb-3 text-base font-semibold">{{ t('superAdmin.companies.invoices.createTitle') }}</h2>
            <form class="space-y-3" @submit.prevent="submitInvoice">
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.companies.invoices.createNumber') }}</span>
                    <input v-model="invoiceForm.number" type="text" class="ui-input mt-1" required />
                    <InputError class="mt-1" :message="invoiceForm.errors.number" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.companies.invoices.paymentAmount') }}</span>
                    <input v-model.number="invoiceAmountTenge" type="number" min="1" class="ui-input mt-1" required />
                    <InputError class="mt-1" :message="invoiceForm.errors.amount_cents" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.companies.invoices.createNotes') }}</span>
                    <textarea v-model="invoiceForm.notes" rows="2" class="ui-input mt-1 resize-y" />
                </label>
                <label class="flex items-center gap-2 text-sm text-ui-text-secondary">
                    <UiCheckbox v-model="invoiceForm.send_email" size="sm" />
                    {{ t('superAdmin.companies.invoices.createSendEmail') }}
                </label>
                <button type="submit" class="ui-btn ui-btn--primary ui-btn--sm" :disabled="invoiceForm.processing">
                    {{ t('superAdmin.companies.invoices.createSubmit') }}
                </button>
            </form>
        </section>

        <DangerConfirmModal
            :open="showVoidConfirm"
            :title="t('superAdmin.companies.invoices.voidModalTitle')"
            :description="voidTarget ? t('superAdmin.companies.invoices.voidModalDescription', { number: voidTarget.number }) : ''"
            :confirm-label="t('superAdmin.companies.invoices.voidModalConfirm')"
            @close="showVoidConfirm = false"
            @confirm="confirmVoidInvoice"
        />
    </div>
</template>
