<script setup lang="ts">
import { computed } from 'vue';
import AuthenticatedLayout from './AuthenticatedLayout.vue';
import PanelResizeHandle from '@/Components/Ui/PanelResizeHandle.vue';
import ChatSidebar from '@/Pages/Chats/Partials/ChatSidebar.vue';
import {
    LIST_SIDEBAR_WIDTH_DEFAULTS,
    LIST_SIDEBAR_WIDTH_STORAGE_KEY,
    useResizablePanelWidth,
} from '@/composables/useResizablePanelWidth';
import type { Chat, Paginated } from '@/types';

withDefaults(
    defineProps<{
        chats: Paginated<Chat>;
        selectedChatId?: number;
        search?: string;
        scope?: 'active' | 'archived';
    }>(),
    {
        scope: 'active',
    },
);

const sidebarResize = useResizablePanelWidth({
    storageKey: LIST_SIDEBAR_WIDTH_STORAGE_KEY,
    ...LIST_SIDEBAR_WIDTH_DEFAULTS,
    edge: 'left',
});

const sidebarWidthStyle = computed(() => ({
    width: sidebarResize.widthPx.value,
}));

const sidebarResizing = computed(() => sidebarResize.isResizing.value);
</script>

<template>
    <AuthenticatedLayout>
        <div class="flex h-full min-h-0 w-full bg-[var(--wa-bg)]">
            <div
                class="flex h-full shrink-0 overflow-hidden"
                :class="{ 'hidden sm:flex': selectedChatId }"
                :style="sidebarWidthStyle"
            >
                <ChatSidebar
                    :chats="chats"
                    :selected-chat-id="selectedChatId"
                    :search="search"
                    :scope="scope"
                    class="h-full w-full min-w-0"
                />
            </div>
            <PanelResizeHandle
                class="hidden sm:block"
                :active="sidebarResizing"
                @pointerdown="sidebarResize.onResizePointerDown"
            />
            <div
                class="flex min-h-0 min-w-0 flex-1 flex-col border-l"
                :style="{ borderColor: 'var(--wa-sidebar-divider)' }"
                :class="{ 'hidden sm:flex': !selectedChatId }"
            >
                <slot />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
