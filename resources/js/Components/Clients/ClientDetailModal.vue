<script setup lang="ts">
import AiWorkspaceClientSummary from '@/Components/AiChat/AiWorkspaceClientSummary.vue';
import type { ClientSummary } from '@/Components/AiChat/aiWorkspaceTypes';
import ClientActivityTimeline from '@/Components/Clients/ClientActivityTimeline.vue';
import ClientFinancePlaceholder from '@/Components/Clients/ClientFinancePlaceholder.vue';
import ClientProfileSection from '@/Components/Clients/ClientProfileSection.vue';
import type { ClientListItem, ClientProfile } from '@/Components/Clients/clientProfileTypes';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { formatPhone } from '@/utils/phone';
import { useToastStore } from '@/stores/toast';

const props = defineProps<{
    client: ClientListItem | null;
}>();

const emit = defineEmits<{
    close: [];
    saved: [clientId: number, name: string | null];
}>();

const { show: showToast } = useToastStore();

const profile = ref<ClientProfile | null>(null);
const summary = ref<ClientSummary | null>(null);
const profileLoading = ref(false);
const summaryLoading = ref(false);
const profileError = ref<string | null>(null);
const editingName = ref('');
const saving = ref(false);

const displayName = computed(() => {
    if (!props.client) {
        return 'Клиент';
    }
    return (
        (props.client.name || '').trim()
        || (props.client.push_name || '').trim()
        || (props.client.last_chat_name || '').trim()
        || formatPhone(props.client.phone_number)
        || 'Без имени'
    );
});

const chatUrl = computed(() => {
    const chatId = props.client?.primary_chat_id;
    if (!chatId || !route().has('chats.show')) {
        return null;
    }
    return route('chats.show', chatId);
});

watch(
    () => props.client?.id,
    (contactId) => {
        profile.value = null;
        summary.value = null;
        profileError.value = null;
        if (contactId) {
            editingName.value = (props.client?.name || '').trim();
            void loadProfile(contactId);
            void loadSummary(contactId);
        }
    },
    { immediate: true },
);

async function loadProfile(contactId: number): Promise<void> {
    profileLoading.value = true;
    profileError.value = null;
    try {
        const params = props.client?.primary_chat_id ? { chat_id: props.client.primary_chat_id } : {};
        const { data } = await axios.get(route('clients.profile', contactId), { params });
        profile.value = data.profile as ClientProfile;
    } catch (e: any) {
        profile.value = null;
        profileError.value = e?.response?.data?.message || 'Не удалось загрузить профиль';
    } finally {
        profileLoading.value = false;
    }
}

async function loadSummary(contactId: number): Promise<void> {
    summaryLoading.value = true;
    try {
        const params = props.client?.primary_chat_id ? { chat_id: props.client.primary_chat_id } : {};
        const { data } = await axios.get(route('clients.summary', contactId), { params });
        summary.value = data.client_summary as ClientSummary;
    } catch {
        summary.value = null;
    } finally {
        summaryLoading.value = false;
    }
}

async function saveName(): Promise<void> {
    if (!props.client || saving.value) {
        return;
    }
    saving.value = true;
    try {
        const name = editingName.value.trim();
        const { data } = await axios.patch(route('contacts.update', props.client.id), { name });
        if (data?.success) {
            emit('saved', props.client.id, name !== '' ? name : null);
            showToast({ message: 'Имя клиента обновлено' });
            return;
        }
        showToast({ message: data?.error || 'Не удалось обновить имя' });
    } catch (e: any) {
        showToast({ message: e?.response?.data?.message || 'Не удалось обновить имя' });
    } finally {
        saving.value = false;
    }
}

function sectionByKey(key: string) {
    return profile.value?.sections.find((section) => section.key === key) ?? null;
}
</script>

<template>
    <teleport to="body">
        <div
            v-if="client"
            class="fixed inset-0 z-[450] overflow-y-auto px-3 py-4 sm:px-4 sm:py-6"
            :style="{ background: 'rgba(0,0,0,.45)' }"
            @click.self="emit('close')"
        >
            <div class="mx-auto flex min-h-[calc(100%-1rem)] max-w-[960px] items-center justify-center">
                <div
                    class="flex w-full max-h-[min(90dvh,calc(100dvh-2rem))] flex-col rounded-2xl border overflow-hidden"
                    :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border)' }"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="`Профиль клиента ${displayName}`"
                >
                    <header class="shrink-0 flex items-center justify-between gap-3 px-5 py-4 border-b" :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }">
                        <div class="flex min-w-0 items-center gap-3">
                            <UserAvatar :name="displayName" :src="client.profile_picture_url" :size="40" />
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium">{{ displayName }}</div>
                                <div class="truncate text-xs opacity-70">{{ formatPhone(client.phone_number) || '—' }}</div>
                            </div>
                            <span
                                v-if="client.stage"
                                class="shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                                :style="{ background: client.stage.color || 'var(--ui-accent)', color: '#fff' }"
                            >
                                {{ client.stage.name }}
                            </span>
                        </div>
                        <button type="button" class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--ui-surface-hover)]" aria-label="Закрыть" @click="emit('close')">
                            ✕
                        </button>
                    </header>

                    <div class="min-h-0 flex-1 overflow-hidden">
                        <div class="grid h-full min-h-0 grid-cols-1 lg:grid-cols-[1fr_300px]">
                            <div class="min-h-0 overflow-y-auto p-4 space-y-3">
                                <div v-if="profileLoading" class="py-10 text-center text-sm opacity-70">Загружаем профиль…</div>
                                <div v-else-if="profileError" class="rounded-lg border px-4 py-3 text-sm" :style="{ borderColor: 'var(--ui-border)' }">{{ profileError }}</div>
                                <template v-else-if="profile">
                                    <ClientProfileSection
                                        v-if="sectionByKey('basic')"
                                        :title="sectionByKey('basic')!.title"
                                        :semantic="sectionByKey('basic')!.semantic"
                                        :fields="sectionByKey('basic')!.fields"
                                    />
                                    <ClientProfileSection
                                        v-if="sectionByKey('contacts')"
                                        :title="sectionByKey('contacts')!.title"
                                        :semantic="sectionByKey('contacts')!.semantic"
                                        :fields="sectionByKey('contacts')!.fields"
                                    />
                                    <ClientProfileSection
                                        v-if="sectionByKey('finance')"
                                        :title="sectionByKey('finance')!.title"
                                        :semantic="sectionByKey('finance')!.semantic"
                                        :fields="[]"
                                        :default-open="true"
                                    >
                                        <ClientFinancePlaceholder :message="sectionByKey('finance')!.message" />
                                    </ClientProfileSection>
                                    <ClientProfileSection
                                        v-if="sectionByKey('b2b')"
                                        :title="sectionByKey('b2b')!.title"
                                        :semantic="sectionByKey('b2b')!.semantic"
                                        :fields="sectionByKey('b2b')!.fields"
                                    />
                                    <ClientProfileSection
                                        v-if="sectionByKey('history')"
                                        :title="sectionByKey('history')!.title"
                                        :semantic="sectionByKey('history')!.semantic"
                                        :fields="sectionByKey('history')!.fields"
                                    >
                                        <ClientActivityTimeline :items="sectionByKey('history')!.activity || []" />
                                    </ClientProfileSection>
                                    <ClientProfileSection
                                        v-if="sectionByKey('tasks_notes')"
                                        :title="sectionByKey('tasks_notes')!.title"
                                        :semantic="sectionByKey('tasks_notes')!.semantic"
                                        :fields="sectionByKey('tasks_notes')!.fields"
                                    />
                                </template>
                            </div>

                            <aside class="hidden lg:flex min-h-0 flex-col border-l overflow-hidden" :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }">
                                <div class="shrink-0 px-4 py-3 text-xs font-medium uppercase tracking-wide opacity-70">AI-сводка</div>
                                <div class="min-h-0 flex-1 overflow-y-auto px-3 pb-3">
                                    <AiWorkspaceClientSummary
                                        :summary="summary"
                                        :loading="summaryLoading"
                                        variant="chat"
                                        :expanded="true"
                                        hide-open-chat
                                    />
                                </div>
                            </aside>
                        </div>
                    </div>

                    <footer class="shrink-0 flex flex-wrap items-center gap-2 border-t px-4 py-3" :style="{ borderColor: 'var(--ui-border)' }">
                        <input
                            v-model="editingName"
                            type="text"
                            class="min-w-[140px] flex-1 rounded-lg border-0 px-3 py-2 text-sm focus:ring-0 focus:outline-none"
                            :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                            placeholder="Сохранённое имя"
                        />
                        <Link
                            v-if="chatUrl"
                            :href="chatUrl"
                            class="rounded-lg px-3 py-2 text-sm"
                            :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                        >
                            Открыть чат
                        </Link>
                        <button
                            type="button"
                            class="rounded-lg px-3 py-2 text-sm disabled:opacity-50"
                            :style="{ background: 'var(--ui-accent)', color: '#fff' }"
                            :disabled="saving"
                            @click="saveName"
                        >
                            Сохранить имя
                        </button>
                        <button type="button" class="rounded-lg px-3 py-2 text-sm" :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }" @click="emit('close')">
                            Закрыть
                        </button>
                    </footer>
                </div>
            </div>
        </div>
    </teleport>
</template>

<style scoped>
:deep(.ai-workspace-summary) {
    --sem-who: #8b5cf6;
    --sem-context: #f59e0b;
    --sem-agreements: #22c55e;
}
</style>
