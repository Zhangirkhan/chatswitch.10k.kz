<script setup lang="ts">
import { ref } from 'vue';
import axios from 'axios';
import { useToastStore } from '@/stores/toast';
import { useI18n } from '@/composables/useI18n';

const { t } = useI18n();

export type PendingOrchestratorApproval = {
    run_id: number;
    summary: string;
    appointment_label: string | null;
    stage_name: string | null;
    manager_note: string | null;
};

const props = defineProps<{
    chatId: number;
    pending: PendingOrchestratorApproval;
}>();

const emit = defineEmits<{
    approved: [payload: { ai_orchestrator_status: string | null; ai_orchestrator_last_summary: string | null }];
    cleared: [];
}>();

const { show: showToast } = useToastStore();
const submitting = ref(false);

async function approve(): Promise<void> {
    if (submitting.value) {
        return;
    }
    submitting.value = true;
    try {
        const res = await axios.post(
            route('chats.orchestrator.approve', { chat: props.chatId, run: props.pending.run_id }),
        );
        const chat = res.data?.chat;
        emit('approved', {
            ai_orchestrator_status: chat?.ai_orchestrator_status ?? 'completed',
            ai_orchestrator_last_summary: chat?.ai_orchestrator_last_summary ?? null,
        });
        emit('cleared');
        showToast({ message: t('chats.orchestrator.approved'), duration: 3500 });
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } }; message?: string };
        const msg = err?.response?.data?.message ?? err?.message ?? t('chats.orchestrator.approveFailed');
        showToast({ message: msg, duration: 4500 });
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div
        class="mx-3 mb-2 rounded-xl border px-3 py-2.5 text-[13px] leading-snug"
        style="
            border-color: color-mix(in srgb, var(--wa-accent) 45%, transparent);
            background: color-mix(in srgb, var(--wa-accent) 12%, var(--wa-panel));
            color: var(--wa-text);
        "
        role="status"
    >
        <p class="font-medium" style="color: var(--wa-accent)">{{ t('chats.orchestrator.title') }}</p>
        <p class="mt-1">{{ pending.summary }}</p>
        <p v-if="pending.manager_note" class="mt-1 opacity-80">{{ pending.manager_note }}</p>
        <div class="mt-2.5 flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-lg px-3 py-1.5 text-[13px] font-medium text-white disabled:opacity-60"
                style="background: var(--wa-accent)"
                :disabled="submitting"
                @click="approve"
            >
                {{ submitting ? t('chats.orchestrator.approving') : t('chats.orchestrator.approve') }}
            </button>
        </div>
        <p class="mt-2 text-[12px] opacity-70">
            {{ t('chats.orchestrator.hint') }}
        </p>
    </div>
</template>
