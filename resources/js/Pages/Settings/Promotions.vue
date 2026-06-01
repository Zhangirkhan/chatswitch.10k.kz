<script setup lang="ts">
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
import UiCheckbox from '@/Components/Ui/UiCheckbox.vue';
import UiModal from '@/Components/Ui/UiModal.vue';
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { useToastStore } from '@/stores/toast';

type PromotionType = 'percent' | 'fixed' | 'bogo' | 'gift' | 'bundle' | 'free_delivery' | 'custom';

type PromotionRow = {
    id: number;
    name: string;
    discount_type: PromotionType;
    percent: number | null;
    fixed_amount: string | null;
    buy_quantity: number | null;
    get_quantity: number | null;
    benefit_summary: string | null;
    valid_from: string | null;
    valid_until: string | null;
    conditions: string | null;
    is_active: boolean;
    sort_order: number;
    is_currently_valid: boolean;
};

const props = defineProps<{
    promotions: PromotionRow[];
    ai_promotions_enabled: boolean;
}>();

const { show: showToast } = useToastStore();
const localPromotions = ref<PromotionRow[]>([...props.promotions]);
const aiPromotionsEnabled = ref(props.ai_promotions_enabled);
const settingsSaving = ref(false);
const modalOpen = ref(false);
const saving = ref(false);
const editingId = ref<number | null>(null);
const deleteOpen = ref(false);
const deleteTarget = ref<PromotionRow | null>(null);
const deleteBusy = ref(false);

const form = ref({
    name: '',
    discount_type: 'percent' as PromotionType,
    percent: '10',
    fixed_amount: '',
    buy_quantity: '1',
    get_quantity: '1',
    valid_from: '',
    valid_until: '',
    conditions: '',
    is_active: true,
    sort_order: 0,
});

watch(
    () => props.promotions,
    (items) => {
        localPromotions.value = [...items];
    },
    { deep: true },
);

watch(
    () => props.ai_promotions_enabled,
    (value) => {
        aiPromotionsEnabled.value = value;
    },
);

async function saveAiPromotionsSetting(enabled: boolean): Promise<void> {
    if (settingsSaving.value) {
        return;
    }
    settingsSaving.value = true;
    const previous = aiPromotionsEnabled.value;
    aiPromotionsEnabled.value = enabled;
    try {
        await axios.put(route('settings.promotions.settings'), {
            ai_promotions_enabled: enabled,
        });
        showToast({
            message: enabled ? 'AI будет предлагать акции автоматически.' : 'AI больше не предлагает акции.',
            duration: 3000,
        });
    } catch (e: unknown) {
        aiPromotionsEnabled.value = previous;
        const err = e as { response?: { data?: { message?: string } }; message?: string };
        showToast({
            message: err?.response?.data?.message ?? err?.message ?? 'Не удалось сохранить настройку.',
            duration: 4500,
        });
    } finally {
        settingsSaving.value = false;
    }
}

const promotionTypeOptions: Array<{ id: PromotionType; label: string; hint?: string }> = [
    { id: 'percent', label: 'Скидка в процентах' },
    { id: 'fixed', label: 'Скидка суммой' },
    { id: 'bogo', label: 'N+M (1+1, 2+1…)', hint: 'Купи N — получи M в подарок' },
    { id: 'gift', label: 'Подарок при покупке' },
    { id: 'bundle', label: 'Комплект / набор' },
    { id: 'free_delivery', label: 'Бесплатная доставка' },
    { id: 'custom', label: 'Другое (текстовое описание)' },
];

const selectedTypeHint = computed(() =>
    promotionTypeOptions.find((opt) => opt.id === form.value.discount_type)?.hint ?? null,
);

function resetForm(): void {
    form.value = {
        name: '',
        discount_type: 'percent',
        percent: '10',
        fixed_amount: '',
        buy_quantity: '1',
        get_quantity: '1',
        valid_from: '',
        valid_until: '',
        conditions: '',
        is_active: true,
        sort_order: 0,
    };
}

function openCreate(): void {
    editingId.value = null;
    resetForm();
    modalOpen.value = true;
}

function openEdit(row: PromotionRow): void {
    editingId.value = row.id;
    form.value = {
        name: row.name,
        discount_type: row.discount_type,
        percent: row.percent != null ? String(row.percent) : '',
        fixed_amount: row.fixed_amount ?? '',
        buy_quantity: row.buy_quantity != null ? String(row.buy_quantity) : '1',
        get_quantity: row.get_quantity != null ? String(row.get_quantity) : '1',
        valid_from: row.valid_from ?? '',
        valid_until: row.valid_until ?? '',
        conditions: row.conditions ?? '',
        is_active: row.is_active,
        sort_order: row.sort_order,
    };
    modalOpen.value = true;
}

function discountSummary(row: PromotionRow): string {
    if (row.benefit_summary) {
        return row.benefit_summary;
    }
    if (row.discount_type === 'percent' && row.percent != null) {
        return `−${row.percent}%`;
    }
    if (row.discount_type === 'fixed' && row.fixed_amount) {
        return `−${row.fixed_amount} ₸`;
    }
    if (row.discount_type === 'bogo') {
        const buy = row.buy_quantity ?? 1;
        const get = row.get_quantity ?? 1;
        return `${buy}+${get}`;
    }
    if (row.discount_type === 'gift') {
        return 'Подарок';
    }
    if (row.discount_type === 'bundle') {
        return 'Комплект';
    }
    if (row.discount_type === 'free_delivery') {
        return 'Бесплатная доставка';
    }
    return 'Условие';
}

function validityLabel(row: PromotionRow): string {
    if (!row.valid_from && !row.valid_until) {
        return 'Без срока';
    }
    const from = row.valid_from ? `с ${row.valid_from}` : '';
    const until = row.valid_until ? `до ${row.valid_until}` : '';
    return [from, until].filter(Boolean).join(' ');
}

const sortedPromotions = computed(() =>
    [...localPromotions.value].sort((a, b) => a.sort_order - b.sort_order || a.id - b.id),
);

async function save(): Promise<void> {
    if (saving.value) {
        return;
    }
    saving.value = true;
    const payload = {
        name: form.value.name.trim(),
        discount_type: form.value.discount_type,
        percent: form.value.discount_type === 'percent' ? Number(form.value.percent) || null : null,
        fixed_amount: form.value.discount_type === 'fixed' ? form.value.fixed_amount || null : null,
        buy_quantity: form.value.discount_type === 'bogo' ? Number(form.value.buy_quantity) || 1 : null,
        get_quantity: form.value.discount_type === 'bogo' ? Number(form.value.get_quantity) || 1 : null,
        valid_from: form.value.valid_from || null,
        valid_until: form.value.valid_until || null,
        conditions: form.value.conditions.trim() || null,
        is_active: form.value.is_active,
        sort_order: Number(form.value.sort_order) || 0,
    };

    try {
        if (editingId.value) {
            const res = await axios.put(route('settings.promotions.update', { promotion: editingId.value }), payload);
            const updated = res.data?.promotion as PromotionRow;
            const idx = localPromotions.value.findIndex((p) => p.id === editingId.value);
            if (idx >= 0 && updated) {
                localPromotions.value[idx] = updated;
            }
            showToast({ message: 'Акция обновлена.', duration: 3000 });
        } else {
            const res = await axios.post(route('settings.promotions.store'), payload);
            const created = res.data?.promotion as PromotionRow;
            if (created) {
                localPromotions.value.push(created);
            }
            showToast({ message: 'Акция добавлена.', duration: 3000 });
        }
        modalOpen.value = false;
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } }; message?: string };
        showToast({
            message: err?.response?.data?.message ?? err?.message ?? 'Не удалось сохранить.',
            duration: 4500,
        });
    } finally {
        saving.value = false;
    }
}

function confirmDelete(row: PromotionRow): void {
    deleteTarget.value = row;
    deleteOpen.value = true;
}

async function destroyPromotion(): Promise<void> {
    if (!deleteTarget.value || deleteBusy.value) {
        return;
    }
    deleteBusy.value = true;
    try {
        await axios.delete(route('settings.promotions.destroy', { promotion: deleteTarget.value.id }));
        localPromotions.value = localPromotions.value.filter((p) => p.id !== deleteTarget.value?.id);
        showToast({ message: 'Акция удалена.', duration: 3000 });
        deleteOpen.value = false;
        deleteTarget.value = null;
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } }; message?: string };
        showToast({
            message: err?.response?.data?.message ?? err?.message ?? 'Не удалось удалить.',
            duration: 4500,
        });
    } finally {
        deleteBusy.value = false;
    }
}
</script>

<template>
    <SettingsLayout
        title="Акции и скидки"
        subtitle="Промо для AI и дожима в воронках"
    >
        <Head title="Акции и скидки" />

        <template #actions>
            <button type="button" class="ui-btn ui-btn--primary" @click="openCreate">
                Добавить акцию
            </button>
        </template>

        <div class="w-full px-6 py-6 space-y-4">
            <p class="text-sm text-[var(--ui-text-secondary)] max-w-3xl">
                По умолчанию AI предлагает все активные акции в чатах и при дожиме.
                Привязка к этапам воронки —
                <Link :href="route('settings.funnels')" class="text-[var(--ui-accent)] hover:underline">
                    Воронки продаж
                </Link>
            </p>

            <article
                class="rounded-xl border px-4 py-3"
                :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface)' }"
            >
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div class="min-w-0 flex-1">
                        <h2 class="text-[15px] font-semibold text-[var(--ui-text)]">AI и акции</h2>
                        <p class="mt-1 text-sm text-[var(--ui-text-secondary)]">
                            Когда включено, AI автоматически видит все активные акции в чатах и при дожиме клиентов.
                            Отключите, если промо не должны упоминаться.
                        </p>
                    </div>
                    <label class="inline-flex shrink-0 items-center gap-2 text-sm text-[var(--ui-text)]">
                        <UiCheckbox
                            :model-value="aiPromotionsEnabled"
                            :disabled="settingsSaving"
                            aria-label="AI предлагает акции"
                            @update:model-value="saveAiPromotionsSetting"
                        />
                        AI предлагает акции
                    </label>
                </div>
            </article>

            <div
                v-if="sortedPromotions.length === 0"
                class="rounded-xl border border-dashed px-6 py-10 text-center text-sm text-[var(--ui-text-secondary)]"
                :style="{ borderColor: 'var(--ui-border)' }"
            >
                Пока нет акций. Добавьте первую — например «Скидка 10% до конца месяца».
            </div>

            <div v-else class="space-y-3">
                <article
                    v-for="row in sortedPromotions"
                    :key="row.id"
                    class="rounded-xl border px-4 py-3"
                    :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface)' }"
                >
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-[15px] font-semibold text-[var(--ui-text)]">{{ row.name }}</h2>
                                <span
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium"
                                    :style="{
                                        background: row.is_currently_valid ? 'var(--ui-accent-soft)' : 'color-mix(in srgb, var(--ui-text-secondary) 15%, transparent)',
                                        color: row.is_currently_valid ? 'var(--ui-accent)' : 'var(--ui-text-secondary)',
                                    }"
                                >
                                    {{ row.is_currently_valid ? 'Активна' : 'Не действует' }}
                                </span>
                                <span
                                    v-if="!row.is_active"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-medium opacity-70"
                                    :style="{ background: 'var(--ui-surface-inset)' }"
                                >
                                    Выключена
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-[var(--ui-text-secondary)]">
                                {{ discountSummary(row) }} · {{ validityLabel(row) }}
                            </p>
                            <p v-if="row.conditions" class="mt-1 text-xs text-[var(--ui-text-secondary)]">
                                {{ row.conditions }}
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs"
                                :style="{ borderColor: 'var(--ui-border)' }"
                                @click="openEdit(row)"
                            >
                                Изменить
                            </button>
                            <button
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-xs text-red-500"
                                :style="{ borderColor: 'var(--ui-border)' }"
                                @click="confirmDelete(row)"
                            >
                                Удалить
                            </button>
                        </div>
                    </div>
                </article>
            </div>
        </div>

        <UiModal
            :open="modalOpen"
            title="Акция"
            max-width="lg"
            panel-class="max-w-lg"
            @close="modalOpen = false"
        >
            <div class="space-y-4">
                <label class="block space-y-1">
                    <span class="text-sm text-[var(--ui-text-secondary)]">Название</span>
                    <input
                        v-model="form.name"
                        type="text"
                        class="ui-field w-full rounded-lg border px-3 py-2"
                        placeholder="Скидка 10% на пакет «Старт»"
                    />
                </label>

                <label class="block space-y-1">
                    <span class="text-sm text-[var(--ui-text-secondary)]">Тип акции</span>
                    <select v-model="form.discount_type" class="ui-field w-full rounded-lg border px-3 py-2">
                        <option v-for="opt in promotionTypeOptions" :key="opt.id" :value="opt.id">
                            {{ opt.label }}
                        </option>
                    </select>
                    <p v-if="selectedTypeHint" class="text-xs text-[var(--ui-text-secondary)]">
                        {{ selectedTypeHint }}
                    </p>
                </label>

                <label v-if="form.discount_type === 'percent'" class="block space-y-1">
                    <span class="text-sm text-[var(--ui-text-secondary)]">Процент</span>
                    <input
                        v-model="form.percent"
                        type="number"
                        min="1"
                        max="100"
                        class="ui-field w-full rounded-lg border px-3 py-2"
                    />
                </label>

                <label v-if="form.discount_type === 'fixed'" class="block space-y-1">
                    <span class="text-sm text-[var(--ui-text-secondary)]">Сумма (₸)</span>
                    <input
                        v-model="form.fixed_amount"
                        type="number"
                        min="0"
                        step="0.01"
                        class="ui-field w-full rounded-lg border px-3 py-2"
                    />
                </label>

                <div v-if="form.discount_type === 'bogo'" class="grid grid-cols-2 gap-3">
                    <label class="block space-y-1">
                        <span class="text-sm text-[var(--ui-text-secondary)]">Покупаете (N)</span>
                        <input
                            v-model="form.buy_quantity"
                            type="number"
                            min="1"
                            max="99"
                            class="ui-field w-full rounded-lg border px-3 py-2"
                        />
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm text-[var(--ui-text-secondary)]">В подарок (M)</span>
                        <input
                            v-model="form.get_quantity"
                            type="number"
                            min="1"
                            max="99"
                            class="ui-field w-full rounded-lg border px-3 py-2"
                        />
                    </label>
                </div>

                <p
                    v-if="form.discount_type === 'gift' || form.discount_type === 'bundle'"
                    class="text-xs text-[var(--ui-text-secondary)]"
                >
                    Опишите подарок или состав комплекта в поле «Условия» ниже — AI будет опираться на этот текст.
                </p>

                <div class="grid grid-cols-2 gap-3">
                    <label class="block space-y-1">
                        <span class="text-sm text-[var(--ui-text-secondary)]">Действует с</span>
                        <input v-model="form.valid_from" type="date" class="ui-field w-full rounded-lg border px-3 py-2" />
                    </label>
                    <label class="block space-y-1">
                        <span class="text-sm text-[var(--ui-text-secondary)]">Действует до</span>
                        <input v-model="form.valid_until" type="date" class="ui-field w-full rounded-lg border px-3 py-2" />
                    </label>
                </div>

                <label class="block space-y-1">
                    <span class="text-sm text-[var(--ui-text-secondary)]">Условия (для AI и менеджера)</span>
                    <textarea
                        v-model="form.conditions"
                        rows="3"
                        class="ui-field w-full rounded-lg border px-3 py-2"
                        placeholder="Только на первый заказ, не суммируется с другими акциями…"
                    />
                </label>

                <label class="block space-y-1">
                    <span class="text-sm text-[var(--ui-text-secondary)]">Порядок сортировки</span>
                    <input
                        v-model.number="form.sort_order"
                        type="number"
                        min="0"
                        class="ui-field w-full rounded-lg border px-3 py-2"
                    />
                </label>

                <span class="inline-flex items-center gap-2 text-sm text-[var(--ui-text)]">
                    <UiCheckbox v-model="form.is_active" aria-label="Акция активна" />
                    Акция активна
                </span>

                <div class="flex justify-end gap-2 pt-2">
                    <button
                        type="button"
                        class="rounded-lg border px-4 py-2 text-sm"
                        :style="{ borderColor: 'var(--ui-border)' }"
                        @click="modalOpen = false"
                    >
                        Отмена
                    </button>
                    <button
                        type="button"
                        class="rounded-lg px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                        style="background: var(--ui-accent)"
                        :disabled="saving || !form.name.trim()"
                        @click="save"
                    >
                        {{ saving ? 'Сохраняем…' : 'Сохранить' }}
                    </button>
                </div>
            </div>
        </UiModal>

        <DangerConfirmModal
            :open="deleteOpen"
            title="Удалить акцию?"
            :description="deleteTarget ? `«${deleteTarget.name}» будет удалена без возможности восстановления.` : ''"
            confirm-label="Удалить"
            :busy="deleteBusy"
            confirm-variant="danger"
            @close="deleteOpen = false"
            @confirm="destroyPromotion"
        />
    </SettingsLayout>
</template>
