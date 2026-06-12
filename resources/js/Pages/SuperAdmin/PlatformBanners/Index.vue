<script setup lang="ts">
import UiModal from '@/Components/Ui/UiModal.vue';
import SuperAdminPageHeader from '@/Components/SuperAdmin/SuperAdminPageHeader.vue';
import { useI18n } from '@/composables/useI18n';
import { reloadPlatformBanners } from '@/composables/usePlatformBannerVisibility';
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
    delivery: {
        code: 'draft' | 'scheduled' | 'expired' | 'active';
        web: boolean;
        mobile: boolean;
    };
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
const editPreviewMessage = computed(() => editForm.message_ru.trim() || t('superAdmin.platformBanners.previewPlaceholder'));

function applyPreset(preset: (typeof colorPresets)[number], target: 'form' | 'edit'): void {
    const f = target === 'form' ? form : editForm;
    f.background_color = preset.bg;
    f.text_color = preset.text;
}

function isPresetActive(preset: (typeof colorPresets)[number], target: 'form' | 'edit'): boolean {
    const f = target === 'form' ? form : editForm;

    return f.background_color.toLowerCase() === preset.bg && f.text_color.toLowerCase() === preset.text;
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

function afterBannerMutation(): void {
    reloadPlatformBanners();
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
            afterBannerMutation();
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
            afterBannerMutation();
        },
    });
}

function destroy(row: BannerRow): void {
    if (!confirm(t('superAdmin.platformBanners.confirmDelete'))) return;
    router.delete(`/platform-banners/${row.id}`, {
        preserveScroll: true,
        onSuccess: afterBannerMutation,
    });
}

function targetLabel(value: BannerRow['targets']): string {
    if (value === 'web') return t('superAdmin.platformBanners.targetWeb');
    if (value === 'mobile') return t('superAdmin.platformBanners.targetMobile');
    return t('superAdmin.platformBanners.targetBoth');
}

function deliveryBadgeClass(code: BannerRow['delivery']['code']): string {
    if (code === 'active') return 'ui-badge ui-badge--success';
    if (code === 'scheduled') return 'ui-badge ui-badge--warning';
    if (code === 'expired') return 'ui-badge ui-badge--neutral';
    return 'ui-badge ui-badge--neutral';
}

function formatScheduleAt(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';

    return d.toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'Asia/Almaty',
    });
}

function deliveryLabel(row: BannerRow): string {
    return t(`superAdmin.platformBanners.delivery.${row.delivery.code}`);
}
</script>

<template>
    
        <Head :title="t('superAdmin.platformBanners.pageTitle')" />
        <SuperAdminPageHeader
            accent-group="platform"
            :eyebrow="t('superAdmin.layout.navGroups.platform')"
            :title="t('superAdmin.platformBanners.heading')"
        >
            <template #actions>
                <button type="button" class="ui-btn ui-btn--primary ui-btn--sm" @click="openCreate">
                    {{ t('superAdmin.platformBanners.add') }}
                </button>
            </template>
        </SuperAdminPageHeader>

        <div v-if="props.banners.length === 0" class="ui-empty-state ui-empty-state--dashed">
            {{ t('superAdmin.platformBanners.empty') }}
        </div>

        <div v-else class="ui-panel ui-table-panel overflow-hidden p-0">
            <table class="ui-table w-full">
                <thead>
                    <tr>
                        <th class="min-w-[12rem]">{{ t('superAdmin.platformBanners.colMessage') }}</th>
                        <th class="min-w-[8rem]">{{ t('superAdmin.platformBanners.colScope') }}</th>
                        <th class="min-w-[7rem]">{{ t('superAdmin.platformBanners.colTargets') }}</th>
                        <th class="min-w-[10rem]">{{ t('superAdmin.platformBanners.colSchedule') }}</th>
                        <th class="min-w-[7rem]">{{ t('superAdmin.platformBanners.colStatus') }}</th>
                        <th class="min-w-[9rem] text-right">{{ t('superAdmin.common.actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in props.banners" :key="row.id">
                        <td>
                            <div class="flex items-center gap-2.5">
                                <span
                                    class="inline-block h-3.5 w-3.5 shrink-0 rounded-full ring-1 ring-black/10"
                                    :style="{ background: row.background_color }"
                                />
                                <span class="font-medium text-ui-text-primary">{{ row.message.ru }}</span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap">{{ row.company?.name ?? t('superAdmin.platformBanners.scopePlatform') }}</td>
                        <td>
                            <span class="ui-badge ui-badge--neutral">{{ targetLabel(row.targets) }}</span>
                        </td>
                        <td>
                            <div v-if="!row.starts_at && !row.ends_at" class="text-sm text-ui-text-muted">
                                {{ t('superAdmin.platformBanners.scheduleAlways') }}
                            </div>
                            <div v-else class="flex flex-col gap-0.5 text-sm whitespace-nowrap">
                                <span v-if="row.starts_at">
                                    <span class="text-ui-text-muted">{{ t('superAdmin.platformBanners.scheduleFrom') }}</span>
                                    {{ formatScheduleAt(row.starts_at) }}
                                </span>
                                <span v-if="row.ends_at">
                                    <span class="text-ui-text-muted">{{ t('superAdmin.platformBanners.scheduleUntil') }}</span>
                                    {{ formatScheduleAt(row.ends_at) }}
                                </span>
                            </div>
                        </td>
                        <td>
                            <span :class="deliveryBadgeClass(row.delivery.code)">
                                {{ deliveryLabel(row) }}
                            </span>
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="openEdit(row)">
                                    {{ t('superAdmin.platformBanners.edit') }}
                                </button>
                                <button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm" @click="destroy(row)">
                                    {{ t('superAdmin.platformBanners.delete') }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <UiModal :open="showCreate" :title="t('superAdmin.platformBanners.createTitle')" max-width="2xl" @close="showCreate = false">
            <form id="platform-banner-create" class="space-y-4 px-5 py-4" @submit.prevent="submitCreate">
                <div class="platform-banner-preview" :style="{ background: form.background_color, color: form.text_color }">
                    <span class="platform-banner-preview__text">{{ previewMessage }}</span>
                    <span class="platform-banner-preview__close" aria-hidden="true">×</span>
                </div>

                <label class="block text-sm">
                    <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldMessageRu') }}</span>
                    <textarea v-model="form.message_ru" rows="2" class="ui-input mt-1 min-h-[4.5rem] w-full resize-y" required />
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldCompany') }}</span>
                        <select v-model="form.company_id" class="ui-select mt-1 w-full">
                            <option value="">{{ t('superAdmin.platformBanners.scopePlatform') }}</option>
                            <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldTargets') }}</span>
                        <select v-model="form.targets" class="ui-select mt-1 w-full">
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
                            class="platform-banner-preset"
                            :class="{ 'platform-banner-preset--active': isPresetActive(preset, 'form') }"
                            :style="{ backgroundColor: preset.bg, color: preset.text }"
                            @click="applyPreset(preset, 'form')"
                        >
                            {{ t(`superAdmin.platformBanners.preset.${preset.id}`) }}
                        </button>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldBgColor') }}</span>
                        <div class="mt-1 flex items-center gap-2">
                            <input v-model="form.background_color" type="color" class="platform-banner-color-input" />
                            <input v-model="form.background_color" type="text" class="ui-input flex-1 font-mono text-xs uppercase" maxlength="7" />
                        </div>
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldTextColor') }}</span>
                        <div class="mt-1 flex items-center gap-2">
                            <input v-model="form.text_color" type="color" class="platform-banner-color-input" />
                            <input v-model="form.text_color" type="text" class="ui-input flex-1 font-mono text-xs uppercase" maxlength="7" />
                        </div>
                    </label>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldStartsAt') }}</span>
                        <input v-model="form.starts_at" type="datetime-local" class="ui-input mt-1 w-full" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldEndsAt') }}</span>
                        <input v-model="form.ends_at" type="datetime-local" class="ui-input mt-1 w-full" />
                    </label>
                </div>
                <p class="text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.scheduleHint') }}</p>

                <label class="flex items-center gap-2 text-sm text-ui-text-secondary">
                    <input v-model="form.is_published" type="checkbox" class="rounded border-ui-border" />
                    {{ t('superAdmin.platformBanners.fieldPublished') }}
                </label>
            </form>
            <template #footer>
                <button type="button" class="ui-btn ui-btn--ghost" @click="showCreate = false">{{ t('superAdmin.common.cancel') }}</button>
                <button type="submit" form="platform-banner-create" class="ui-btn ui-btn--primary" :disabled="form.processing">
                    {{ form.processing ? t('superAdmin.common.saving') : t('superAdmin.common.save') }}
                </button>
            </template>
        </UiModal>

        <UiModal
            :open="editing !== null"
            :title="t('superAdmin.platformBanners.editTitle')"
            max-width="2xl"
            @close="editing = null"
        >
            <form v-if="editing" id="platform-banner-edit" class="space-y-4 px-5 py-4" @submit.prevent="submitEdit">
                <div class="platform-banner-preview" :style="{ background: editForm.background_color, color: editForm.text_color }">
                    <span class="platform-banner-preview__text">{{ editPreviewMessage }}</span>
                    <span class="platform-banner-preview__close" aria-hidden="true">×</span>
                </div>

                <label class="block text-sm">
                    <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldMessageRu') }}</span>
                    <textarea v-model="editForm.message_ru" rows="2" class="ui-input mt-1 min-h-[4.5rem] w-full resize-y" required />
                </label>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldCompany') }}</span>
                        <select v-model="editForm.company_id" class="ui-select mt-1 w-full">
                            <option value="">{{ t('superAdmin.platformBanners.scopePlatform') }}</option>
                            <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
                        </select>
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldTargets') }}</span>
                        <select v-model="editForm.targets" class="ui-select mt-1 w-full">
                            <option value="both">{{ t('superAdmin.platformBanners.targetBoth') }}</option>
                            <option value="web">{{ t('superAdmin.platformBanners.targetWeb') }}</option>
                            <option value="mobile">{{ t('superAdmin.platformBanners.targetMobile') }}</option>
                        </select>
                    </label>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldBgColor') }}</span>
                        <div class="mt-1 flex items-center gap-2">
                            <input v-model="editForm.background_color" type="color" class="platform-banner-color-input" />
                            <input v-model="editForm.background_color" type="text" class="ui-input flex-1 font-mono text-xs uppercase" maxlength="7" />
                        </div>
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldTextColor') }}</span>
                        <div class="mt-1 flex items-center gap-2">
                            <input v-model="editForm.text_color" type="color" class="platform-banner-color-input" />
                            <input v-model="editForm.text_color" type="text" class="ui-input flex-1 font-mono text-xs uppercase" maxlength="7" />
                        </div>
                    </label>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldStartsAt') }}</span>
                        <input v-model="editForm.starts_at" type="datetime-local" class="ui-input mt-1 w-full" />
                    </label>
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.fieldEndsAt') }}</span>
                        <input v-model="editForm.ends_at" type="datetime-local" class="ui-input mt-1 w-full" />
                    </label>
                </div>
                <p class="text-xs text-ui-text-muted">{{ t('superAdmin.platformBanners.scheduleHint') }}</p>

                <label class="flex items-center gap-2 text-sm text-ui-text-secondary">
                    <input v-model="editForm.is_published" type="checkbox" class="rounded border-ui-border" />
                    {{ t('superAdmin.platformBanners.fieldPublished') }}
                </label>
            </form>
            <template #footer>
                <button type="button" class="ui-btn ui-btn--ghost" @click="editing = null">{{ t('superAdmin.common.cancel') }}</button>
                <button type="submit" form="platform-banner-edit" class="ui-btn ui-btn--primary" :disabled="editForm.processing">
                    {{ editForm.processing ? t('superAdmin.common.saving') : t('superAdmin.common.save') }}
                </button>
            </template>
        </UiModal>
    
</template>

<style scoped>
.platform-banner-preview {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 2.25rem;
    padding: 0.5rem 2.5rem 0.5rem 1rem;
    border-radius: 0.375rem;
    font-size: 0.8125rem;
    line-height: 1.4;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08);
}

.platform-banner-preview__text {
    text-align: center;
}

.platform-banner-preview__close {
    position: absolute;
    right: 0.65rem;
    top: 50%;
    transform: translateY(-50%);
    width: 1.5rem;
    height: 1.5rem;
    display: grid;
    place-items: center;
    border-radius: 0.375rem;
    background: rgba(0, 0, 0, 0.2);
    font-size: 1rem;
    line-height: 1;
}

.platform-banner-preset {
    border: 2px solid transparent;
    border-radius: 9999px;
    padding: 0.35rem 0.85rem;
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1.2;
    cursor: pointer;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
}

.platform-banner-preset:hover {
    transform: translateY(-1px);
}

.platform-banner-preset--active {
    box-shadow: 0 0 0 2px var(--ui-bg), 0 0 0 4px color-mix(in srgb, currentColor 70%, transparent);
}

.platform-banner-color-input {
    height: 2.5rem;
    width: 3rem;
    flex-shrink: 0;
    cursor: pointer;
    border: 1px solid var(--ui-border);
    border-radius: 0.375rem;
    background: transparent;
    padding: 0.125rem;
}
</style>
