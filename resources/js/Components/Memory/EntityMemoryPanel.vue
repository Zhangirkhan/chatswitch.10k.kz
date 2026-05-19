<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps<{
    subjectType: 'tenant' | 'contact' | 'employee' | 'client_company';
    subjectId: number;
    compact?: boolean;
}>();

type MemoryPayload = {
    subject_type: string;
    subject_id: number;
    subject_label: string;
    title: string;
    subtitle: string | null;
    content: string;
    updated_at: string | null;
    updated_by: { id: number; name: string | null } | null;
    file_path: string;
};

type BackupRow = {
    id: number;
    preview: string;
    created_at: string | null;
    created_by: { id: number; name: string | null } | null;
};

const loading = ref(true);
const saving = ref(false);
const error = ref<string | null>(null);
const saved = ref(false);
const content = ref('');
const meta = ref<MemoryPayload | null>(null);
const backupsOpen = ref(false);
const backups = ref<BackupRow[]>([]);
const backupsLoading = ref(false);

async function load(): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await axios.get(route('entity-memory.show', {
            subjectType: props.subjectType,
            subjectId: props.subjectId,
        }));
        meta.value = data.memory as MemoryPayload;
        content.value = meta.value?.content ?? '';
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Не удалось загрузить memory.md';
    } finally {
        loading.value = false;
    }
}

async function save(): Promise<void> {
    if (saving.value) return;
    saving.value = true;
    error.value = null;
    saved.value = false;
    try {
        const { data } = await axios.put(route('entity-memory.update', {
            subjectType: props.subjectType,
            subjectId: props.subjectId,
        }), { content: content.value });
        meta.value = data.memory as MemoryPayload;
        content.value = meta.value?.content ?? '';
        saved.value = true;
        setTimeout(() => {
            saved.value = false;
        }, 2000);
    } catch (e: any) {
        error.value = e?.response?.data?.message || e?.response?.data?.errors?.content?.[0] || 'Не удалось сохранить';
    } finally {
        saving.value = false;
    }
}

async function loadBackups(): Promise<void> {
    backupsLoading.value = true;
    try {
        const { data } = await axios.get(route('entity-memory.backups', {
            subjectType: props.subjectType,
            subjectId: props.subjectId,
        }));
        backups.value = Array.isArray(data.data) ? data.data : [];
    } catch {
        backups.value = [];
    } finally {
        backupsLoading.value = false;
    }
}

async function openBackups(): Promise<void> {
    backupsOpen.value = true;
    await loadBackups();
}

async function restoreBackup(backupId: number): Promise<void> {
    if (!confirm('Восстановить эту версию memory.md? Текущий текст уйдёт в бэкап.')) {
        return;
    }
    saving.value = true;
    try {
        const { data } = await axios.post(route('entity-memory.restore', {
            subjectType: props.subjectType,
            subjectId: props.subjectId,
            backupId,
        }));
        meta.value = data.memory as MemoryPayload;
        content.value = meta.value?.content ?? '';
        backupsOpen.value = false;
    } catch (e: any) {
        error.value = e?.response?.data?.message || 'Не удалось восстановить';
    } finally {
        saving.value = false;
    }
}

onMounted(() => {
    void load();
});

watch(
    () => [props.subjectType, props.subjectId] as const,
    () => {
        void load();
    },
);
</script>

<template>
    <div
        class="rounded-xl border overflow-hidden"
        :class="compact ? 'text-[12px]' : 'text-sm'"
        :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel)' }"
    >
        <div
            class="px-3 py-2 border-b flex items-center justify-between gap-2"
            :style="{ borderColor: 'var(--wa-border)', background: 'var(--wa-panel-header)' }"
        >
            <div class="min-w-0">
                <div class="font-medium truncate" :style="{ color: 'var(--wa-text)' }">
                    memory.md — {{ meta?.subject_label || 'Память' }}
                </div>
                <div v-if="meta?.title" class="text-[11px] truncate" :style="{ color: 'var(--wa-text-secondary)' }">
                    {{ meta.title }}
                    <span v-if="meta.subtitle"> · {{ meta.subtitle }}</span>
                </div>
            </div>
            <div class="flex items-center gap-1 shrink-0">
                <button
                    type="button"
                    class="px-2 py-1 rounded-lg text-[11px] border"
                    :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
                    @click="openBackups"
                >
                    Бэкапы
                </button>
                <button
                    type="button"
                    class="px-2 py-1 rounded-lg text-[11px] font-medium"
                    :style="{ background: 'var(--wa-accent)', color: 'var(--wa-accent-on)' }"
                    :disabled="saving || loading"
                    @click="save"
                >
                    {{ saving ? '…' : saved ? 'Сохранено' : 'Сохранить' }}
                </button>
            </div>
        </div>

        <div v-if="loading" class="px-3 py-4" :style="{ color: 'var(--wa-text-secondary)' }">Загрузка…</div>
        <div v-else class="p-3 space-y-2">
            <p v-if="error" class="text-[12px] text-red-400">{{ error }}</p>
            <textarea
                v-model="content"
                rows="10"
                class="w-full rounded-lg border px-3 py-2 font-mono text-[12px] leading-relaxed resize-y min-h-[140px]"
                :style="{
                    borderColor: 'var(--wa-border)',
                    background: 'var(--wa-bg)',
                    color: 'var(--wa-text)',
                }"
                placeholder="# Заметки в формате Markdown…"
            />
            <p v-if="meta?.updated_at" class="text-[10px]" :style="{ color: 'var(--wa-text-secondary)' }">
                Обновлено {{ new Date(meta.updated_at).toLocaleString('ru-RU') }}
                <span v-if="meta.updated_by?.name"> · {{ meta.updated_by.name }}</span>
                <span v-if="meta.file_path"> · файл: {{ meta.file_path }}</span>
            </p>
        </div>

        <div
            v-if="backupsOpen"
            class="fixed inset-0 z-[1200] flex items-center justify-center p-4 bg-black/40"
            @click.self="backupsOpen = false"
        >
            <div
                class="w-full max-w-lg max-h-[80vh] flex flex-col rounded-xl border shadow-2xl overflow-hidden"
                :style="{ background: 'var(--wa-panel)', borderColor: 'var(--wa-border)' }"
            >
                <div class="px-4 py-3 border-b font-medium" :style="{ borderColor: 'var(--wa-border)' }">
                    Бэкапы memory.md
                </div>
                <div class="flex-1 overflow-y-auto wa-scrollbar p-3 space-y-2">
                    <p v-if="backupsLoading" class="text-sm" :style="{ color: 'var(--wa-text-secondary)' }">Загрузка…</p>
                    <p v-else-if="backups.length === 0" class="text-sm" :style="{ color: 'var(--wa-text-secondary)' }">
                        Пока нет сохранённых версий.
                    </p>
                    <button
                        v-for="row in backups"
                        :key="row.id"
                        type="button"
                        class="w-full text-left rounded-lg border px-3 py-2 hover:opacity-90"
                        :style="{ borderColor: 'var(--wa-border)' }"
                        @click="restoreBackup(row.id)"
                    >
                        <div class="text-[11px]" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ row.created_at ? new Date(row.created_at).toLocaleString('ru-RU') : '—' }}
                            <span v-if="row.created_by?.name"> · {{ row.created_by.name }}</span>
                        </div>
                        <div class="text-[12px] mt-1 line-clamp-3 font-mono" :style="{ color: 'var(--wa-text)' }">
                            {{ row.preview }}
                        </div>
                    </button>
                </div>
                <div class="px-4 py-3 border-t" :style="{ borderColor: 'var(--wa-border)' }">
                    <button
                        type="button"
                        class="w-full py-2 rounded-lg border text-sm"
                        :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text-secondary)' }"
                        @click="backupsOpen = false"
                    >
                        Закрыть
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
