<script setup lang="ts">
import { computed, onBeforeUnmount } from 'vue';
import type { Message } from '@/types';

const props = defineProps<{
    message: Message;
    panelWidth?: string;
}>();

const emit = defineEmits<{
    (e: 'close'): void;
}>();

const isOutbound = computed(() => props.message.direction === 'outbound');

function formatDateTime(dateStr: string | null): string {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleString('ru-RU', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const baseTs = computed(() => props.message.message_timestamp || props.message.created_at || null);

const showSent = computed(() => !!baseTs.value);
const showDelivered = computed(() => isOutbound.value && (props.message.ack === 'delivered' || props.message.ack === 'read') && !!baseTs.value);
const showRead = computed(() => isOutbound.value && props.message.ack === 'read' && !!baseTs.value);
const showReceived = computed(() => !isOutbound.value && !!baseTs.value);

function onEscape(e: KeyboardEvent) {
    if (e.key === 'Escape') emit('close');
}
window.addEventListener('keydown', onEscape);
onBeforeUnmount(() => window.removeEventListener('keydown', onEscape));
</script>

<template>
    <aside
        class="shrink-0 h-full flex flex-col border-l overflow-hidden"
        :style="{
            width: props.panelWidth ?? '400px',
            background: 'var(--wa-panel)',
            borderColor: 'var(--wa-border)',
        }"
    >
        <!-- Header -->
        <div
            class="h-[60px] px-4 flex items-center gap-5 shrink-0"
            :style="{ background: 'var(--wa-panel-header)' }"
        >
            <button
                @click="emit('close')"
                class="w-9 h-9 rounded-full flex items-center justify-center hover:bg-[var(--wa-panel-hover)]"
                title="Закрыть"
                type="button"
            >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <h2 class="text-base flex-1" :style="{ color: 'var(--wa-text)' }">
                Данные о сообщении
            </h2>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto wa-scrollbar">
            <!-- Message preview -->
            <div class="px-6 py-6">
                <div
                    class="max-w-[85%] text-[14.2px] rounded-lg pl-2 pr-2 pt-[6px] pb-[8px] wa-shadow"
                    :class="isOutbound ? 'ml-auto rounded-tr-none' : 'mr-auto rounded-tl-none'"
                    :style="{
                        background: isOutbound ? 'var(--wa-bubble-out)' : 'var(--wa-bubble-in)',
                        color: 'var(--wa-bubble-text)',
                    }"
                >
                    <p v-if="message.body" class="whitespace-pre-wrap break-words leading-[19px] pr-14" style="word-break: break-word;">
                        {{ message.body }}
                    </p>
                    <div class="flex items-center justify-end gap-1 -mt-[4px] -mb-[4px] float-right ml-2">
                        <span class="text-[11px] leading-none opacity-70">
                            {{ formatDateTime(baseTs) }}
                        </span>
                    </div>
                    <div class="clear-both"></div>
                </div>
            </div>

            <div class="h-2" :style="{ background: 'var(--wa-bg)' }"></div>

            <!-- Status rows -->
            <div class="px-6 py-6 space-y-5">
                <div v-if="showRead" class="flex items-start gap-3">
                    <svg class="w-5 h-5 mt-0.5" viewBox="0 0 16 15" fill="var(--wa-ack-read)">
                        <path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.879a.32.32 0 0 1-.484.033l-.358-.325a.319.319 0 0 0-.484.032l-.378.483a.418.418 0 0 0 .036.541l1.32 1.266c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51zm-4.1 0l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.879a.32.32 0 0 1-.484.033L1.891 7.769a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.258 3.185c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/>
                    </svg>
                    <div class="min-w-0">
                        <div class="font-medium" :style="{ color: 'var(--wa-text)' }">Прочитано</div>
                        <div class="text-[13px]" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ formatDateTime(baseTs) }}
                        </div>
                    </div>
                </div>

                <div v-if="showDelivered" class="flex items-start gap-3">
                    <svg class="w-5 h-5 mt-0.5" viewBox="0 0 16 15" fill="var(--wa-text-secondary)">
                        <path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.879a.32.32 0 0 1-.484.033l-.358-.325a.319.319 0 0 0-.484.032l-.378.483a.418.418 0 0 0 .036.541l1.32 1.266c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51zm-4.1 0l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.879a.32.32 0 0 1-.484.033L1.891 7.769a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.258 3.185c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/>
                    </svg>
                    <div class="min-w-0">
                        <div class="font-medium" :style="{ color: 'var(--wa-text)' }">Доставлено</div>
                        <div class="text-[13px]" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ formatDateTime(baseTs) }}
                        </div>
                    </div>
                </div>

                <div v-if="showSent" class="flex items-start gap-3">
                    <svg class="w-5 h-5 mt-0.5" viewBox="0 0 16 15" fill="var(--wa-text-secondary)">
                        <path d="M10.91 3.316l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.879a.32.32 0 0 1-.484.033L1.891 7.769a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.258 3.185c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/>
                    </svg>
                    <div class="min-w-0">
                        <div class="font-medium" :style="{ color: 'var(--wa-text)' }">
                            {{ showReceived ? 'Получено' : 'Отправлено' }}
                        </div>
                        <div class="text-[13px]" :style="{ color: 'var(--wa-text-secondary)' }">
                            {{ formatDateTime(baseTs) }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </aside>
</template>

