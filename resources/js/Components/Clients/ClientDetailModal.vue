<script setup lang="ts">
import AiWorkspaceClientSummary from '@/Components/AiChat/AiWorkspaceClientSummary.vue';
import type { ClientSummary } from '@/Components/AiChat/aiWorkspaceTypes';
import ClientProfileSectionsBlock from '@/Components/Clients/ClientProfileSectionsBlock.vue';
import ContactAddFieldModal from '@/Components/Clients/ContactAddFieldModal.vue';
import ContactFieldPickerModal from '@/Components/Clients/ContactFieldPickerModal.vue';
import type { ClientListItem, ClientProfile, ClientProfileField } from '@/Components/Clients/clientProfileTypes';
import { mergeSummaryIntoProfile } from '@/Components/Clients/clientProfileMerge';
import UserAvatar from '@/Components/UserAvatar.vue';
import { useContactFieldActions } from '@/composables/useContactFieldActions';
import { setContactProfileCache, useContactProfile } from '@/composables/useContactProfile';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { formatPhone } from '@/utils/phone';
import { useToastStore } from '@/stores/toast';

const props = defineProps<{
    client: ClientListItem | null;
    canManageContactFields?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    saved: [clientId: number, name: string | null];
    photoUpdated: [clientId: number, url: string | null];
}>();

const { show: showToast } = useToastStore();
const {
    profile,
    summary,
    profileLoading,
    summaryLoading,
    profileError,
    loadProfile,
    enrichProfileInBackground,
    loadSummary,
    primeFromCache,
    invalidateContactProfileCache,
} = useContactProfile();

const editingName = ref('');
const saving = ref(false);
const fieldPickerOpen = ref(false);
const addFieldOpen = ref(false);

const displayName = computed(() => {
    if (!props.client) {
        return 'Клиент';
    }
    return (
        (props.client.name || '').trim()
        || (props.client.push_name || '').trim()
        || (props.client.last_chat_name || '').trim()
        || formatPhone(props.client.phone_display || props.client.phone_number)
        || 'Без имени'
    );
});

const displayProfile = computed(() => mergeSummaryIntoProfile(profile.value, summary.value));

const chatUrl = computed(() => {
    const chatId = props.client?.primary_chat_id;
    if (!chatId || !route().has('chats.show')) {
        return null;
    }
    return route('chats.show', chatId);
});

const fieldActions = useContactFieldActions({
    contactId: () => props.client?.id,
    chatId: () => props.client?.primary_chat_id,
    onProfileUpdated: (nextProfile: ClientProfile) => {
        profile.value = nextProfile;
        if (props.client) {
            setContactProfileCache(props.client.id, props.client.primary_chat_id, nextProfile);
        }
    },
    onPhotoUpdated: (url: string | null) => {
        if (props.client) {
            emit('photoUpdated', props.client.id, url);
        }
    },
});

watch(
    () => props.client?.id,
    (contactId) => {
        profileError.value = null;
        if (!contactId) {
            profile.value = null;
            summary.value = null;
            return;
        }

        editingName.value = (props.client?.name || '').trim();
        const chatId = props.client?.primary_chat_id;
        primeFromCache(contactId, chatId);

        if (profile.value && !profile.value.ai_enriched) {
            void enrichProfileInBackground(contactId, chatId);
        } else if (!profile.value) {
            void loadProfile(contactId, chatId).then((loaded) => {
                if (loaded && !loaded.ai_enriched) {
                    void enrichProfileInBackground(contactId, chatId);
                }
            });
        }

        if (!summary.value) {
            void loadSummary(contactId, chatId);
        }
    },
    { immediate: true },
);

async function saveName(): Promise<void> {
    if (!props.client || saving.value) {
        return;
    }
    saving.value = true;
    try {
        const name = editingName.value.trim();
        const { data } = await axios.patch(route('contacts.update', props.client.id), { name });
        if (data?.success) {
            invalidateContactProfileCache(props.client.id);
            emit('saved', props.client.id, name !== '' ? name : null);
            showToast({ message: 'Имя клиента обновлено' });
            return;
        }
        showToast({ message: data?.error || 'Не удалось обновить имя' });
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        showToast({ message: err.response?.data?.message || 'Не удалось обновить имя' });
    } finally {
        saving.value = false;
    }
}

function onFieldsUpdated(): void {
    if (!props.client?.id) {
        return;
    }
    fieldActions.onFieldsConfigUpdated();
    void loadProfile(props.client.id, props.client.primary_chat_id, { force: true });
}

function saveCustomField(field: ClientProfileField, value: unknown): void {
    void fieldActions.saveField(field, value);
}

function uploadCustomField(field: ClientProfileField, file: File): void {
    void fieldActions.uploadField(field, file);
}

function clearCustomField(field: ClientProfileField): void {
    void fieldActions.clearField(field);
}
</script>

<template>
    <teleport to="body">
        <div
            v-if="client"
            class="fixed inset-0 z-[450] flex items-center justify-center p-3 sm:p-6"
            :style="{ background: 'rgba(0,0,0,.45)' }"
            @click.self="emit('close')"
        >
            <div
                class="client-detail-modal flex w-full max-w-[960px] flex-col rounded-2xl border overflow-hidden"
                :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border)' }"
                role="dialog"
                aria-modal="true"
                :aria-label="`Профиль клиента ${displayName}`"
                @click.stop
            >
                    <header class="shrink-0 flex items-center justify-between gap-3 px-5 py-4 border-b" :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }">
                        <div class="flex min-w-0 items-center gap-3">
                            <UserAvatar :name="displayName" :src="client.profile_picture_url" :size="40" />
                            <div class="min-w-0">
                                <div class="truncate text-sm font-medium">{{ displayName }}</div>
                                <div class="truncate text-xs opacity-70">{{ client.phone_display || formatPhone(client.phone_number) || client.lead_id || '—' }}</div>
                            </div>
                            <span
                                v-if="client.stage"
                                class="shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                                :style="{ background: client.stage.color || 'var(--ui-accent)', color: '#fff' }"
                            >
                                {{ client.stage.name }}
                            </span>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <template v-if="canManageContactFields">
                                <button
                                    type="button"
                                    class="rounded-lg px-2.5 py-1.5 text-xs"
                                    :style="{ background: 'var(--ui-surface)' }"
                                    title="Выбор полей"
                                    @click="fieldPickerOpen = true"
                                >
                                    Поля
                                </button>
                                <button
                                    type="button"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-base font-medium leading-none"
                                    :style="{ background: 'var(--ui-accent)', color: '#fff' }"
                                    aria-label="Добавить поле"
                                    title="Добавить поле"
                                    @click="addFieldOpen = true"
                                >
                                    +
                                </button>
                            </template>
                            <button type="button" class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--ui-surface-hover)]" aria-label="Закрыть" @click="emit('close')">
                                ✕
                            </button>
                        </div>
                    </header>

                    <div class="client-detail-modal__body min-h-0 flex flex-1 overflow-hidden">
                        <div class="min-h-0 min-w-0 flex-1 overflow-y-auto overscroll-contain p-4">
                            <ClientProfileSectionsBlock
                                :profile="displayProfile"
                                :loading="profileLoading"
                                :error="profileError"
                                :editable="canManageContactFields"
                                :contact-name="displayName"
                                @save-field="saveCustomField"
                                @upload-field="uploadCustomField"
                                @clear-field="clearCustomField"
                            />
                        </div>

                        <aside
                            class="client-detail-modal__aside hidden min-h-0 w-[300px] shrink-0 border-l lg:flex lg:flex-col"
                            :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }"
                        >
                            <div class="shrink-0 px-4 py-3 text-xs font-medium uppercase tracking-wide opacity-70">AI-сводка</div>
                            <div class="client-detail-modal__aside-scroll min-h-0 flex-1 overflow-y-auto overscroll-contain px-3 pb-3">
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

                    <footer class="shrink-0 flex flex-wrap items-center gap-2 border-t px-4 py-3" :style="{ borderColor: 'var(--ui-border)' }">
                        <input
                            v-model="editingName"
                            type="text"
                            class="min-w-[140px] flex-1 rounded-lg border-0 px-3 py-2 text-sm focus:ring-0 focus:outline-none"
                            :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                            placeholder="Сохранённое имя"
                        />
                        <button
                            type="button"
                            class="rounded-lg px-3 py-2 text-sm disabled:opacity-50"
                            :style="{ background: 'var(--ui-accent)', color: '#fff' }"
                            :disabled="saving"
                            @click="saveName"
                        >
                            Сохранить имя
                        </button>
                        <Link
                            v-if="chatUrl"
                            :href="chatUrl"
                            class="rounded-lg px-3 py-2 text-sm"
                            :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }"
                        >
                            Открыть чат
                        </Link>
                        <button type="button" class="rounded-lg px-3 py-2 text-sm" :style="{ background: 'var(--ui-surface-muted)', color: 'var(--ui-text)' }" @click="emit('close')">
                            Закрыть
                        </button>
                    </footer>
            </div>
        </div>

        <ContactFieldPickerModal
            :open="fieldPickerOpen"
            @close="fieldPickerOpen = false"
            @updated="onFieldsUpdated"
        />
        <ContactAddFieldModal
            :open="addFieldOpen"
            @close="addFieldOpen = false"
            @created="onFieldsUpdated"
        />
    </teleport>
</template>

<style scoped>
.client-detail-modal {
    height: min(90dvh, calc(100dvh - 2rem));
    max-height: min(90dvh, calc(100dvh - 2rem));
}

.client-detail-modal__body {
    flex-basis: 0;
}

.client-detail-modal__aside-scroll {
    flex-basis: 0;
}

@media (max-width: 1023px) {
    .client-detail-modal__aside {
        display: none;
    }
}
</style>
