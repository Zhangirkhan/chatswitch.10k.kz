<script setup lang="ts">
import UiFilterField from '@/Components/Ui/UiFilterField.vue';
import UiFilterPanel from '@/Components/Ui/UiFilterPanel.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import UiPagination from '@/Components/Ui/UiPagination.vue';
import SuperAdminPageHeader from '@/Components/SuperAdmin/SuperAdminPageHeader.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const { t } = useI18n();

type RankingRow = {
    id: number;
    type: 'complaint' | 'suggestion';
    status: 'new' | 'read' | 'resolved';
    message: string;
    likes_count: number;
    created_at: string;
    company?: { id: number; name: string; slug: string } | null;
    user?: { id: number; name: string; email: string } | null;
};

interface Paginated<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    from: number | null;
    to: number | null;
    total: number;
}

const props = defineProps<{
    messages: Paginated<RankingRow>;
    filters: {
        status: string;
        type: string;
        period: string;
    };
}>();

const filterForm = useForm({ ...props.filters });

function applyFilters(): void {
    filterForm.get('/contact-messages/ranking', { preserveState: true, preserveScroll: true });
}

function formatDate(iso: string | null): string {
    if (!iso) return t('superAdmin.common.emDash');
    return new Date(iso).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' });
}

function messagePreview(text: string, max = 120): string {
    const trimmed = text.trim();
    if (trimmed.length <= max) return trimmed;
    return `${trimmed.slice(0, max)}…`;
}

function typeLabel(type: RankingRow['type']): string {
    return type === 'complaint'
        ? t('superAdmin.contactMessages.typeComplaint')
        : t('superAdmin.contactMessages.typeSuggestion');
}

function statusLabel(status: RankingRow['status']): string {
    if (status === 'new') return t('superAdmin.contactMessages.statusNew');
    if (status === 'read') return t('superAdmin.contactMessages.statusRead');
    return t('superAdmin.contactMessages.statusResolved');
}

const selected = ref<RankingRow | null>(null);
const showModal = computed({
    get: () => selected.value !== null,
    set: (open: boolean) => {
        if (!open) selected.value = null;
    },
});

const actionForm = useForm({ admin_note: '' });

function openDetails(row: RankingRow): void {
    selected.value = row;
    actionForm.admin_note = '';
}

function closeModal(): void {
    selected.value = null;
    actionForm.reset();
    actionForm.clearErrors();
}

function markRead(): void {
    if (!selected.value) return;
    actionForm.patch(`/contact-messages/ranking/${selected.value.id}/read`, {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
}

function resolveMessage(): void {
    if (!selected.value) return;
    actionForm.patch(`/contact-messages/ranking/${selected.value.id}/resolve`, {
        preserveScroll: true,
        onSuccess: () => closeModal(),
    });
}
</script>

<template>
    <SuperAdminLayout>
        <Head :title="t('superAdmin.contactMessagesRanking.pageTitle')" />
        <SuperAdminPageHeader
            :eyebrow="t('superAdmin.layout.navGroups.operations')"
            :title="t('superAdmin.contactMessagesRanking.heading')"
        />

        <div class="ui-super-admin-actions">
            <Link href="/contact-messages" class="ui-btn ui-btn--ghost ui-btn--sm">
                {{ t('superAdmin.contactMessagesRanking.tabAll') }}
            </Link>
            <span class="ui-btn ui-btn--secondary ui-btn--sm pointer-events-none">
                {{ t('superAdmin.contactMessagesRanking.tabRanking') }}
            </span>
        </div>

        <UiFilterPanel class="mb-4" @submit="applyFilters">
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
            <UiFilterField :label="t('superAdmin.contactMessagesRanking.filterPeriod')">
                <select v-model="filterForm.period" class="ui-select">
                    <option value="7d">{{ t('superAdmin.contactMessagesRanking.period7d') }}</option>
                    <option value="30d">{{ t('superAdmin.contactMessagesRanking.period30d') }}</option>
                    <option value="all">{{ t('superAdmin.contactMessagesRanking.periodAll') }}</option>
                </select>
            </UiFilterField>
            <template #actions>
                <button type="submit" class="ui-btn ui-btn--secondary ui-btn--sm" :disabled="filterForm.processing">
                    {{ t('superAdmin.common.apply') }}
                </button>
            </template>
        </UiFilterPanel>

        <div v-if="messages.data.length === 0" class="ui-empty-state ui-empty-state--dashed">
            {{ t('superAdmin.contactMessagesRanking.empty') }}
        </div>

        <div v-else class="ui-panel ui-table-panel overflow-hidden p-0">
            <table class="min-w-[960px] w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>{{ t('superAdmin.contactMessagesRanking.likes') }}</th>
                        <th>{{ t('superAdmin.contactMessages.filterType') }}</th>
                        <th>{{ t('superAdmin.contactMessages.message') }}</th>
                        <th>{{ t('superAdmin.contactMessages.tableDate') }}</th>
                        <th>{{ t('superAdmin.contactMessages.company') }}</th>
                        <th>{{ t('superAdmin.contactMessages.user') }}</th>
                        <th>{{ t('superAdmin.contactMessages.filterStatus') }}</th>
                        <th class="text-right">{{ t('superAdmin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in messages.data" :key="row.id">
                        <td class="font-semibold whitespace-nowrap">{{ row.likes_count }}</td>
                        <td>{{ typeLabel(row.type) }}</td>
                        <td class="max-w-md">{{ messagePreview(row.message) }}</td>
                        <td class="text-ui-text-muted whitespace-nowrap">{{ formatDate(row.created_at) }}</td>
                        <td>{{ row.company?.name ?? t('superAdmin.common.emDash') }}</td>
                        <td>{{ row.user?.email ?? t('superAdmin.common.emDash') }}</td>
                        <td>{{ statusLabel(row.status) }}</td>
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
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessagesRanking.likes') }}</div>
                        <div class="font-semibold">{{ selected.likes_count }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.filterType') }}</div>
                        <div>{{ typeLabel(selected.type) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.company') }}</div>
                        <div>{{ selected.company?.name ?? t('superAdmin.common.emDash') }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.user') }}</div>
                        <div>{{ selected.user?.email ?? t('superAdmin.common.emDash') }}</div>
                    </div>
                </div>
                <div>
                    <div class="text-xs text-ui-text-muted mb-1">{{ t('superAdmin.contactMessages.message') }}</div>
                    <p class="whitespace-pre-wrap break-words text-sm">{{ selected.message }}</p>
                </div>
                <label class="block text-sm">
                    <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.contactMessages.adminNote') }}</span>
                    <textarea v-model="actionForm.admin_note" rows="3" class="ui-textarea w-full" />
                </label>
            </div>
            <template #footer>
                <button type="button" class="ui-btn ui-btn--ghost" @click="closeModal">{{ t('superAdmin.common.cancel') }}</button>
                <button type="button" class="ui-btn ui-btn--secondary" :disabled="actionForm.processing" @click="markRead">
                    {{ t('superAdmin.contactMessages.markRead') }}
                </button>
                <button type="button" class="ui-btn ui-btn--primary" :disabled="actionForm.processing" @click="resolveMessage">
                    {{ t('superAdmin.contactMessages.resolve') }}
                </button>
            </template>
        </UiModal>
    </SuperAdminLayout>
</template>
