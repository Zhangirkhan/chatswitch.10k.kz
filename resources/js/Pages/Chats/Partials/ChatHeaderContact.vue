<script setup lang="ts">
import { inject } from 'vue';
import Avatar from '@/Components/Avatar.vue';
import FunnelStageIcon from '@/Components/Funnel/FunnelStageIcon.vue';
import { formatPhone } from '@/utils/phone';
import { CHAT_HEADER_VIEW_KEY } from './chatHeaderViewKey';
import type { ChatHeaderViewContext } from './chatHeaderViewContext';

const s = inject(CHAT_HEADER_VIEW_KEY) as ChatHeaderViewContext;
</script>

<template>
        <div
            role="button"
            tabindex="0"
            aria-label="Информация о контакте"
            @click="s.openContactInfo"
            @keydown.enter.prevent="s.openContactInfo"
            @keydown.space.prevent="s.openContactInfo"
            class="cursor-pointer shrink-0"
        >
            <Avatar
                :avatar-url="s.chat.contact?.profile_picture_url"
                :name="s.displayName"
                :is-group="s.chat.is_group"
                :size="40"
            />
        </div>

        <div
            role="button"
            tabindex="0"
            aria-label="Информация о контакте"
            @click="s.openContactInfo"
            @keydown.enter.prevent="s.openContactInfo"
            @keydown.space.prevent="s.openContactInfo"
            class="flex-1 min-w-0 cursor-pointer"
        >
            <h2 class="text-base text-[var(--wa-text)] truncate font-normal">
                {{ s.chat.chat_name || s.chat.contact?.push_name || formatPhone(s.chat.contact?.phone_number) || 'Без имени' }}
            </h2>
            <p class="text-xs text-[var(--wa-text-secondary)] truncate">
                <template v-if="s.typingUsers.size > 0">
                    <span class="text-[var(--wa-accent)]">{{ s.getTypingText() }}</span>
                </template>
                <template v-else>
                    в сети
                </template>
            </p>
            <p
                v-if="s.sessionLine"
                class="text-[11px] leading-tight text-[var(--wa-text-secondary)] truncate opacity-80"
                :title="`Чат ведётся через ваш номер ${s.sessionLine.phone}${s.sessionLine.name ? ` (${s.sessionLine.name})` : ''}`"
            >
                <span class="opacity-60">через</span>
                <span v-if="s.sessionLine.phone" class="ml-1 font-medium tabular-nums">{{ s.sessionLine.phone }}</span>
                <span v-if="s.sessionLine.name" class="ml-1">· {{ s.sessionLine.name }}</span>
            </p>
            <button
                v-if="s.funnelCompactLine"
                type="button"
                class="header-funnel-compact mt-0.5 w-full text-left"
                :class="`header-funnel-compact-${s.aiSnapshotTone}`"
                :title="s.funnelCompactTitle"
                @click.stop="s.funnelModuleVisible ? s.openFunnelModal() : s.emit('open-ai')"
            >
                <span
                    v-if="s.funnelModuleVisible && s.chat.funnel_stage"
                    class="header-funnel-compact-dot shrink-0"
                    :style="{ background: `${s.funnelBarColor}22`, color: s.funnelBarColor }"
                >
                    <FunnelStageIcon :type="s.chat.funnel_stage?.stage_type" :size="10" />
                </span>
                <span class="truncate">{{ s.funnelCompactLine }}</span>
                <span
                    v-if="s.funnelModuleVisible && s.funnelProgressPercent > 0"
                    class="header-funnel-compact-bar shrink-0"
                    aria-hidden="true"
                >
                    <span :style="{ width: `${s.funnelProgressPercent}%`, background: s.funnelBarColor }"></span>
                </span>
            </button>
        </div>
</template>
