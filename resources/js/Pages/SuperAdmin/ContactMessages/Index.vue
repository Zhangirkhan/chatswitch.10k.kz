<script setup lang="ts">
import UiFilterField from '@/Components/Ui/UiFilterField.vue';
import UiFilterPanel from '@/Components/Ui/UiFilterPanel.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import UiPagination from '@/Components/Ui/UiPagination.vue';
import SuperAdminPageHeader from '@/Components/SuperAdmin/SuperAdminPageHeader.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';

const { t } = useI18n();

type ContactMessageRow = {
    id: number;
    type: 'complaint' | 'suggestion';
    source: 'web' | 'mobile';
    status: 'new' | 'read' | 'resolved';
    message: string;
    app_version: string | null;
    device_platform: string | null;
    device_model: string | null;
    device_manufacturer: string | null;
    os_version: string | null;
    locale: string | null;
    client_ip: string | null;
    admin_note: string | null;
    created_at: string;
    resolved_at: string | null;
    company?: { id: number; name: string; slug: string } | null;
    user?: { id: number; name: string; email: string } | null;
    resolved_by?: { id: number; name: string } | null;
};

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    messages: Paginated<ContactMessageRow>;
    filters: {
        status: string;
        type: string;
        source: string;
        company_id: string;
        search: string;
    };
    companies: Array<{ id: number; name: string; slug: string }>;
}>();

const filterForm = useForm({ ...props.filters });

function applyFilters(): void {
    filterForm.get('/contact-messages', { preserveState: true, preserveScroll: true });
}

function formatDate(iso: string | null): string {
    if (!iso) return t('superAdmin.common.emDash');
    return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

function messagePreview(text: string, max = 80): string {
    const trimmed = text.trim();
    if (trimmed.length <= max) return trimmed;
    return `${trimmed.slice(0, max)}…`;
}

function statusBadgeClass(status: ContactMessageRow['status']): string {
    if (status === 'new') return 'ui-badge ui-badge--warning';
    if (status === 'read') return 'ui-badge ui-badge--neutral';
    return 'ui-badge ui-badge--success';
}

function typeLabel(type: ContactMessageRow['type']): string {
    return type === 'complaint'
        ? t('superAdmin.contactMessages.typeComplaint')
        : t('superAdmin.contactMessages.typeSuggestion');
}

function sourceLabel(source: ContactMessageRow['source']): string {
    return source === 'web'
        ? t('superAdmin.contactMessages.sourceWeb')
        : t('superAdmin.contactMessages.sourceMobile');
}

function statusLabel(status: ContactMessageRow['status']): string {
    if (status === 'new') return t('superAdmin.contactMessages.statusNew');
    if (status === 'read') return t('superAdmin.contactMessages.statusRead');
    return t('superAdmin.contactMessages.statusResolved');
}

const selected = ref<ContactMessageRow | null>(null);
const showModal = computed({
    get: () => selected.value !== null,
    set: (open: boolean) => {
        if (!open) selected.value = null;
    },
});

const actionForm = useForm({ admin_note: '' });

function openDetails(row: ContactMessageRow): void {
    selected.value = row;
    actionForm.admin_note = row.admin_note ?? '';
}

function closeModal(): void {
    selected.value = null;
    actionForm.reset();
    actionForm.clearErrors();
}

function markRead(): void {
    if (!selected.value) return;
    actionForm.patch(`/contact-messages/${selected.value.id}/read`, {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
}

function resolveMessage(): void {
    if (!selected.value) return;
    actionForm.patch(`/contact-messages/${selected.value.id}/resolve`, {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
}

const deviceInfo = computed(() => {
    const row = selected.value;
    if (!row) return '';
    const parts = [
        row.device_manufacturer,
        row.device_model ?? row.device_platform,
        row.os_version,
        row.locale,
    ].filter(Boolean);
    return parts.length > 0 ? parts.join(' · ') : t('superAdmin.common.emDash');
});
</script>

<template>
    <SuperAdminLayout>
        <Head :title="t('superAdmin.contactMessages.pageTitle')" />
        <SuperAdminPageHeader
            accent-group="operations"
            :eyebrow="t('superAdmin.layout.navGroups.operations')"
            :title="t('superAdmin.contactMessages.heading')"
        />

        <div class="ui-super-admin-actions">
            <span class="ui-btn ui-btn--secondary ui-btn--sm pointer-events-none">
                {{ t('superAdmin.contactMessagesRanking.tabAll') }}
            </span>
            <Link href="/contact-messages/ranking" class="ui-btn ui-btn--ghost ui-btn--sm">
                {{ t('superAdmin.contactMessagesRanking.tabRanking') }}
            </Link>
        </div>

        <UiFilterPanel class="mb-4" @submit="applyFilters">
            <UiFilterField :label="t('superAdmin.contactMessages.filterSearch')" wide>
                <input
                    v-model="filterForm.search"
                    type="search"
                    :placeholder="t('superAdmin.contactMessages.searchPlaceholder')"
                    class="ui-input"
                />
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.contactMessages.filterStatus')">
                <select v-model="filterForm.status" class="ui-select">
                    <option value="">{{ t('superAdmin.common.filterAll') }}</option>
                    <option value="new">{{ t('superAdmin.contactMessages.statusNew') }}</option>
                    <option value="read">{{ t('superAdmin.contactMessages.statusRead') }}</option>
                    <option value="resolved">{{ t('superAdmin.contactMessages.statusResolved') }}</option>
                </select>
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.contactMessages.filterType')">
                <select v-model="filterForm.type" class="ui-select">
                    <option value="">{{ t('superAdmin.common.filterAll') }}</option>
                    <option value="complaint">{{ t('superAdmin.contactMessages.typeComplaint') }}</option>
                    <option value="suggestion">{{ t('superAdmin.contactMessages.typeSuggestion') }}</option>
                </select>
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.contactMessages.filterSource')">
                <select v-model="filterForm.source" class="ui-select">
                    <option value="">{{ t('superAdmin.common.filterAll') }}</option>
                    <option value="web">{{ t('superAdmin.contactMessages.sourceWeb') }}</option>
                    <option value="mobile">{{ t('superAdmin.contactMessages.sourceMobile') }}</option>
                </select>
            </UiFilterField>
            <UiFilterField :label="t('superAdmin.contactMessages.filterCompany')">
                <select v-model="filterForm.company_id" class="ui-select">
                    <option value="">{{ t('superAdmin.common.filterAll') }}</option>
                    <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                </select>
            </UiFilterField>
            <template #actions>
                <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm" :disabled="filterForm.processing">
                    {{ t('superAdmin.common.apply') }}
                </button>
            </template>
        </UiFilterPanel>

        <div v-if="messages.data.length === 0" class="ui-empty-state ui-empty-state--dashed">
            {{ t('superAdmin.contactMessages.empty') }}
        </div>

        <div v-else class="ui-panel ui-table-panel overflow-hidden p-0">
            <table class="min-w-[960px] w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>{{ t('superAdmin.contactMessages.tableDate') }}</th>
                        <th>{{ t('superAdmin.contactMessages.company') }}</th>
                        <th>{{ t('superAdmin.contactMessages.user') }}</th>
                        <th>{{ t('superAdmin.contactMessages.filterType') }}</th>
                        <th>{{ t('superAdmin.contactMessages.filterSource') }}</th>
                        <th>{{ t('superAdmin.contactMessages.filterStatus') }}</th>
                        <th>{{ t('superAdmin.contactMessages.message') }}</th>
                        <th class="text-right">{{ t('superAdmin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in messages.data" :key="row.id">
                        <td class="text-ui-text-muted whitespace-nowrap">{{ formatDate(row.created_at) }}</td>
                        <td>
                            <Link
                                v-if="row.company"
                                :href="`/companies/${row.company.id}`"
                                class="text-ui-accent hover:underline"
                            >
                                {{ row.company.name }}
                            </Link>
                            <span v-else>{{ t('superAdmin.common.emDash') }}</span>
                        </td>
                        <td>
                            <div v-if="row.user" class="min-w-0">
                                <div class="font-medium truncate">{{ row.user.name }}</div>
                                <div class="text-xs text-ui-text-muted truncate">{{ row.user.email }}</div>
                            </div>
                            <span v-else>{{ t('superAdmin.common.emDash') }}</span>
                        </td>
                        <td>{{ typeLabel(row.type) }}</td>
                        <td>{{ sourceLabel(row.source) }}</td>
                        <td>
                            <span :class="statusBadgeClass(row.status)">{{ statusLabel(row.status) }}</span>
                        </td>
                        <td class="max-w-[240px] truncate text-ui-text-muted">{{ messagePreview(row.message) }}</td>
                        <td class="text-right">
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="openDetails(row)">
                                {{ t('superAdmin.contactMessages.openDetails') }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <UiPagination
            v-if="messages.data.length > 0"
            class="mt-4"
            :links="messages.links"
            :from="messages.from"
            :to="messages.to"
            :total="messages.total"
        />

        <UiModal
            :open="showModal"
            :title="selected ? t('superAdmin.contactMessages.modalTitle', { id: selected.id }) : ''"
            max-width="2xl"
            @close="closeModal"
        >
            <div v-if="selected" class="ui-super-admin-modal-body space-y-4">
                <div class="grid gap-3 sm:grid-cols-2 text-sm">
                    <div>
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.company') }}</div>
                        <div class="font-medium">{{ selected.company?.name ?? t('superAdmin.common.emDash') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.user') }}</div>
                        <div class="font-medium">{{ selected.user?.name ?? t('superAdmin.common.emDash') }}</div>
                        <div v-if="selected.user" class="text-xs text-ui-text-muted">{{ selected.user.email }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.filterType') }}</div>
                        <div>{{ typeLabel(selected.type) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.filterSource') }}</div>
                        <div>{{ sourceLabel(selected.source) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.filterStatus') }}</div>
                        <span :class="statusBadgeClass(selected.status)">{{ statusLabel(selected.status) }}</span>
                    </div>
                    <div>
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.tableDate') }}</div>
                        <div>{{ formatDate(selected.created_at) }}</div>
                    </div>
                    <div v-if="selected.source === 'mobile'">
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.deviceInfo') }}</div>
                        <div>{{ deviceInfo }}</div>
                    </div>
                    <div v-if="selected.source === 'mobile'">
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.appVersion') }}</div>
                        <div>{{ selected.app_version ?? t('superAdmin.common.emDash') }}</div>
                    </div>
                    <div v-if="selected.client_ip">
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.clientIp') }}</div>
                        <div class="font-mono text-xs">{{ selected.client_ip }}</div>
                    </div>
                </div>

                <div>
                    <div class="text-xs text-ui-text-muted mb-1">{{ t('superAdmin.contactMessages.message') }}</div>
                    <div class="ui-panel p-3 text-sm whitespace-pre-wrap">{{ selected.message }}</div>
                </div>

                <div>
                    <label class="block text-xs text-ui-text-muted mb-1" for="admin-note">
                        {{ t('superAdmin.contactMessages.adminNote') }}
                    </label>
                    <textarea
                        id="admin-note"
                        v-model="actionForm.admin_note"
                        rows="3"
                        class="ui-input w-full min-h-[88px]"
                    />
                </div>
            </div>

            <template #footer>
                <button type="button" class="ui-btn ui-btn--ghost" @click="closeModal">
                    {{ t('superAdmin.common.cancel') }}
                </button>
                <button
                    type="button"
                    class="ui-btn ui-btn--secondary"
                    :disabled="actionForm.processing || selected?.status === 'resolved'"
                    @click="markRead"
                >
                    {{ t('superAdmin.contactMessages.markRead') }}
                </button>
                <button
                    type="button"
                    class="ui-btn ui-btn--primary"
                    :disabled="actionForm.processing || selected?.status === 'resolved'"
                    @click="resolveMessage"
                >
                    {{ t('superAdmin.contactMessages.resolve') }}
                </button>
            </template>
        </UiModal>
    
    </SuperAdminLayout>
</template>
