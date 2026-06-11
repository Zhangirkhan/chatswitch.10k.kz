<script setup lang="ts">
import UiModal from '@/Components/Ui/UiModal.vue';
import SuperAdminLayout from '@/Layouts/SuperAdminLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type BannerRow = {
    id: number;
    company_id: number | null;
    message: Record<string, string>;
    background_color: string;
    text_color: string;
    starts_at: string | null;
    ends_at: string | null;
    targets: 'web' | 'mobile' | 'both';
    priority: number;
    is_published: boolean;
    company?: { id: number; name: string; slug: string } | null;
};

const props = defineProps<{
    banners: BannerRow[];
    companies: Array<{ id: number; name: string; slug: string }>;
}>();

const { t } = useI18n();

const showCreate = ref(false);
const editing = ref<BannerRow | null>(null);

const colorPresets = [
    { id: 'warning', bg: '#d97706', text: '#fffbeb' },
    { id: 'info', bg: '#2563eb', text: '#ffffff' },
    { id: 'maintenance', bg: '#64748b', text: '#f8fafc' },
    { id: 'danger', bg: '#dc2626', text: '#ffffff' },
] as const;

const form = useForm({
    company_id: '' as string | number,
    message_ru: '',
    message_kk: '',
    message_en: '',
    background_color: '#2563eb',
    text_color: '#ffffff',
    starts_at: '',
    ends_at: '',
    targets: 'both' as 'web' | 'mobile' | 'both',
    priority: 0,
    is_published: true,
});

const editForm = useForm({
    company_id: '' as string | number,
    message_ru: '',
    message_kk: '',
    message_en: '',
    background_color: '#2563eb',
    text_color: '#ffffff',
    starts_at: '',
    ends_at: '',
    targets: 'both' as 'web' | 'mobile' | 'both',
    priority: 0,
    is_published: true,
});

const previewMessage = computed(() => form.message_ru.trim() || t('superAdmin.platformBanners.previewPlaceholder'));

function applyPreset(preset: (typeof colorPresets)[number], target: 'form' | 'edit'): void {
    const f = target === 'form' ? form : editForm;
    f.background_color = preset.bg;
    f.text_color = preset.text;
}

function toDatetimeLocal(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

function payloadFromForm(f: typeof form) {
    return {
        ...f.data(),
        company_id: f.company_id === '' ? null : Number(f.company_id),
        starts_at: f.starts_at || null,
        ends_at: f.ends_at || null,
    };
}

function openCreate(): void {
    form.reset();
    form.background_color = '#2563eb';
    form.text_color = '#ffffff';
    form.targets = 'both';
    form.is_published = true;
    showCreate.value = true;
}

function submitCreate(): void {
    form.transform(() => payloadFromForm(form)).post('/platform-banners', {
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false;
            form.reset();
        },
    });
}

function openEdit(row: BannerRow): void {
    editing.value = row;
    editForm.company_id = row.company_id ? String(row.company_id) : '';
    editForm.message_ru = row.message.ru ?? '';
    editForm.message_kk = row.message.kk ?? '';
    editForm.message_en = row.message.en ?? '';
    editForm.background_color = row.background_color;
    editForm.text_color = row.text_color;
    editForm.starts_at = toDatetimeLocal(row.starts_at);
    editForm.ends_at = toDatetimeLocal(row.ends_at);
    editForm.targets = row.targets;
    editForm.priority = row.priority;
    editForm.is_published = row.is_published;
}

function submitEdit(): void {
    if (!editing.value) return;
    editForm.transform(() => payloadFromForm(editForm)).put(`/platform-banners/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
}

function destroy(row: BannerRow): void {
    if (!confirm(t('superAdmin.platformBanners.confirmDelete'))) return;
    router.delete(`/platform-banners/${row.id}`, { preserveScroll: true });
}

function targetLabel(value: BannerRow['targets']): string {
    if (value === 'web') return t('superAdmin.platformBanners.targetWeb');
    if (value === 'mobile') return t('superAdmin.platformBanners.targetMobile');
    return t('superAdmin.platformBanners.targetBoth');
}
</script>

<template>
    <SuperAdminLayout>
        <Head :title="t('superAdmin.platformBanners.pageTitle')" />
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">{{ t('superAdmin.platformBanners.heading') }}</h1>
            <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" @click="openCreate">
                {{ t('superAdmin.platformBanners.add') }}
            </button>
        </div>

        <div v-if="banners.length === 0" class="ui-empty-state ui-empty-state--dashed">
            {{ t('superAdmin.platformBanners.empty') }}
        </div>

        <div v-else class="ui-panel overflow-hidden p-0">
            <table class="min-w-full text-left text-sm">
                <thead>
                    <tr>
                        <th>{{ t('superAdmin.platformBanners.colMessage') }}</th>
                        <th>{{ t('superAdmin.platformBanners.colScope') }}</th>
                        <th>{{ t('superAdmin.platformBanners.colTargets') }}</th>
                        <th>{{ t('superAdmin.platformBanners.colSchedule') }}</th>
                        <th>{{ t('superAdmin.platformBanners.colStatus') }}</th>
                        <th class="text-right">{{ t('superAdmin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in banners" :key="row.id">
                        <td class="max-w-md">
                            <div class="flex items-center gap-2">
                                <span class="inline-block h-3 w-3 rounded-full shrink-0" :style="{ background: row.background_color }" />
                                <span>{{ row.message.ru }}</span>
                            </div>
                        </td>
                        <td>{{ row.company?.name ?? t('superAdmin.platformBanners.scopePlatform') }}</td>
                        <td>{{ targetLabel(row.targets) }}</td>
                        <td class="text-xs text-ui-text-muted whitespace-nowrap">
                            <span v-if="row.starts_at">{{ row.starts_at }}</span>
                            <span v-if="row.starts_at && row.ends_at"> — </span>
                            <span v-if="row.ends_at">{{ row.ends_at }}</span>
                            <span v-if="!row.starts_at && !row.ends_at">{{ t('superAdmin.common.emDash') }}</span>
                        </td>
                        <td>
                            <span :class="row.is_published ? 'ui-badge ui-badge--success' : 'ui-badge ui-badge--neutral'">
                                {{ row.is_published ? t('superAdmin.platformBanners.published') : t('superAdmin.platformBanners.draft') }}
                            </span>
                        </td>
                        <td class="text-right space-x-1">
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="openEdit(row)">{{ t('superAdmin.platformBanners.edit') }}</button>
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm text-red-600" @click="destroy(row)">{{ t('superAdmin.platformBanners.delete') }}</button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <UiModal :open="showCreate" :title="t('superAdmin.platformBanners.createTitle')" max-width="2xl" @close="showCreate = false">
            <form class="space-y-4 px-5 py-4" @submit.prevent="submitCreate">
                <div class="platform-banner-preview" :style="{ background: form.background_color, color: form.text_color }">
                    {{ previewMessage }}
                </div>
                <label class="block text-sm">
                    <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldMessageRu') }}</span>
                    <textarea v-model="form.message_ru" rows="2" class="ui-textarea w-full" required />
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldCompany') }}</span>
                        <select v-model="form.company_id" class="ui-select w-full">
                            <option value="">{{ t('superAdmin.platformBanners.scopePlatform') }}</option>
                            <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldTargets') }}</span>
                        <select v-model="form.targets" class="ui-select w-full">
                            <option value="both">{{ t('superAdmin.platformBanners.targetBoth') }}</option>
                            <option value="web">{{ t('superAdmin.platformBanners.targetWeb') }}</option>
                            <option value="mobile">{{ t('superAdmin.platformBanners.targetMobile') }}</option>
                        </select>
                    </label>
                </div>
                <div>
                    <div class="mb-2 text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.colorPresets') }}</div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="preset in colorPresets"
                            :key="preset.id"
                            type="button"
                            class="ui-btn ui-btn--ghost ui-btn--sm"
                            @click="applyPreset(preset, 'form')"
                        >
                            {{ t(`superAdmin.platformBanners.preset.${preset.id}`) }}
                        </button>
                    </div>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldBgColor') }}</span>
                        <input v-model="form.background_color" type="color" class="h-10 w-full rounded border border-ui-border" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldTextColor') }}</span>
                        <input v-model="form.text_color" type="color" class="h-10 w-full rounded border border-ui-border" />
                    </label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldStartsAt') }}</span>
                        <input v-model="form.starts_at" type="datetime-local" class="ui-input w-full" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldEndsAt') }}</span>
                        <input v-model="form.ends_at" type="datetime-local" class="ui-input w-full" />
                    </label>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_published" type="checkbox" />
                    {{ t('superAdmin.platformBanners.fieldPublished') }}
                </label>
            </form>
            <template #footer>
                <button type="button" class="ui-btn ui-btn--ghost" @click="showCreate = false">{{ t('superAdmin.common.cancel') }}</button>
                <button type="button" class="ui-btn ui-btn--primary" :disabled="form.processing" @click="submitCreate">{{ t('superAdmin.common.save') }}</button>
            </template>
        </UiModal>

        <UiModal
            :open="editing !== null"
            :title="t('superAdmin.platformBanners.editTitle')"
            max-width="2xl"
            @close="editing = null"
        >
            <form v-if="editing" class="space-y-4 px-5 py-4" @submit.prevent="submitEdit">
                <div class="platform-banner-preview" :style="{ background: editForm.background_color, color: editForm.text_color }">
                    {{ editForm.message_ru || t('superAdmin.platformBanners.previewPlaceholder') }}
                </div>
                <label class="block text-sm">
                    <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldMessageRu') }}</span>
                    <textarea v-model="editForm.message_ru" rows="2" class="ui-textarea w-full" required />
                </label>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldCompany') }}</span>
                        <select v-model="editForm.company_id" class="ui-select w-full">
                            <option value="">{{ t('superAdmin.platformBanners.scopePlatform') }}</option>
                            <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldTargets') }}</span>
                        <select v-model="editForm.targets" class="ui-select w-full">
                            <option value="both">{{ t('superAdmin.platformBanners.targetBoth') }}</option>
                            <option value="web">{{ t('superAdmin.platformBanners.targetWeb') }}</option>
                            <option value="mobile">{{ t('superAdmin.platformBanners.targetMobile') }}</option>
                        </select>
                    </label>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldBgColor') }}</span>
                        <input v-model="editForm.background_color" type="color" class="h-10 w-full rounded border border-ui-border" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldTextColor') }}</span>
                        <input v-model="editForm.text_color" type="color" class="h-10 w-full rounded border border-ui-border" />
                    </label>
                </div>
                <label class="flex items-center gap-2 text-sm">
                    <input v-model="editForm.is_published" type="checkbox" />
                    {{ t('superAdmin.platformBanners.fieldPublished') }}
                </label>
            </form>
            <template #footer>
                <button type="button" class="ui-btn ui-btn--ghost" @click="editing = null">{{ t('superAdmin.common.cancel') }}</button>
                <button type="button" class="ui-btn ui-btn--primary" :disabled="editForm.processing" @click="submitEdit">{{ t('superAdmin.common.save') }}</button>
            </template>
        </UiModal>
    </SuperAdminLayout>
</template>

<style scoped>
.platform-banner-preview {
    border-radius: 0.375rem;
    padding: 0.5rem 2rem;
    text-align: center;
    font-size: 0.8125rem;
    line-height: 1.4;
    min-height: 2.25rem;
    display: flex;
    align-items: center;
    justify-content: center;
}
</style>
