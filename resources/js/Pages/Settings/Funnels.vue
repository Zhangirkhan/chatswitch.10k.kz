<script setup lang="ts">
import SettingsLayout from '@/Layouts/SettingsLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import { useToastStore } from '@/stores/toast';

interface FunnelStage {
    id: number;
    funnel_id: number;
    name: string;
    color: string;
    position: number;
    is_active: boolean;
}

interface Funnel {
    id: number;
    name: string;
    description: string | null;
    color: string;
    is_active: boolean;
    position: number;
    stages: FunnelStage[];
    stages_count?: number;
}

const props = defineProps<{
    funnels: Funnel[];
}>();

const { show: showToast } = useToastStore();

const localFunnels = ref<Funnel[]>([...props.funnels]);

watch(
    () => props.funnels,
    (next) => {
        localFunnels.value = [...next];
    },
    { deep: true },
);

/** Палитра для цветовых пресетов и в воронке, и в этапе. */
const palette = [
    '#25d366', '#34d399', '#22d3ee', '#3b82f6', '#6366f1',
    '#8b5cf6', '#a855f7', '#ec4899', '#ef4444', '#f97316',
    '#f59e0b', '#facc15', '#84cc16', '#9ca3af', '#64748b',
];

/* ============================================================
 * Modal: Funnel (create/edit)
 * ============================================================ */
const funnelModalOpen = ref(false);
const editingFunnelId = ref<number | null>(null);
const funnelForm = ref({
    name: '',
    description: '',
    color: '#25d366',
    is_active: true,
});
const savingFunnel = ref(false);
const funnelErrors = ref<Record<string, string>>({});

function openCreateFunnel() {
    editingFunnelId.value = null;
    funnelForm.value = {
        name: '',
        description: '',
        color: '#25d366',
        is_active: true,
    };
    funnelErrors.value = {};
    funnelModalOpen.value = true;
}

function openEditFunnel(funnel: Funnel) {
    editingFunnelId.value = funnel.id;
    funnelForm.value = {
        name: funnel.name,
        description: funnel.description ?? '',
        color: funnel.color || '#25d366',
        is_active: funnel.is_active !== false,
    };
    funnelErrors.value = {};
    funnelModalOpen.value = true;
}

function closeFunnelModal() {
    funnelModalOpen.value = false;
    funnelErrors.value = {};
}

async function saveFunnel() {
    if (!funnelForm.value.name.trim()) {
        funnelErrors.value = { name: 'Укажите название воронки' };
        return;
    }
    if (savingFunnel.value) return;
    savingFunnel.value = true;
    funnelErrors.value = {};

    try {
        const payload = {
            name: funnelForm.value.name.trim(),
            description: funnelForm.value.description.trim() || null,
            color: funnelForm.value.color,
            is_active: funnelForm.value.is_active,
        };

        if (editingFunnelId.value === null) {
            await axios.post(route('settings.funnels.store'), payload);
            showToast({ message: 'Воронка создана', duration: 3000 });
        } else {
            await axios.put(route('settings.funnels.update', editingFunnelId.value), payload);
            showToast({ message: 'Воронка обновлена', duration: 3000 });
        }
        funnelModalOpen.value = false;
        await router.reload({ only: ['funnels'] });
    } catch (err: unknown) {
        const e = err as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } };
        if (e.response?.status === 422 && e.response.data?.errors) {
            const flat: Record<string, string> = {};
            for (const [k, msgs] of Object.entries(e.response.data.errors)) {
                flat[k] = (msgs as string[]).join('\n');
            }
            funnelErrors.value = flat;
        } else {
            showToast({ message: e.response?.data?.message || 'Ошибка сохранения', duration: 6000 });
        }
    } finally {
        savingFunnel.value = false;
    }
}

async function deleteFunnel(funnel: Funnel) {
    const stagesCount = funnel.stages?.length ?? 0;
    const stageWarning = stagesCount > 0
        ? `\nБудет удалено также ${stagesCount} этап(ов).`
        : '';
    if (!confirm(`Удалить воронку "${funnel.name}"?${stageWarning}`)) return;

    try {
        await axios.delete(route('settings.funnels.destroy', funnel.id));
        showToast({ message: 'Воронка удалена', duration: 3000 });
        await router.reload({ only: ['funnels'] });
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } };
        showToast({ message: e.response?.data?.message || 'Ошибка удаления', duration: 6000 });
    }
}

/* ============================================================
 * Modal: Stage (create/edit)
 * ============================================================ */
const stageModalOpen = ref(false);
const stageContext = ref<{ funnel: Funnel | null; editingStageId: number | null }>({
    funnel: null,
    editingStageId: null,
});
const stageForm = ref({
    name: '',
    color: '#9ca3af',
    is_active: true,
});
const savingStage = ref(false);
const stageErrors = ref<Record<string, string>>({});

function openCreateStage(funnel: Funnel) {
    stageContext.value = { funnel, editingStageId: null };
    stageForm.value = { name: '', color: '#9ca3af', is_active: true };
    stageErrors.value = {};
    stageModalOpen.value = true;
}

function openEditStage(funnel: Funnel, stage: FunnelStage) {
    stageContext.value = { funnel, editingStageId: stage.id };
    stageForm.value = {
        name: stage.name,
        color: stage.color || '#9ca3af',
        is_active: stage.is_active !== false,
    };
    stageErrors.value = {};
    stageModalOpen.value = true;
}

function closeStageModal() {
    stageModalOpen.value = false;
    stageErrors.value = {};
}

async function saveStage() {
    const funnel = stageContext.value.funnel;
    if (!funnel) return;
    if (!stageForm.value.name.trim()) {
        stageErrors.value = { name: 'Укажите название этапа' };
        return;
    }
    if (savingStage.value) return;
    savingStage.value = true;
    stageErrors.value = {};

    try {
        const payload = {
            name: stageForm.value.name.trim(),
            color: stageForm.value.color,
            is_active: stageForm.value.is_active,
        };

        if (stageContext.value.editingStageId === null) {
            await axios.post(route('settings.funnels.stages.store', funnel.id), payload);
            showToast({ message: 'Этап добавлен', duration: 3000 });
        } else {
            await axios.put(
                route('settings.funnels.stages.update', [funnel.id, stageContext.value.editingStageId]),
                payload,
            );
            showToast({ message: 'Этап обновлён', duration: 3000 });
        }
        stageModalOpen.value = false;
        await router.reload({ only: ['funnels'] });
    } catch (err: unknown) {
        const e = err as { response?: { status?: number; data?: { message?: string; errors?: Record<string, string[]> } } };
        if (e.response?.status === 422 && e.response.data?.errors) {
            const flat: Record<string, string> = {};
            for (const [k, msgs] of Object.entries(e.response.data.errors)) {
                flat[k] = (msgs as string[]).join('\n');
            }
            stageErrors.value = flat;
        } else {
            showToast({ message: e.response?.data?.message || 'Ошибка сохранения', duration: 6000 });
        }
    } finally {
        savingStage.value = false;
    }
}

async function deleteStage(funnel: Funnel, stage: FunnelStage) {
    if (!confirm(`Удалить этап "${stage.name}"?`)) return;
    try {
        await axios.delete(route('settings.funnels.stages.destroy', [funnel.id, stage.id]));
        showToast({ message: 'Этап удалён', duration: 3000 });
        await router.reload({ only: ['funnels'] });
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } };
        showToast({ message: e.response?.data?.message || 'Ошибка удаления', duration: 6000 });
    }
}

/* ============================================================
 * Manual reorder of stages (move up/down)
 * ============================================================ */
async function moveStage(funnel: Funnel, stage: FunnelStage, direction: -1 | 1) {
    const stages = [...funnel.stages].sort((a, b) => a.position - b.position);
    const idx = stages.findIndex((s) => s.id === stage.id);
    const swapIdx = idx + direction;
    if (idx === -1 || swapIdx < 0 || swapIdx >= stages.length) return;

    [stages[idx], stages[swapIdx]] = [stages[swapIdx], stages[idx]];
    const orderedIds = stages.map((s) => s.id);

    try {
        await axios.post(route('settings.funnels.stages.reorder', funnel.id), {
            stage_ids: orderedIds,
        });
        await router.reload({ only: ['funnels'] });
    } catch (err: unknown) {
        const e = err as { response?: { data?: { message?: string } } };
        showToast({ message: e.response?.data?.message || 'Не удалось переставить этап', duration: 6000 });
    }
}

const totalFunnels = computed(() => localFunnels.value.length);
</script>

<template>
    <Head title="Воронки продаж" />
    <SettingsLayout title="Воронки продаж" subtitle="Этапы и статусы сделок">
        <template #actions>
            <button
                @click="openCreateFunnel"
                class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                :style="{ background: 'var(--wa-accent)', color: '#fff' }"
            >
                + Новая воронка
            </button>
        </template>

        <div class="w-full px-6 py-6 space-y-4">
            <p class="text-sm text-[var(--wa-text-secondary)] max-w-3xl">
                Создавайте воронки и заполняйте их этапами вручную. Этапы упорядочены — порядок
                задаётся стрелками «вверх/вниз».
            </p>

            <div
                v-if="totalFunnels === 0"
                class="rounded-lg border px-6 py-12 text-center"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div class="text-[var(--wa-text)] text-base font-medium mb-1">Воронок пока нет</div>
                <div class="text-sm text-[var(--wa-text-secondary)] mb-4">
                    Создайте первую воронку и добавьте к ней этапы.
                </div>
                <button
                    @click="openCreateFunnel"
                    class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95"
                    :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                >
                    + Новая воронка
                </button>
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="funnel in localFunnels"
                    :key="funnel.id"
                    class="rounded-lg border overflow-hidden"
                    :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
                >
                    <div
                        class="px-5 py-4 flex items-center justify-between gap-3"
                        :style="{ background: 'var(--wa-panel-header)', borderBottom: '1px solid var(--wa-border)' }"
                    >
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <span
                                class="w-3 h-3 rounded-full shrink-0"
                                :style="{ background: funnel.color }"
                            ></span>
                            <div class="min-w-0">
                                <div class="text-[15px] font-medium text-[var(--wa-text)] truncate flex items-center gap-2">
                                    {{ funnel.name }}
                                    <span
                                        v-if="!funnel.is_active"
                                        class="text-[10px] px-1.5 py-0.5 rounded bg-red-500/10 text-red-400"
                                    >
                                        неактивна
                                    </span>
                                </div>
                                <div
                                    v-if="funnel.description"
                                    class="text-xs text-[var(--wa-text-secondary)] truncate"
                                >
                                    {{ funnel.description }}
                                </div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button
                                type="button"
                                class="text-xs px-2.5 py-1.5 rounded-md border transition hover:brightness-95"
                                :style="{ color: 'var(--wa-accent)', borderColor: 'var(--wa-border-strong)' }"
                                @click="openCreateStage(funnel)"
                            >
                                + Этап
                            </button>
                            <button
                                type="button"
                                class="text-xs px-2.5 py-1.5 rounded-md border transition hover:brightness-95"
                                :style="{ color: 'var(--wa-text)', borderColor: 'var(--wa-border-strong)' }"
                                @click="openEditFunnel(funnel)"
                            >
                                Изменить
                            </button>
                            <button
                                type="button"
                                class="text-xs px-2.5 py-1.5 rounded-md border border-red-500/40 text-red-400 transition hover:bg-red-500/10"
                                @click="deleteFunnel(funnel)"
                            >
                                Удалить
                            </button>
                        </div>
                    </div>

                    <div class="px-5 py-4">
                        <div
                            v-if="funnel.stages.length === 0"
                            class="text-sm text-[var(--wa-text-secondary)] italic"
                        >
                            Этапов пока нет — нажмите «+ Этап», чтобы добавить первый.
                        </div>
                        <ol v-else class="space-y-2">
                            <li
                                v-for="(stage, idx) in funnel.stages"
                                :key="stage.id"
                                class="flex items-center gap-3 px-3 py-2 rounded-lg border"
                                :style="{ background: 'var(--wa-bg)', borderColor: 'var(--wa-border-strong)' }"
                            >
                                <span class="text-xs font-mono text-[var(--wa-text-secondary)] w-6 text-right">
                                    {{ idx + 1 }}.
                                </span>
                                <span
                                    class="w-2.5 h-2.5 rounded-full shrink-0"
                                    :style="{ background: stage.color }"
                                ></span>
                                <span class="flex-1 min-w-0 truncate text-sm text-[var(--wa-text)]">
                                    {{ stage.name }}
                                    <span
                                        v-if="!stage.is_active"
                                        class="ml-2 text-[10px] px-1.5 py-0.5 rounded bg-red-500/10 text-red-400"
                                    >
                                        неактивен
                                    </span>
                                </span>
                                <div class="flex items-center gap-1 shrink-0">
                                    <button
                                        type="button"
                                        class="px-1.5 py-1 rounded text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)] disabled:opacity-30"
                                        :disabled="idx === 0"
                                        title="Переместить выше"
                                        @click="moveStage(funnel, stage, -1)"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        class="px-1.5 py-1 rounded text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)] disabled:opacity-30"
                                        :disabled="idx === funnel.stages.length - 1"
                                        title="Переместить ниже"
                                        @click="moveStage(funnel, stage, 1)"
                                    >
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        class="text-xs px-2 py-1 rounded-md border transition hover:brightness-95"
                                        :style="{ color: 'var(--wa-accent)', borderColor: 'var(--wa-border-strong)' }"
                                        @click="openEditStage(funnel, stage)"
                                    >
                                        Изменить
                                    </button>
                                    <button
                                        type="button"
                                        class="text-xs px-2 py-1 rounded-md border border-red-500/40 text-red-400 transition hover:bg-red-500/10"
                                        @click="deleteStage(funnel, stage)"
                                    >
                                        Удалить
                                    </button>
                                </div>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal: Funnel -->
        <Teleport to="body">
            <div
                v-if="funnelModalOpen"
                class="fixed inset-0 z-[2000] flex items-center justify-center p-4 bg-black/60"
                role="dialog"
                aria-modal="true"
                @click.self="closeFunnelModal"
            >
                <div
                    class="w-full max-w-lg max-h-[min(90vh,800px)] overflow-hidden flex flex-col rounded-xl border shadow-2xl"
                    :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border-strong)' }"
                    @click.stop
                >
                    <div
                        class="px-5 py-4 border-b shrink-0 flex items-center justify-between gap-3"
                        :style="{ borderColor: 'var(--wa-border)' }"
                    >
                        <h3 class="text-base font-medium text-[var(--wa-text)]">
                            {{ editingFunnelId === null ? 'Новая воронка' : 'Редактировать воронку' }}
                        </h3>
                        <button
                            type="button"
                            class="text-sm text-[var(--wa-text-secondary)] hover:text-[var(--wa-text)] px-2 py-1 rounded"
                            aria-label="Закрыть"
                            @click="closeFunnelModal"
                        >
                            ✕
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto wa-scrollbar px-5 py-4 space-y-3">
                        <div>
                            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Название</label>
                            <input
                                v-model="funnelForm.name"
                                type="text"
                                class="settings-input"
                                :class="{ 'settings-input-error': funnelErrors.name }"
                                placeholder="Например: Продажи B2B"
                            />
                            <div v-if="funnelErrors.name" class="text-xs text-red-400 mt-1 whitespace-pre-line">
                                {{ funnelErrors.name }}
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Описание (необязательно)</label>
                            <textarea
                                v-model="funnelForm.description"
                                class="settings-input min-h-[64px]"
                                rows="2"
                                placeholder="Краткое описание воронки"
                            ></textarea>
                        </div>

                        <div>
                            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Цвет</label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="c in palette"
                                    :key="c"
                                    type="button"
                                    class="w-7 h-7 rounded-full border-2 transition"
                                    :style="{
                                        background: c,
                                        borderColor: funnelForm.color === c ? 'var(--wa-text)' : 'transparent',
                                    }"
                                    @click="funnelForm.color = c"
                                />
                            </div>
                        </div>

                        <div class="flex items-center gap-2 pt-1">
                            <input
                                id="funnel-active"
                                v-model="funnelForm.is_active"
                                type="checkbox"
                                class="w-4 h-4 rounded"
                            />
                            <label for="funnel-active" class="text-sm text-[var(--wa-text)] cursor-pointer">
                                Активна
                            </label>
                        </div>
                    </div>

                    <div
                        class="flex justify-end gap-2 px-5 py-3 border-t shrink-0"
                        :style="{ borderColor: 'var(--wa-border)' }"
                    >
                        <button
                            type="button"
                            class="px-4 py-2 text-sm rounded-lg text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)]"
                            @click="closeFunnelModal"
                        >
                            Отмена
                        </button>
                        <button
                            type="button"
                            class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95 disabled:opacity-50"
                            :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                            :disabled="savingFunnel"
                            @click="saveFunnel"
                        >
                            {{ savingFunnel ? 'Сохранение…' : (editingFunnelId === null ? 'Создать' : 'Сохранить') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal: Stage -->
        <Teleport to="body">
            <div
                v-if="stageModalOpen"
                class="fixed inset-0 z-[2000] flex items-center justify-center p-4 bg-black/60"
                role="dialog"
                aria-modal="true"
                @click.self="closeStageModal"
            >
                <div
                    class="w-full max-w-md max-h-[min(90vh,800px)] overflow-hidden flex flex-col rounded-xl border shadow-2xl"
                    :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border-strong)' }"
                    @click.stop
                >
                    <div
                        class="px-5 py-4 border-b shrink-0 flex items-center justify-between gap-3"
                        :style="{ borderColor: 'var(--wa-border)' }"
                    >
                        <h3 class="text-base font-medium text-[var(--wa-text)]">
                            <template v-if="stageContext.editingStageId === null">
                                Новый этап
                            </template>
                            <template v-else>
                                Редактировать этап
                            </template>
                            <span class="text-xs text-[var(--wa-text-secondary)] ml-1">
                                — {{ stageContext.funnel?.name }}
                            </span>
                        </h3>
                        <button
                            type="button"
                            class="text-sm text-[var(--wa-text-secondary)] hover:text-[var(--wa-text)] px-2 py-1 rounded"
                            aria-label="Закрыть"
                            @click="closeStageModal"
                        >
                            ✕
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto wa-scrollbar px-5 py-4 space-y-3">
                        <div>
                            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Название</label>
                            <input
                                v-model="stageForm.name"
                                type="text"
                                class="settings-input"
                                :class="{ 'settings-input-error': stageErrors.name }"
                                placeholder="Например: Первичный контакт"
                            />
                            <div v-if="stageErrors.name" class="text-xs text-red-400 mt-1 whitespace-pre-line">
                                {{ stageErrors.name }}
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm text-[var(--wa-text-secondary)] mb-1">Цвет</label>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="c in palette"
                                    :key="c"
                                    type="button"
                                    class="w-7 h-7 rounded-full border-2 transition"
                                    :style="{
                                        background: c,
                                        borderColor: stageForm.color === c ? 'var(--wa-text)' : 'transparent',
                                    }"
                                    @click="stageForm.color = c"
                                />
                            </div>
                        </div>

                        <div class="flex items-center gap-2 pt-1">
                            <input
                                id="stage-active"
                                v-model="stageForm.is_active"
                                type="checkbox"
                                class="w-4 h-4 rounded"
                            />
                            <label for="stage-active" class="text-sm text-[var(--wa-text)] cursor-pointer">
                                Активен
                            </label>
                        </div>
                    </div>

                    <div
                        class="flex justify-end gap-2 px-5 py-3 border-t shrink-0"
                        :style="{ borderColor: 'var(--wa-border)' }"
                    >
                        <button
                            type="button"
                            class="px-4 py-2 text-sm rounded-lg text-[var(--wa-text-secondary)] hover:bg-[var(--wa-panel-hover)]"
                            @click="closeStageModal"
                        >
                            Отмена
                        </button>
                        <button
                            type="button"
                            class="px-4 py-2 text-sm rounded-lg transition hover:brightness-95 disabled:opacity-50"
                            :style="{ background: 'var(--wa-accent)', color: '#fff' }"
                            :disabled="savingStage"
                            @click="saveStage"
                        >
                            {{ savingStage ? 'Сохранение…' : (stageContext.editingStageId === null ? 'Создать' : 'Сохранить') }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </SettingsLayout>
</template>

<style scoped>
.settings-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    background: var(--wa-bg);
    color: var(--wa-text);
    border: 1px solid var(--wa-border-strong);
    transition: border-color 0.15s ease;
}
.settings-input:focus {
    outline: none;
    border-color: var(--wa-accent);
}
.settings-input-error {
    border-color: #f87171 !important;
}
</style>
