<script setup lang="ts">
import UiModal from '@/Components/Ui/UiModal.vue';
import SuperAdminPageHeader from '@/Components/SuperAdmin/SuperAdminPageHeader.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const { t } = useI18n();

interface ReleaseRow {
    id: number;
    platform: string;
    version_name: string;
    version_code: number;
    min_version_code: number;
    download_url: string;
    release_notes: string | null;
    is_published: boolean;
    published_at: string | null;
    created_at: string;
}

const props = defineProps<{
    releases: ReleaseRow[];
    platforms: string[];
    nextVersionCode: number;
}>();

const showCreateModal = ref(false);
const showEditModal = ref(false);
const editingRelease = ref<ReleaseRow | null>(null);
const apkInputRef = ref<HTMLInputElement | null>(null);

const form = useForm({
    platform: 'android',
    version_name: '',
    version_code: 1,
    min_version_code: 0,
    download_url: '/apk/app-release.apk',
    release_notes: '',
    is_published: false,
    apk_file: null as File | null,
});

const editForm = useForm({
    version_name: '',
    version_code: 1,
    min_version_code: 0,
    download_url: '',
    release_notes: '',
    apk_file: null as File | null,
});

function openCreateModal(): void {
    form.clearErrors();
    form.reset();
    form.platform = 'android';
    form.version_code = props.nextVersionCode;
    form.min_version_code = props.nextVersionCode;
    form.download_url = '/apk/app-release.apk';
    form.is_published = false;
    form.apk_file = null;
    if (apkInputRef.value) {
        apkInputRef.value.value = '';
    }
    showCreateModal.value = true;
}

function openEditModal(release: ReleaseRow): void {
    editingRelease.value = release;
    editForm.clearErrors();
    editForm.version_name = release.version_name;
    editForm.version_code = release.version_code;
    editForm.min_version_code = release.min_version_code;
    editForm.download_url = release.download_url;
    editForm.release_notes = release.release_notes ?? '';
    editForm.apk_file = null;
    showEditModal.value = true;
}

function closeEditModal(): void {
    if (editForm.processing) return;
    showEditModal.value = false;
    editingRelease.value = null;
}

function closeCreateModal(): void {
    if (form.processing) return;
    showCreateModal.value = false;
}

function onApkSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    form.apk_file = input.files?.[0] ?? null;
}

function submitCreate(): void {
    form.transform((data) => ({
        ...data,
        version_code: Number(data.version_code),
        min_version_code: Number(data.min_version_code),
    })).post('/mobile-releases', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            showCreateModal.value = false;
            form.reset();
        },
    });
}

function submitEdit(): void {
    if (!editingRelease.value) return;

    editForm
        .transform((data) => ({
            ...data,
            version_code: Number(data.version_code),
            min_version_code: Number(data.min_version_code),
        }))
        .put(`/mobile-releases/${editingRelease.value.id}`, {
            preserveScroll: true,
            forceFormData: editForm.apk_file !== null,
            onSuccess: () => {
                closeEditModal();
            },
        });
}

function publishRelease(id: number): void {
    router.post(`/mobile-releases/${id}/publish`, {}, { preserveScroll: true });
}

function unpublishRelease(id: number): void {
    router.post(`/mobile-releases/${id}/unpublish`, {}, { preserveScroll: true });
}

function deleteRelease(id: number): void {
    if (!window.confirm(t('superAdmin.mobileReleases.confirmDelete'))) return;
    router.delete(`/mobile-releases/${id}`, { preserveScroll: true });
}

function platformLabel(platform: string): string {
    if (platform === 'android') return t('superAdmin.mobileReleases.platformAndroid');
    if (platform === 'ios') return t('superAdmin.mobileReleases.platformIos');
    return platform;
}

function formatDate(value: string | null): string {
    if (!value) return t('superAdmin.common.emDash');
    return new Date(value).toLocaleString('ru-RU');
}
</script>

<template>
    <SuperAdminLayout>
        <Head :title="t('superAdmin.mobileReleases.pageTitle')" />

        <SuperAdminPageHeader
            accent-group="platform"
            :eyebrow="t('superAdmin.layout.navGroups.platform')"
            :title="t('superAdmin.mobileReleases.heading')"
            :subtitle="t('superAdmin.mobileReleases.subtitle')"
        >
            <template #actions>
                <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" @click="openCreateModal">
                    {{ t('superAdmin.mobileReleases.addRelease') }}
                </button>
            </template>
        </SuperAdminPageHeader>

        <div v-if="releases.length === 0" class="ui-empty-state ui-empty-state--dashed">
            <p>{{ t('superAdmin.mobileReleases.empty') }}</p>
        </div>

        <div v-else class="ui-panel ui-table-panel overflow-hidden p-0">
            <table class="min-w-full divide-y divide-ui-border text-sm">
                <thead class="bg-ui-surface-muted/60">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium">{{ t('superAdmin.mobileReleases.colPlatform') }}</th>
                        <th class="px-4 py-3 text-left font-medium">{{ t('superAdmin.mobileReleases.colVersion') }}</th>
                        <th class="px-4 py-3 text-left font-medium">{{ t('superAdmin.mobileReleases.colMinVersion') }}</th>
                        <th class="px-4 py-3 text-left font-medium">{{ t('superAdmin.mobileReleases.colDownload') }}</th>
                        <th class="px-4 py-3 text-left font-medium">{{ t('superAdmin.mobileReleases.colStatus') }}</th>
                        <th class="px-4 py-3 text-left font-medium">{{ t('superAdmin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-ui-border bg-ui-surface">
                    <tr v-for="release in releases" :key="release.id">
                        <td class="px-4 py-3">{{ platformLabel(release.platform) }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ release.version_name }}</div>
                            <div class="text-xs text-ui-text-muted">code {{ release.version_code }}</div>
                        </td>
                        <td class="px-4 py-3">{{ release.min_version_code }}</td>
                        <td class="px-4 py-3">
                            <a
                                :href="release.download_url"
                                class="text-ui-accent hover:underline"
                                target="_blank"
                                rel="noopener noreferrer"
                            >{{ release.download_url }}</a>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="ui-badge"
                                :class="release.is_published ? 'ui-badge--success' : 'ui-badge--neutral'"
                            >
                                {{ release.is_published ? t('superAdmin.mobileReleases.published') : t('superAdmin.mobileReleases.draft') }}
                            </span>
                            <div v-if="release.published_at" class="mt-1 text-xs text-ui-text-muted">
                                {{ formatDate(release.published_at) }}
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="ui-btn ui-btn--ghost ui-btn--sm"
                                    @click="openEditModal(release)"
                                >
                                    {{ t('superAdmin.mobileReleases.edit') }}
                                </button>
                                <button
                                    v-if="!release.is_published"
                                    type="button"
                                    class="ui-btn ui-btn--ghost ui-btn--sm"
                                    @click="publishRelease(release.id)"
                                >
                                    {{ t('superAdmin.mobileReleases.publish') }}
                                </button>
                                <button
                                    v-else
                                    type="button"
                                    class="ui-btn ui-btn--ghost ui-btn--sm"
                                    @click="unpublishRelease(release.id)"
                                >
                                    {{ t('superAdmin.mobileReleases.unpublish') }}
                                </button>
                                <button
                                    v-if="!release.is_published"
                                    type="button"
                                    class="ui-btn ui-btn--ghost ui-btn--sm text-ui-danger"
                                    @click="deleteRelease(release.id)"
                                >
                                    {{ t('superAdmin.mobileReleases.delete') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <UiModal
            :open="showCreateModal"
            :title="t('superAdmin.mobileReleases.createModalTitle')"
            max-width="md"
            @close="closeCreateModal"
        >
            <form id="mobile-release-create-form" class="space-y-4 px-5 py-4" @submit.prevent="submitCreate">
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldPlatform') }}</span>
                    <select v-model="form.platform" class="ui-input mt-1">
                        <option value="android">{{ t('superAdmin.mobileReleases.platformAndroid') }}</option>
                        <option value="ios">{{ t('superAdmin.mobileReleases.platformIos') }}</option>
                    </select>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldVersionName') }}</span>
                    <input v-model="form.version_name" type="text" required class="ui-input mt-1" placeholder="1.2.0" />
                    <p v-if="form.errors.version_name" class="mt-1 text-xs text-ui-danger">{{ form.errors.version_name }}</p>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldVersionCode') }}</span>
                    <input v-model.number="form.version_code" type="number" min="1" required autocomplete="off" class="ui-input mt-1" />
                    <p v-if="form.errors.version_code" class="mt-1 text-xs text-ui-danger">{{ form.errors.version_code }}</p>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldMinVersionCode') }}</span>
                    <input v-model.number="form.min_version_code" type="number" min="0" required autocomplete="off" class="ui-input mt-1" />
                    <p class="mt-1 text-xs text-ui-text-muted">{{ t('superAdmin.mobileReleases.fieldMinVersionCodeHint') }}</p>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldDownloadUrl') }}</span>
                    <input v-model="form.download_url" type="text" class="ui-input mt-1" placeholder="/apk/app-release.apk" />
                    <p v-if="form.errors.download_url" class="mt-1 text-xs text-ui-danger">{{ form.errors.download_url }}</p>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldApkFile') }}</span>
                    <input
                        ref="apkInputRef"
                        type="file"
                        class="ui-input mt-1"
                        @change="onApkSelected"
                    />
                    <p class="mt-1 text-xs text-ui-text-muted">{{ t('superAdmin.mobileReleases.fieldApkFileHint') }}</p>
                    <p v-if="form.apk_file" class="mt-1 text-xs text-ui-accent">
                        {{ form.apk_file.name }}
                    </p>
                    <p v-if="form.errors.apk_file" class="mt-1 text-xs text-ui-danger">{{ form.errors.apk_file }}</p>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldReleaseNotes') }}</span>
                    <textarea v-model="form.release_notes" rows="4" class="ui-input mt-1" />
                </label>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_published" type="checkbox" class="rounded border-ui-border" />
                    <span>{{ t('superAdmin.mobileReleases.fieldPublishNow') }}</span>
                </label>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2 px-5 py-4">
                    <button type="button" class="ui-btn ui-btn--ghost" @click="closeCreateModal">
                        {{ t('superAdmin.common.cancel') }}
                    </button>
                    <button type="submit" form="mobile-release-create-form" class="ui-btn ui-btn--primary" :disabled="form.processing">
                        {{ form.processing ? t('superAdmin.common.saving') : t('superAdmin.common.create') }}
                    </button>
                </div>
            </template>
        </UiModal>

        <UiModal
            :open="showEditModal"
            :title="t('superAdmin.mobileReleases.editModalTitle')"
            max-width="md"
            @close="closeEditModal"
        >
            <form id="mobile-release-edit-form" class="space-y-4 px-5 py-4" @submit.prevent="submitEdit">
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldVersionName') }}</span>
                    <input v-model="editForm.version_name" type="text" required class="ui-input mt-1" />
                    <p v-if="editForm.errors.version_name" class="mt-1 text-xs text-ui-danger">{{ editForm.errors.version_name }}</p>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldVersionCode') }}</span>
                    <input v-model.number="editForm.version_code" type="number" min="1" required autocomplete="off" class="ui-input mt-1" />
                    <p v-if="editForm.errors.version_code" class="mt-1 text-xs text-ui-danger">{{ editForm.errors.version_code }}</p>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldMinVersionCode') }}</span>
                    <input v-model.number="editForm.min_version_code" type="number" min="0" required autocomplete="off" class="ui-input mt-1" />
                    <p class="mt-1 text-xs text-ui-text-muted">{{ t('superAdmin.mobileReleases.fieldMinVersionCodeHint') }}</p>
                    <p v-if="editForm.errors.min_version_code" class="mt-1 text-xs text-ui-danger">{{ editForm.errors.min_version_code }}</p>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldDownloadUrl') }}</span>
                    <input v-model="editForm.download_url" type="text" class="ui-input mt-1" />
                    <p v-if="editForm.errors.download_url" class="mt-1 text-xs text-ui-danger">{{ editForm.errors.download_url }}</p>
                </label>
                <label class="block text-sm">
                    <span class="text-ui-text-secondary">{{ t('superAdmin.mobileReleases.fieldReleaseNotes') }}</span>
                    <textarea v-model="editForm.release_notes" rows="4" class="ui-input mt-1" />
                </label>
            </form>
            <template #footer>
                <div class="flex justify-end gap-2 px-5 py-4">
                    <button type="button" class="ui-btn ui-btn--ghost" @click="closeEditModal">
                        {{ t('superAdmin.common.cancel') }}
                    </button>
                    <button type="submit" form="mobile-release-edit-form" class="ui-btn ui-btn--primary" :disabled="editForm.processing">
                        {{ editForm.processing ? t('superAdmin.common.saving') : t('superAdmin.common.save') }}
                    </button>
                </div>
            </template>
        </UiModal>
    </SuperAdminLayout>
</template>
