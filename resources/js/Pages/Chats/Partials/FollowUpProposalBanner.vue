<script setup lang="ts">
import { computed, ref } from 'vue';
import axios from 'axios';
import { useToastStore } from '@/stores/toast';

export type FollowUpProposalVariant = {
    id: string;
    label: string;
    body: string;
    uses_promo: boolean;
    promo_ref: string | null;
};

export type PendingFollowUpProposal = {
    id: number;
    status: string;
    proposals: FollowUpProposalVariant[];
    recommended_id: string | null;
    manager_note: string | null;
    context_summary: string | null;
};

const props = defineProps<{
    chatId: number;
    pending: PendingFollowUpProposal;
}>();

const emit = defineEmits<{
    cleared: [];
    sent: [];
}>();

const { show: showToast } = useToastStore();
const selectedId = ref<string>(props.pending.recommended_id ?? props.pending.proposals[0]?.id ?? '');
const bodyDraft = ref<string>('');
const submitting = ref(false);
const dismissing = ref(false);

const selectedVariant = computed(() =>
    props.pending.proposals.find((p) => p.id === selectedId.value) ?? null,
);

function selectVariant(id: string): void {
    selectedId.value = id;
    const variant = props.pending.proposals.find((p) => p.id === id);
    bodyDraft.value = variant?.body ?? '';
}

if (selectedVariant.value) {
    bodyDraft.value = selectedVariant.value.body;
}

async function sendNow(): Promise<void> {
    if (submitting.value || selectedId.value === '') {
        return;
    }
    submitting.value = true;
    try {
        await axios.post(
            route('chats.follow-up-proposals.send', {
                chat: props.chatId,
                proposal: props.pending.id,
            }),
            {
                variant_id: selectedId.value,
                body: bodyDraft.value.trim() || undefined,
            },
        );
        showToast({ message: 'Сообщение отправлено клиенту.', duration: 3500 });
        emit('sent');
        emit('cleared');
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } }; message?: string };
        showToast({
            message: err?.response?.data?.message ?? err?.message ?? 'Не удалось отправить.',
            duration: 4500,
        });
    } finally {
        submitting.value = false;
    }
}

async function dismiss(): Promise<void> {
    if (dismissing.value) {
        return;
    }
    dismissing.value = true;
    try {
        await axios.post(
            route('chats.follow-up-proposals.dismiss', {
                chat: props.chatId,
                proposal: props.pending.id,
            }),
        );
        emit('cleared');
        showToast({ message: 'Предложения дожима скрыты.', duration: 3000 });
    } catch (e: unknown) {
        const err = e as { response?: { data?: { message?: string } }; message?: string };
        showToast({
            message: err?.response?.data?.message ?? err?.message ?? 'Не удалось скрыть.',
            duration: 4500,
        });
    } finally {
        dismissing.value = false;
    }
}
</script>

<template>
    <div
        class="mx-3 mb-2 rounded-xl border px-3 py-2.5 text-[13px] leading-snug"
        style="
            border-color: color-mix(in srgb, var(--wa-accent) 45%, transparent);
            background: color-mix(in srgb, var(--wa-accent) 10%, var(--wa-panel));
            color: var(--wa-text);
        "
        role="region"
        aria-label="Варианты дожима от AI"
    >
        <p class="font-medium" style="color: var(--wa-accent)">AI: варианты дожима</p>
        <p v-if="pending.context_summary" class="mt-1 opacity-85">{{ pending.context_summary }}</p>
        <p v-if="pending.manager_note" class="mt-1 opacity-80">{{ pending.manager_note }}</p>

        <div class="mt-2.5 space-y-2">
            <label
                v-for="variant in pending.proposals"
                :key="variant.id"
                class="flex cursor-pointer gap-2 rounded-lg border px-2.5 py-2"
                :style="{
                    borderColor:
                        selectedId === variant.id
                            ? 'color-mix(in srgb, var(--wa-accent) 60%, transparent)'
                            : 'var(--wa-border)',
                    background:
                        selectedId === variant.id
                            ? 'color-mix(in srgb, var(--wa-accent) 8%, var(--wa-panel))'
                            : 'transparent',
                }"
            >
                <input
                    type="radio"
                    class="mt-1 shrink-0"
                    :value="variant.id"
                    :checked="selectedId === variant.id"
                    @change="selectVariant(variant.id)"
                />
                <span class="min-w-0">
                    <span class="font-medium">{{ variant.label }}</span>
                    <span v-if="variant.uses_promo" class="ml-1 text-[11px] opacity-70">(со скидкой)</span>
                    <span class="mt-0.5 block text-[12px] opacity-80 line-clamp-3">{{ variant.body }}</span>
                </span>
            </label>
        </div>

        <label class="mt-2.5 block space-y-1">
            <span class="text-[12px] opacity-70">Текст перед отправкой</span>
            <textarea
                v-model="bodyDraft"
                rows="3"
                class="ui-field w-full rounded-lg border px-2.5 py-2 text-[13px]"
                :style="{ borderColor: 'var(--wa-border)', color: 'var(--wa-text)' }"
            />
        </label>

        <div class="mt-2.5 flex flex-wrap gap-2">
            <button
                type="button"
                class="rounded-lg px-3 py-1.5 text-[13px] font-medium text-white disabled:opacity-60"
                style="background: var(--wa-accent)"
                :disabled="submitting || selectedId === ''"
                @click="sendNow"
            >
                {{ submitting ? 'Отправляем…' : 'Отправить клиенту' }}
            </button>
            <button
                type="button"
                class="rounded-lg border px-3 py-1.5 text-[13px] disabled:opacity-60"
                :style="{ borderColor: 'var(--wa-border)' }"
                :disabled="dismissing"
                @click="dismiss"
            >
                {{ dismissing ? '…' : 'Скрыть' }}
            </button>
        </div>
    </div>
</template>
