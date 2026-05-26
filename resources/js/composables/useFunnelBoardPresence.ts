import type { Ref } from 'vue';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { funnelBoardPresenceChannel } from '@/utils/tenantChannels';

export type FunnelBoardViewer = {
    id: number;
    name: string;
};

export function useFunnelBoardPresence(funnelId: Ref<number | null>): {
    viewers: Ref<FunnelBoardViewer[]>;
} {
    const viewers = ref<FunnelBoardViewer[]>([]);
    let presenceChannel: {
        here: (cb: (users: FunnelBoardViewer[]) => void) => void;
        joining: (cb: (user: FunnelBoardViewer) => void) => void;
        leaving: (cb: (user: FunnelBoardViewer) => void) => void;
    } | null = null;

    function teardown(): void {
        const id = funnelId.value;
        const Echo = (window as Window & { Echo?: { leave: (name: string) => void } }).Echo;
        if (Echo && id != null) {
            try {
                const tenantId = Number(usePage().props.tenantCompanyId || 0);
                Echo.leave(funnelBoardPresenceChannel(tenantId, id));
            } catch {
                /* ignore */
            }
        }
        presenceChannel = null;
        viewers.value = [];
    }

    function subscribe(id: number | null): void {
        teardown();
        if (id == null) {
            return;
        }

        const Echo = (window as Window & { Echo?: { join: (name: string) => unknown } }).Echo;
        if (!Echo?.join) {
            return;
        }

        const tenantId = Number(usePage().props.tenantCompanyId || 0);
        presenceChannel = Echo.join(funnelBoardPresenceChannel(tenantId, id)) as typeof presenceChannel;
        presenceChannel?.here((users) => {
            viewers.value = users.filter((u) => u?.id != null);
        });
        presenceChannel?.joining((user) => {
            if (user?.id == null || viewers.value.some((v) => v.id === user.id)) {
                return;
            }
            viewers.value = [...viewers.value, user];
        });
        presenceChannel?.leaving((user) => {
            if (user?.id == null) {
                return;
            }
            viewers.value = viewers.value.filter((v) => v.id !== user.id);
        });
    }

    onMounted(() => {
        subscribe(funnelId.value);
    });

    watch(funnelId, (id) => {
        subscribe(id);
    });

    onBeforeUnmount(() => {
        teardown();
    });

    return { viewers };
}
