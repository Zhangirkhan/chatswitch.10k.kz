<script setup lang="ts">
import SuperAdminPageHeader from '@/Components/SuperAdmin/SuperAdminPageHeader.vue';
import UiFilterField from '@/Components/Ui/UiFilterField.vue';
import UiFilterPanel from '@/Components/Ui/UiFilterPanel.vue';
import UiPagination from '@/Components/Ui/UiPagination.vue';
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import CompaniesIndexRow, { type CompanyIndexRow } from '@/Pages/SuperAdmin/Companies/Partials/CompaniesIndexRow.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    companies: Paginated<CompanyIndexRow>;
    demoCompany: CompanyIndexRow | null;
    demoSlug: string;
    filters: {
        q: string;
        is_active: string;
        subscription_status: string;
        plan_id: string;
        sort: string;
    };
    plans: Array<{ id: number; name: string }>;
    isSandboxSuperAdmin?: boolean;
}>();

const page = usePage();
const rootDomain = computed(() => (page.props.rootDomain as string | undefined) ?? 'accel.kz');

const filterForm = useForm({
    q: props.filters.q,
    is_active: props.filters.is_active,
    subscription_status: props.filters.subscription_status,
    plan_id: props.filters.plan_id,
    sort: props.filters.sort,
});

function applyFilters(): void {
    filterForm.get('/companies', { preserveState: true, preserveScroll: true });
}

const exportExcelUrl = computed(() => {
    const params = new URLSearchParams();
    if (filterForm.q) params.set('q', filterForm.q);
    if (filterForm.is_active) params.set('is_active', filterForm.is_active);
    if (filterForm.subscription_status) params.set('subscription_status', filterForm.subscription_status);
    if (filterForm.plan_id) params.set('plan_id', filterForm.plan_id);
    if (filterForm.sort) params.set('sort', filterForm.sort);
    const query = params.toString();

    return query ? `/companies/export?${query}` : '/companies/export';
});

const toggleTarget = ref<CompanyIndexRow | null>(null);
const deleteTarget = ref<CompanyIndexRow | null>(null);
const showToggleConfirm = ref(false);
const showDeleteConfirm = ref(false);
const showSeedConfirm = ref(false);
const showPopulateDemoConfirm = ref(false);
const showDeleteAllConfirm = ref(false);
const bulkBusy = ref(false);

function requestToggle(c: CompanyIndexRow): void {
    toggleTarget.value = c;
    showToggleConfirm.value = true;
}

function confirmToggle(): void {
    const c = toggleTarget.value;
    if (!c) return;
    router.patch(`/companies/${c.id}/toggle-active`, {}, {
        preserveScroll: true,
        onFinish: () => {
            showToggleConfirm.value = false;
            toggleTarget.value = null;
        },
    });
}

const toggleConfirmDescription = computed(() => {
    const c = toggleTarget.value;
    if (!c) return '';
    return c.is_active
        ? t('superAdmin.companies.index.toggleDisableDescription', {
            name: c.name,
            slug: c.slug,
            domain: rootDomain.value,
        })
        : t('superAdmin.companies.index.toggleEnableDescription', { name: c.name });
});

function populateDemoTenant(): void {
    bulkBusy.value = true;
    router.post('/companies/populate-demo', {}, {
        preserveScroll: true,
        onFinish: () => {
            bulkBusy.value = false;
            showPopulateDemoConfirm.value = false;
        },
    });
}

function seedTestData(): void {
    bulkBusy.value = true;
    router.post('/companies/seed-test-data', {}, {
        preserveScroll: true,
        onFinish: () => {
            bulkBusy.value = false;
            showSeedConfirm.value = false;
        },
    });
}

function deleteAllExceptDemo(): void {
    bulkBusy.value = true;
    router.delete('/companies/non-demo', {
        preserveScroll: true,
        onFinish: () => {
            bulkBusy.value = false;
            showDeleteAllConfirm.value = false;
        },
    });
}

function requestDelete(c: CompanyIndexRow): void {
    deleteTarget.value = c;
    showDeleteConfirm.value = true;
}

function confirmDelete(): void {
    const c = deleteTarget.value;
    if (!c) return;

    bulkBusy.value = true;
    router.delete(`/companies/${c.id}`, {
        preserveScroll: true,
        onFinish: () => {
            bulkBusy.value = false;
            showDeleteConfirm.value = false;
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    
        <Head :title="t('superAdmin.companies.index.pageTitle')" />
        <SuperAdminPageHeader
            accent-group="operations"
            :eyebrow="t('superAdmin.layout.navGroups.operations')"
            :title="t('superAdmin.companies.index.heading')"
        >
            <template #actions>
                <Link href="/companies/create" class="ui-btn ui-btn--primary ui-btn--sm">{{ t('superAdmin.common.create') }}</Link>
            </template>
        </SuperAdminPageHeader>

        <section v-if="isSandboxSuperAdmin" class="mb-6 ui-panel p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-ui-text-secondary">
                {{ t('superAdmin.companies.index.sandboxTitle') }}
            </h2>
            <p class="mt-1 text-sm text-ui-text-muted">
                {{ t('superAdmin.companies.index.sandboxDescription') }}
            </p>
        </section>

        <section v-else-if="demoCompany" class="mb-6">
            <div class="mb-2 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-ui-text-secondary">
                        {{ t('superAdmin.companies.index.demoTitle') }}
                    </h2>
                    <p class="mt-0.5 text-sm text-ui-text-muted">
                        {{ t('superAdmin.companies.index.demoDescription', { slug: demoSlug, domain: rootDomain }) }}
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <button
                        type="button"
                        class="ui-btn ui-btn--primary ui-btn--sm"
                        :disabled="bulkBusy"
                        @click="showPopulateDemoConfirm = true"
                    >
                        {{ t('superAdmin.companies.index.demoPopulate') }}
                    </button>
                    <button
                        type="button"
                        class="ui-btn ui-btn--secondary ui-btn--sm"
                        :disabled="bulkBusy"
                        @click="showSeedConfirm = true"
                    >
                        {{ t('superAdmin.companies.index.demoSeedTest') }}
                    </button>
                </div>
            </div>
            <div class="ui-panel ui-table-panel overflow-hidden p-0">
                <table class="min-w-[720px] w-full text-left text-sm">
                    <thead>
                        <tr>
                            <th>{{ t('superAdmin.companies.index.tableName') }}</th>
                            <th>{{ t('superAdmin.companies.index.tableSubdomain') }}</th>
                            <th>{{ t('superAdmin.companies.index.tableSubscription') }}</th>
                            <th>{{ t('superAdmin.companies.index.tablePlan') }}</th>
                            <th>{{ t('superAdmin.companies.index.tableTrialUntil') }}</th>
                            <th class="text-right">{{ t('superAdmin.common.actions') }}</th>
                            <th class="text-right">{{ t('superAdmin.companies.index.tableActive') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <CompaniesIndexRow
                            :company="demoCompany"
                            :root-domain="rootDomain"
                            is-demo
                            @toggle="requestToggle"
                        />
                    </tbody>
                </table>
            </div>
        </section>

        <UiFilterPanel class="mb-4" @submit="applyFilters">
            <UiFilterField :label="t('superAdmin.companies.index.filterSearch')" wide>
                <input
                    v-model="filterForm.q"
                    type="search"
                    :placeholder="t('superAdmin.companies.index.filterSearchPlaceholder')"
                    class="ui-input"
                />
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.companies.index.filterActivity')">
                <select v-model="filterForm.is_active" class="ui-select">
                    <option value="">{{ t('superAdmin.common.filterAll') }}</option>
                    <option value="1">{{ t('superAdmin.companies.index.filterActive') }}</option>
                    <option value="0">{{ t('superAdmin.companies.index.filterInactive') }}</option>
                </select>
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.companies.index.filterSubscription')">
                <select v-model="filterForm.subscription_status" class="ui-select">
                    <option value="">{{ t('superAdmin.common.filterAll') }}</option>
                    <option value="trial">{{ t('superAdmin.subscriptionStatus.trial') }}</option>
                    <option value="active">{{ t('superAdmin.subscriptionStatus.active') }}</option>
                    <option value="past_due">{{ t('superAdmin.subscriptionStatus.pastDue') }}</option>
                    <option value="suspended">{{ t('superAdmin.subscriptionStatus.suspended') }}</option>
                    <option value="canceled">{{ t('superAdmin.subscriptionStatus.canceled') }}</option>
                </select>
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.companies.field.plan')">
                <select v-model="filterForm.plan_id" class="ui-select">
                    <option value="">{{ t('superAdmin.common.filterAll') }}</option>
                    <option v-for="p in plans" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
                </select>
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.companies.index.filterSort')">
                <select v-model="filterForm.sort" class="ui-select">
                    <option value="created_desc">{{ t('superAdmin.companies.index.sortNewest') }}</option>
                    <option value="created_asc">{{ t('superAdmin.companies.index.sortOldest') }}</option>
                    <option value="name">{{ t('superAdmin.companies.index.sortName') }}</option>
                </select>
            </UiFilterField>
            <template #actions>
                <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm" :disabled="filterForm.processing">
                    {{ t('superAdmin.common.apply') }}
                </button>
            </template>
        </UiFilterPanel>

        <div class="ui-panel ui-table-panel overflow-hidden p-0">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-ui-border px-4 py-2.5">
                <div class="text-sm font-medium text-ui-text-secondary">
                    {{ t('superAdmin.companies.index.clientTenants') }}
                    <span v-if="companies.total > 0" class="text-ui-text-muted">({{ companies.total }})</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a
                        :href="exportExcelUrl"
                        class="ui-btn ui-btn--secondary ui-btn--sm"
                        :title="t('superAdmin.companies.index.exportExcelHint')"
                    >
                        {{ t('superAdmin.companies.index.exportExcel') }}
                    </a>
                    <button
                        v-if="!isSandboxSuperAdmin"
                        type="button"
                        class="ui-btn ui-btn--danger-ghost ui-btn--sm"
                        :disabled="bulkBusy"
                        @click="showDeleteAllConfirm = true"
                    >
                        {{ t('superAdmin.companies.index.deleteAll') }}
                    </button>
                </div>
            </div>
            <table class="min-w-[720px] w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>{{ t('superAdmin.companies.index.tableName') }}</th>
                        <th>{{ t('superAdmin.companies.index.tableSubdomain') }}</th>
                        <th>{{ t('superAdmin.companies.index.tableSubscription') }}</th>
                        <th>{{ t('superAdmin.companies.index.tablePlan') }}</th>
                        <th>{{ t('superAdmin.companies.index.tableTrialUntil') }}</th>
                        <th class="text-right">{{ t('superAdmin.common.actions') }}</th>
                        <th class="text-right">{{ t('superAdmin.companies.index.tableActive') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <CompaniesIndexRow
                        v-for="c in companies.data"
                        :key="c.id"
                        :company="c"
                        :root-domain="rootDomain"
                        @toggle="requestToggle"
                        @delete="requestDelete"
                    />
                    <tr v-if="companies.data.length === 0">
                        <td colspan="7" class="!py-8 text-center text-ui-text-muted">
                            {{ isSandboxSuperAdmin ? t('superAdmin.companies.index.emptySandbox') : t('superAdmin.companies.index.emptyClients') }}
                        </td>
                    </tr>
                </tbody>
            </table>
            <UiPagination
                :links="companies.links"
                :from="companies.from"
                :to="companies.to"
                :total="companies.total"
            />
        </div>

        <DangerConfirmModal
            :open="showToggleConfirm"
            :title="t('superAdmin.companies.index.toggleModalTitle')"
            :description="toggleConfirmDescription"
            :confirm-label="toggleTarget?.is_active ? t('superAdmin.companies.index.toggleDisable') : t('superAdmin.companies.index.toggleEnable')"
            confirm-variant="primary"
            @close="showToggleConfirm = false"
            @confirm="confirmToggle"
        />

        <DangerConfirmModal
            :open="showPopulateDemoConfirm"
            :title="t('superAdmin.companies.index.populateDemoModalTitle')"
            :description="t('superAdmin.companies.index.populateDemoModalDescription', { slug: demoSlug, domain: rootDomain })"
            :confirm-label="t('superAdmin.companies.index.populateDemoConfirm')"
            confirm-variant="primary"
            :busy="bulkBusy"
            @close="showPopulateDemoConfirm = false"
            @confirm="populateDemoTenant"
        />

        <DangerConfirmModal
            :open="showSeedConfirm"
            :title="t('superAdmin.companies.index.seedModalTitle')"
            :description="t('superAdmin.companies.index.seedModalDescription')"
            :confirm-label="t('superAdmin.common.create')"
            confirm-variant="primary"
            :busy="bulkBusy"
            @close="showSeedConfirm = false"
            @confirm="seedTestData"
        />

        <DangerConfirmModal
            :open="showDeleteConfirm"
            :title="t('superAdmin.companies.index.deleteModalTitle')"
            :description="t('superAdmin.companies.index.deleteModalDescription', {
                name: deleteTarget?.name ?? '',
                slug: deleteTarget?.slug ?? '',
            })"
            :confirm-label="t('superAdmin.companies.index.deleteConfirm')"
            confirm-variant="danger"
            :busy="bulkBusy"
            @close="showDeleteConfirm = false"
            @confirm="confirmDelete"
        />

        <DangerConfirmModal
            :open="showDeleteAllConfirm"
            :title="t('superAdmin.companies.index.deleteAllModalTitle')"
            :description="t('superAdmin.companies.index.deleteAllModalDescription', { count: companies.total, slug: demoSlug })"
            :confirm-label="t('superAdmin.companies.index.deleteAllConfirm')"
            confirm-variant="danger"
            :busy="bulkBusy"
            @close="showDeleteAllConfirm = false"
            @confirm="deleteAllExceptDemo"
        />
    
</template>
