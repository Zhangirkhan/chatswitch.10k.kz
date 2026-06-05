<script setup lang="ts">
import DangerConfirmModal from '@/Components/DangerConfirmModal.vue';
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
import { useI18n } from '@/composables/useI18n';
import { useModalBackdropClose } from '@/composables/useModalBackdropClose';
import { Link } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { formatPhone } from '@/utils/phone';
import { useToastStore } from '@/stores/toast';

const props = defineProps<{
    client: ClientListItem | null;
    canManageContactFields?: boolean;
    canClearClientData?: boolean;
}>();

const emit = defineEmits<{
    close: [];
    saved: [clientId: number, name: string | null];
    photoUpdated: [clientId: number, url: string | null];
}>();

const { onBackdropPointerDown, onPanelPointerDown, onBackdropClick } = useModalBackdropClose(() => emit('close'));

const { show: showToast } = useToastStore();
const { t } = useI18n();
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
const clearMemoryDialogOpen = ref(false);
const clearChatDialogOpen = ref(false);
const clearChatTarget = ref<ClientListItem['channels'][number] | null>(null);
const clearing = ref(false);

const displayName = computed(() => {
    if (!props.client) {
        return t('clients.detail.fallbackName');
    }
    return (
        (props.client.name || '').trim()
        || (props.client.push_name || '').trim()
        || (props.client.last_chat_name || '').trim()
        || formatPhone(props.client.phone_display || props.client.phone_number)
        || t('clients.noName')
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
            showToast({ message: t('clients.detail.toastNameUpdated') });
            return;
        }
        showToast({ message: data?.error || t('clients.detail.toastNameError') });
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        showToast({ message: err.response?.data?.message || t('clients.detail.toastNameError') });
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

async function confirmClearMemory(): Promise<void> {
    if (!props.client || clearing.value) {
        return;
    }
    clearing.value = true;
    try {
        await axios.post(route('clients.clear-memory', props.client.id));
        invalidateContactProfileCache(props.client.id);
        summary.value = null;
        await loadProfile(props.client.id, props.client.primary_chat_id, { force: true });
        await loadSummary(props.client.id, props.client.primary_chat_id);
        showToast({ message: t('clients.detail.toastMemoryCleared') });
        clearMemoryDialogOpen.value = false;
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        showToast({ message: err.response?.data?.message || t('clients.detail.toastMemoryClearError') });
    } finally {
        clearing.value = false;
    }
}

function openClearChatDialog(channel: ClientListItem['channels'][number]): void {
    clearChatTarget.value = channel;
    clearChatDialogOpen.value = true;
}

async function confirmClearChat(): Promise<void> {
    const client = props.client;
    const channel = clearChatTarget.value;
    if (!client || !channel || clearing.value) {
        return;
    }
    clearing.value = true;
    try {
        await axios.post(route('clients.clear-chat', { contact: client.id, chat: channel.chat_id }));
        clearChatDialogOpen.value = false;
        clearChatTarget.value = null;
        showToast({ message: t('clients.detail.toastChatCleared') });
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } } };
        showToast({ message: err.response?.data?.message || t('clients.detail.toastChatClearError') });
    } finally {
        clearing.value = false;
    }
}
</script>

<template>
    <teleport to="body">
        <div
            v-if="client"
            class="fixed inset-0 z-[450] flex items-center justify-center p-3 sm:p-6"
            :style="{ background: 'rgba(0,0,0,.45)' }"
            @pointerdown="onBackdropPointerDown"
            @click="onBackdropClick"
        >
            <div
                class="client-detail-modal flex w-full max-w-[960px] flex-col rounded-2xl border overflow-hidden"
                :style="{ background: 'var(--ui-surface)', borderColor: 'var(--ui-border)' }"
                role="dialog"
                aria-modal="true"
                :aria-label="t('clients.detail.profileAria', { name: displayName })"
                @pointerdown="onPanelPointerDown"
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
                                :style="{ background: client.stage.color || 'var(--ui-accent)', color: 'var(--ui-accent-on)' }"
                            >
                                {{ client.stage.name }}
                            </span>
                        </div>
                        <div class="flex shrink-0 items-center gap-1">
                            <template v-if="canManageContactFields">
                                <button
                                    type="button"
                                    class="ui-btn ui-btn--secondary ui-btn--sm"
                                    :title="t('clients.detail.fieldsTitle')"
                                    @click="fieldPickerOpen = true"
                                >
                                    {{ t('clients.detail.fieldsButton') }}
                                </button>
                                <button
                                    type="button"
                                    class="ui-btn ui-btn--primary ui-btn--sm ui-btn--icon text-base leading-none"
                                    :aria-label="t('clients.detail.addField')"
                                    :title="t('clients.detail.addField')"
                                    @click="addFieldOpen = true"
                                >
                                    +
                                </button>
                            </template>
                            <button
                                type="button"
                                class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm text-base leading-none"
                                :aria-label="t('common.close')"
                                @click="emit('close')"
                            >
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
                            <div class="shrink-0 px-4 py-3 text-xs font-medium uppercase tracking-wide opacity-70">{{ t('clients.detail.aiSummary') }}</div>
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

                    <section
                        v-if="canClearClientData && client"
                        class="shrink-0 border-t px-4 py-3"
                        :style="{ borderColor: 'var(--ui-border)', background: 'var(--ui-surface-muted)' }"
                    >
                        <div class="text-xs font-semibold uppercase tracking-wide opacity-70">
                            {{ t('clients.detail.dataActionsTitle') }}
                        </div>
                        <p class="mt-1 text-xs leading-relaxed opacity-70">
                            {{ t('clients.detail.dataActionsHint') }}
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                type="button"
                                class="ui-btn ui-btn--danger-ghost ui-btn--sm"
                                @click="clearMemoryDialogOpen = true"
                            >
                                {{ t('clients.detail.clearMemory') }}
                            </button>
                            <button
                                v-for="channel in client.channels"
                                :key="channel.chat_id"
                                type="button"
                                class="ui-btn ui-btn--danger-ghost ui-btn--sm"
                                @click="openClearChatDialog(channel)"
                            >
                                {{ t('clients.detail.clearChatFor', { label: channel.session_label || channel.chat_name || `#${channel.chat_id}` }) }}
                            </button>
                        </div>
                    </section>

                    <footer class="shrink-0 flex flex-wrap items-center gap-[var(--primitive-gap-sm)] border-t border-[var(--ui-border)] px-4 py-3">
                        <input
                            v-model="editingName"
                            type="text"
                            class="ui-input min-w-[140px] flex-1 !min-h-[30px] !py-0 text-sm"
                            :placeholder="t('clients.detail.savedName')"
                        />
                        <button
                            type="button"
                            class="ui-btn ui-btn--primary ui-btn--sm"
                            :disabled="saving"
                            @click="saveName"
                        >
                            {{ t('clients.detail.saveName') }}
                        </button>
                        <Link
                            v-if="chatUrl"
                            :href="chatUrl"
                            class="ui-btn ui-btn--secondary ui-btn--sm"
                        >
                            {{ t('clients.detail.openChat') }}
                        </Link>
                        <button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" @click="emit('close')">
                            {{ t('common.close') }}
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

        <DangerConfirmModal
            :open="clearMemoryDialogOpen"
            :title="t('clients.detail.clearMemoryTitle')"
            :description="t('clients.detail.clearMemoryDescription')"
            :confirm-label="t('clients.detail.clearMemoryConfirm')"
            :busy="clearing"
            confirm-variant="danger"
            @close="clearMemoryDialogOpen = false"
            @confirm="confirmClearMemory"
        />

        <DangerConfirmModal
            :open="clearChatDialogOpen"
            :title="t('clients.detail.clearChatTitle')"
            :description="t('clients.detail.clearChatDescription', { label: clearChatTarget?.session_label || clearChatTarget?.chat_name || '' })"
            :confirm-label="t('clients.detail.clearChatConfirm')"
            :busy="clearing"
            confirm-variant="danger"
            @close="clearChatDialogOpen = false"
            @confirm="confirmClearChat"
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
