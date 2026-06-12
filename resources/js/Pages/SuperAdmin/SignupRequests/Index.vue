<script setup lang="ts">
import UiFilterField from '@/Components/Ui/UiFilterField.vue';
import UiFilterPanel from '@/Components/Ui/UiFilterPanel.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiPagination from '@/Components/Ui/UiPagination.vue';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import InputError from '@/Components/InputError.vue';
import SuperAdminPageHeader from '@/Components/SuperAdmin/SuperAdminPageHeader.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

type SignupRequestRow = {
    id: number;
    company_name: string;
    bin: string | null;
    desired_slug: string | null;
    contact_name: string;
    email: string;
    phone: string | null;
    message: string | null;
    status: string;
    company?: { id: number; name: string; slug: string } | null;
    processed_by?: { name: string } | null;
    processed_at: string | null;
    created_at: string;
};

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    requests: Paginated<SignupRequestRow>;
    filters: { status: string };
}>();

const page = usePage();
const rootDomain = computed(() => (page.props.rootDomain as string | undefined) ?? 'accel.kz');
const formErrors = computed(() => page.props.errors as Record<string, string>);

const filterForm = useForm({ status: props.filters.status });

function applyFilter(): void {
    filterForm.get('/signup-requests', { preserveState: true, preserveScroll: true });
}

const createCompanyById = reactive<Record<number, boolean>>({});

const statusLabel = computed(() => ({
    pending: t('superAdmin.signupRequests.statusPending'),
    processed: t('superAdmin.signupRequests.statusProcessed'),
    rejected: t('superAdmin.signupRequests.statusRejected'),
}));

function approve(id: number) {
    router.post(
        `/signup-requests/${id}/approve`,
        { create_company: createCompanyById[id] ? '1' : '' },
        { preserveScroll: true },
    );
}

const rejectTargetId = ref<number | null>(null);
const showRejectConfirm = ref(false);

function requestReject(id: number): void {
    rejectTargetId.value = id;
    showRejectConfirm.value = true;
}

function confirmReject(): void {
    if (rejectTargetId.value === null) return;
    router.post(`/signup-requests/${rejectTargetId.value}/reject`, {}, {
        preserveScroll: true,
        onFinish: () => {
            showRejectConfirm.value = false;
            rejectTargetId.value = null;
        },
    });
}
</script>

<template>
        <Head :title="t('superAdmin.signupRequests.pageTitle')" />
        <SuperAdminPageHeader
            accent-group="operations"
            :eyebrow="t('superAdmin.layout.navGroups.operations')"
            :title="t('superAdmin.signupRequests.heading')"
        />

        <InputError class="mb-4" :message="formErrors.create_company" />

        <UiFilterPanel class="mb-6" compact @submit="applyFilter">
            <UiFilterField :label="t('superAdmin.signupRequests.filterStatus')">
                <select v-model="filterForm.status" class="ui-select">
                    <option value="">{{ t('superAdmin.common.filterAll') }}</option>
                    <option value="pending">{{ t('superAdmin.signupRequests.statusPending') }}</option>
                    <option value="processed">{{ t('superAdmin.signupRequests.statusProcessed') }}</option>
                    <option value="rejected">{{ t('superAdmin.signupRequests.statusRejected') }}</option>
                </select>
            </UiFilterField>
            <template #actions>
                <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm" :disabled="filterForm.processing">
                    {{ t('superAdmin.common.apply') }}
                </button>
            </template>
        </UiFilterPanel>

        <div v-if="requests.data.length === 0" class="ui-empty-state ui-empty-state--dashed">
            {{ t('superAdmin.signupRequests.empty') }}
        </div>

        <div v-else class="space-y-3">
            <div v-for="r in requests.data" :key="r.id" class="ui-panel p-4">
                <div class="flex flex-col gap-4 sm:flex-row sm:justify-between">
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ r.company_name }}</div>
                        <div v-if="r.bin" class="text-sm text-ui-text-secondary">{{ t('superAdmin.signupRequests.bin') }} {{ r.bin }}</div>
                        <div v-if="r.desired_slug" class="text-sm text-ui-text-secondary">
                            {{ t('superAdmin.signupRequests.subdomain') }}
                            <span class="font-mono text-ui-text">{{ r.desired_slug }}.{{ rootDomain }}</span>
                        </div>
                        <div class="text-sm text-ui-text-secondary">{{ r.contact_name }} — {{ r.email }}</div>
                        <div v-if="r.phone" class="text-sm text-ui-text-secondary">{{ r.phone }}</div>
                        <p v-if="r.message" class="mt-2 text-sm text-ui-text-secondary">{{ r.message }}</p>
                        <p v-if="r.company" class="mt-2 text-sm">
                            <Link :href="`/companies/${r.company.id}`" class="text-ui-accent hover:underline">
                                {{ t('superAdmin.signupRequests.linkedCompany', { name: r.company.name, slug: r.company.slug }) }}
                            </Link>
                        </p>
                        <p v-if="r.processed_at" class="mt-1 text-xs text-ui-text-muted">
                            {{ t('superAdmin.signupRequests.processedAt') }} {{ new Date(r.processed_at).toLocaleString('ru-RU') }}
                            <span v-if="r.processed_by"> · {{ r.processed_by.name }}</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-col gap-3 sm:items-end">
                        <span class="ui-badge ui-badge--neutral text-xs uppercase">
                            {{ statusLabel[r.status as 'pending' | 'processed' | 'rejected'] ?? r.status }}
                        </span>
                        <template v-if="r.status === 'pending'">
                            <label class="flex cursor-pointer items-center gap-2 text-sm text-ui-text-secondary">
                                <UiCheckbox v-model="createCompanyById[r.id]" size="sm" />
                                <span>{{ t('superAdmin.signupRequests.createTenantCheckbox') }}</span>
                            </label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="ui-btn ui-btn--primary ui-btn--sm"
                                    :disabled="!createCompanyById[r.id]"
                                    @click="approve(r.id)"
                                >
                                    {{ t('superAdmin.signupRequests.approveAndCreate') }}
                                </button>
                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="requestReject(r.id)">
                                    {{ t('superAdmin.signupRequests.reject') }}
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <UiPagination
            v-if="requests.data.length > 0"
            class="mt-4"
            :links="requests.links"
            :from="requests.from"
            :to="requests.to"
            :total="requests.total"
        />

        <DangerConfirmModal
            :open="showRejectConfirm"
            :title="t('superAdmin.signupRequests.rejectModalTitle')"
            :description="t('superAdmin.signupRequests.rejectModalDescription')"
            :confirm-label="t('superAdmin.signupRequests.rejectModalConfirm')"
            @close="showRejectConfirm = false"
            @confirm="confirmReject"
        />
    
</template>
