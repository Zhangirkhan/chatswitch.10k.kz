<script setup lang="ts">
import UiModal from '@/Components/Ui/UiModal.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface ChangelogEntry {
    id: number;
    published_at: string;
    title: Record<string, string>;
    body: Record<string, string>;
    is_published: boolean;
    is_user_visible: boolean;
    git_commit_hash: string | null;
    source_commit_subject: string | null;
    created_at: string;
}

const props = defineProps<{
    entries: ChangelogEntry[];
}>();

const { t } = useI18n();

const showCreate = ref(false);
const editing = ref<ChangelogEntry | null>(null);
const syncingGit = ref(false);

const form = useForm({
    published_at: new Date().toISOString().slice(0, 10),
    title_ru: '',
    title_kk: '',
    title_en: '',
    body_ru: '',
    body_kk: '',
    body_en: '',
    is_published: true,
    is_user_visible: true,
});

const editForm = useForm({
    published_at: '',
    title_ru: '',
    title_kk: '',
    title_en: '',
    body_ru: '',
    body_kk: '',
    body_en: '',
    is_published: true,
    is_user_visible: true,
});

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' });
}

function openCreate(): void {
    form.reset();
    form.published_at = new Date().toISOString().slice(0, 10);
    form.is_published = true;
    form.is_user_visible = true;
    showCreate.value = true;
}

function submitCreate(): void {
    form.post('/platform-changelog', {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            form.reset();
        },
    });
}

function openEdit(entry: ChangelogEntry): void {
    editing.value = entry;
    editForm.published_at = entry.published_at.slice(0, 10);
    editForm.title_ru = entry.title.ru ?? '';
    editForm.title_kk = entry.title.kk ?? '';
    editForm.title_en = entry.title.en ?? '';
    editForm.body_ru = entry.body.ru ?? '';
    editForm.body_kk = entry.body.kk ?? '';
    editForm.body_en = entry.body.en ?? '';
    editForm.is_published = entry.is_published;
    editForm.is_user_visible = entry.is_user_visible;
}

function submitEdit(): void {
    if (!editing.value) return;
    editForm.put(`/platform-changelog/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
}

function destroyEntry(entry: ChangelogEntry): void {
    if (!window.confirm(t('superAdmin.platformChangelog.deleteConfirm'))) return;
    router.delete(`/platform-changelog/${entry.id}`, { preserveScroll: true });
}

function syncFromGit(): void {
    syncingGit.value = true;
    router.post('/platform-changelog/sync-git', {}, {
        preserveScroll: true,
        onFinish: () => {
            syncingGit.value = false;
        },
    });
}

const showEditModal = computed({
    get: () => editing.value !== null,
    set: (open: boolean) => {
        if (!open) editing.value = null;
    },
});
</script>

<template>
    <SuperAdminLayout>
        <Head :title="t('superAdmin.platformChangelog.pageTitle')" />

        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold sm:text-2xl">{{ t('superAdmin.platformChangelog.heading') }}</h1>
                <p class="mt-1 text-sm text-ui-text-secondary">{{ t('superAdmin.platformChangelog.lead') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="ui-btn ui-btn--secondary ui-btn--sm"
                    :disabled="syncingGit"
                    @click="syncFromGit"
                >
                    {{ syncingGit ? t('superAdmin.platformChangelog.syncingGit') : t('superAdmin.platformChangelog.syncGit') }}
                </button>
                <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" @click="openCreate">
                    {{ t('superAdmin.platformChangelog.add') }}
                </button>
            </div>
        </div>

        <div class="ui-panel ui-table-panel overflow-hidden p-0">
            <table class="ui-table w-full">
                <thead>
                    <tr>
                        <th>{{ t('superAdmin.platformChangelog.colDate') }}</th>
                        <th>{{ t('superAdmin.platformChangelog.colTitle') }}</th>
                        <th>{{ t('superAdmin.platformChangelog.colStatus') }}</th>
                        <th class="text-right">{{ t('superAdmin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="entries.length === 0">
                        <td colspan="4" class="py-8 text-center text-sm text-ui-text-muted">
                            {{ t('superAdmin.platformChangelog.empty') }}
                        </td>
                    </tr>
                    <tr v-for="entry in entries" :key="entry.id">
                        <td class="whitespace-nowrap">{{ formatDate(entry.published_at) }}</td>
                        <td>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ entry.title.ru }}</span>
                                <span v-if="entry.git_commit_hash" class="ui-badge ui-badge--neutral text-xs">
                                    {{ t('superAdmin.platformChangelog.fromGit') }}
                                </span>
                            </div>
                            <div v-if="entry.source_commit_subject" class="mt-0.5 text-xs text-ui-text-muted">
                                {{ entry.source_commit_subject }}
                            </div>
                            <div class="mt-0.5 line-clamp-2 text-sm text-ui-text-secondary">{{ entry.body.ru }}</div>
                        </td>
                        <td>
                            <div class="flex flex-col items-start gap-1">
                                <span
                                    class="ui-badge"
                                    :class="entry.is_published ? 'ui-badge--success' : 'ui-badge--neutral'"
                                >
                                    {{ entry.is_published ? t('superAdmin.platformChangelog.published') : t('superAdmin.platformChangelog.draft') }}
                                </span>
                                <span
                                    class="ui-badge text-xs"
                                    :class="entry.is_user_visible ? 'ui-badge--neutral' : 'ui-badge--warning'"
                                >
                                    {{ entry.is_user_visible
                                        ? t('superAdmin.platformChangelog.audienceUser')
                                        : t('superAdmin.platformChangelog.audienceInternal') }}
                                </span>
                            </div>
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="openEdit(entry)">
                                    {{ t('superAdmin.platformChangelog.edit') }}
                                </button>
                                <button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm" @click="destroyEntry(entry)">
                                    {{ t('superAdmin.platformChangelog.delete') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <UiModal
            :open="showCreate"
            :title="t('superAdmin.platformChangelog.createTitle')"
            max-width="lg"
            @close="showCreate = false"
        >
            <form id="platform-changelog-create" class="space-y-4" @submit.prevent="submitCreate">
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldDate') }}</span>
                    <input v-model="form.published_at" type="date" class="ui-input mt-1" required />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldTitleRu') }}</span>
                    <input v-model="form.title_ru" type="text" class="ui-input mt-1" required maxlength="200" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldTitleKk') }}</span>
                    <input v-model="form.title_kk" type="text" class="ui-input mt-1" maxlength="200" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldTitleEn') }}</span>
                    <input v-model="form.title_en" type="text" class="ui-input mt-1" maxlength="200" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldBodyRu') }}</span>
                    <textarea v-model="form.body_ru" rows="4" class="ui-input mt-1" required maxlength="10000" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldBodyKk') }}</span>
                    <textarea v-model="form.body_kk" rows="4" class="ui-input mt-1" maxlength="10000" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldBodyEn') }}</span>
                    <textarea v-model="form.body_en" rows="4" class="ui-input mt-1" maxlength="10000" />
                </label>
                <label class="flex items-center gap-2 text-sm text-ui-text-secondary">
                    <input v-model="form.is_published" type="checkbox" class="rounded border-ui-border" />
                    {{ t('superAdmin.platformChangelog.fieldPublished') }}
                </label>
                <label class="flex items-center gap-2 text-sm text-ui-text-secondary">
                    <input v-model="form.is_user_visible" type="checkbox" class="rounded border-ui-border" />
                    {{ t('superAdmin.platformChangelog.fieldUserVisible') }}
                </label>
            </form>
            <template #footer>
                <button type="button" class="ui-btn ui-btn--ghost" @click="showCreate = false">{{ t('superAdmin.common.cancel') }}</button>
                <button type="submit" form="platform-changelog-create" class="ui-btn ui-btn--primary" :disabled="form.processing">
                    {{ form.processing ? t('superAdmin.common.saving') : t('superAdmin.common.create') }}
                </button>
            </template>
        </UiModal>

        <UiModal
            :open="showEditModal"
            :title="t('superAdmin.platformChangelog.editTitle')"
            max-width="lg"
            @close="editing = null"
        >
            <form id="platform-changelog-edit" class="space-y-4" @submit.prevent="submitEdit">
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldDate') }}</span>
                    <input v-model="editForm.published_at" type="date" class="ui-input mt-1" required />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldTitleRu') }}</span>
                    <input v-model="editForm.title_ru" type="text" class="ui-input mt-1" required maxlength="200" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldTitleKk') }}</span>
                    <input v-model="editForm.title_kk" type="text" class="ui-input mt-1" maxlength="200" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldTitleEn') }}</span>
                    <input v-model="editForm.title_en" type="text" class="ui-input mt-1" maxlength="200" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldBodyRu') }}</span>
                    <textarea v-model="editForm.body_ru" rows="4" class="ui-input mt-1" required maxlength="10000" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldBodyKk') }}</span>
                    <textarea v-model="editForm.body_kk" rows="4" class="ui-input mt-1" maxlength="10000" />
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.platformChangelog.fieldBodyEn') }}</span>
                    <textarea v-model="editForm.body_en" rows="4" class="ui-input mt-1" maxlength="10000" />
                </label>
                <label class="flex items-center gap-2 text-sm text-ui-text-secondary">
                    <input v-model="editForm.is_published" type="checkbox" class="rounded border-ui-border" />
                    {{ t('superAdmin.platformChangelog.fieldPublished') }}
                </label>
                <label class="flex items-center gap-2 text-sm text-ui-text-secondary">
                    <input v-model="editForm.is_user_visible" type="checkbox" class="rounded border-ui-border" />
                    {{ t('superAdmin.platformChangelog.fieldUserVisible') }}
                </label>
            </form>
            <template #footer>
                <button type="button" class="ui-btn ui-btn--ghost" @click="editing = null">{{ t('superAdmin.common.cancel') }}</button>
                <button type="submit" form="platform-changelog-edit" class="ui-btn ui-btn--primary" :disabled="editForm.processing">
                    {{ editForm.processing ? t('superAdmin.common.saving') : t('superAdmin.common.save') }}
                </button>
            </template>
        </UiModal>
    </SuperAdminLayout>
</template>
