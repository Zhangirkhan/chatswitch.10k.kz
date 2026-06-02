<script setup lang="ts">
import { inject } from 'vue';
import { CHAT_HEADER_VIEW_KEY } from './chatHeaderViewKey';
import type { ChatHeaderViewContext } from './chatHeaderViewContext';

const s = inject(CHAT_HEADER_VIEW_KEY) as ChatHeaderViewContext;
</script>

<template>
        <button
            v-if="s.funnelModuleVisible"
            type="button"
            class="absolute inset-x-0 bottom-0 z-[5] h-3 w-full border-0 p-0 m-0 bg-transparent cursor-pointer group"
            :title="s.funnelBarTitle"
            :aria-label="s.funnelBarTitle || 'Воронка продаж — открыть настройку'"
            :aria-valuenow="s.funnelBarCurrentIndex >= 0 ? s.funnelBarCurrentIndex + 1 : 0"
            :aria-valuemin="0"
            :aria-valuemax="s.funnelBarCells.length"
            role="progressbar"
            @click="s.openFunnelModal"
        >
            <span
                v-if="s.funnelBarCells.length"
                class="pointer-events-none absolute inset-x-0 bottom-0 flex h-[3px] gap-px px-px group-hover:opacity-95"
                aria-hidden="true"
            >
                <span
                    v-for="cell in s.funnelBarCells"
                    :key="cell.id"
                    class="min-w-0 flex-1 rounded-[1px] transition-colors duration-500 ease-out"
                    :class="{
                        'ring-1 ring-inset ring-white/35 dark:ring-black/25':
                            cell.index === s.funnelBarCurrentIndex && s.funnelBarCurrentIndex >= 0,
                    }"
                    :style="s.funnelBarCellStyle(cell.index, cell.color)"
                    :title="cell.name"
                />
            </span>
            <span
                v-else
                class="pointer-events-none absolute inset-x-0 bottom-0 h-[3px] rounded-sm bg-black/10 dark:bg-white/10"
                aria-hidden="true"
            />
        </button>
</template>
