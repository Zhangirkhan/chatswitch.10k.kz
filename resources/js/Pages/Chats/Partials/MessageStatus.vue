<script setup lang="ts">
import { computed } from 'vue';
import type { Message } from '@/types';

type MessageStatus = 'sent' | 'delivered' | 'read';
type MessageAck = Message['ack'];

const props = defineProps<{
    /** Preferred status (app-level), if present. */
    status?: MessageStatus;
    /** Backend/service ack (fallback). */
    ack?: MessageAck;
}>();

const fillRead = 'var(--wa-ack-read)';
const fillMuted = 'rgba(255,255,255,0.55)';
const fillFailed = 'var(--wa-danger, #f87171)';

const effectiveStatus = computed<MessageStatus>(() => {
    if (props.status) return props.status;
    if (props.ack === 'read') return 'read';
    if (props.ack === 'delivered') return 'delivered';
    if (props.ack === 'sent') return 'sent';
    if (props.ack === 'pending') return 'sent';
    return 'sent';
});

const effectiveFill = computed(() => (effectiveStatus.value === 'read' ? fillRead : fillMuted));
</script>

<template>
    <span class="wa-ack-ticks ml-0.5 flex shrink-0" aria-hidden="true">
        <template v-if="ack === 'failed'">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" :fill="fillFailed">
                <path
                    d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"
                />
            </svg>
        </template>

        <template v-else-if="effectiveStatus === 'delivered' || effectiveStatus === 'read'">
            <svg class="h-3.5 w-[18px]" viewBox="0 0 16 15" :fill="effectiveFill">
                <path
                    d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.879a.32.32 0 0 1-.484.033l-.358-.325a.319.319 0 0 0-.484.032l-.378.483a.418.418 0 0 0 .036.541l1.32 1.266c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51zm-4.1 0l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.879a.32.32 0 0 1-.484.033L1.891 7.769a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.258 3.185c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"
                />
            </svg>
        </template>

        <template v-else>
            <svg class="h-3.5 w-3" viewBox="0 0 16 15" :fill="fillMuted">
                <path
                    d="M10.91 3.316l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.879a.32.32 0 0 1-.484.033L1.891 7.769a.366.366 0 0 0-.515.006l-.423.433a.364.364 0 0 0 .006.514l3.258 3.185c.143.14.361.125.484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"
                />
            </svg>
        </template>
    </span>
</template>
