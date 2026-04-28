import { onBeforeUnmount, watch } from 'vue';

type Listener = (payload: unknown) => void;
type Listeners = Record<string, Listener>;

declare global {
    interface Window {
        Echo?: any;
    }
}

/**
 * Typed wrapper around Laravel Echo private channels.
 * Automatically leaves the channel on unmount/channel change.
 */
export function useEchoChannel(channelName: () => string | null, listeners: () => Listeners) {
    let current: { name: string; channel: any } | null = null;

    function teardown() {
        if (!current || !window.Echo) return;
        window.Echo.leave(current.name);
        current = null;
    }

    function setup() {
        teardown();

        const name = channelName();
        if (!name || !window.Echo) return;

        const channel = window.Echo.private(name);
        for (const [event, handler] of Object.entries(listeners())) {
            channel.listen(event, handler);
        }
        current = { name, channel };
    }

    watch(() => channelName(), setup, { immediate: true });
    onBeforeUnmount(teardown);
}
