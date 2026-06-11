import { onBeforeUnmount, watch } from 'vue';

type Listener = (payload: unknown) => void;
type Listeners = Record<string, Listener>;

declare global {
    interface Window {
        Echo?: any;
    }
}

const ECHO_WAIT_MS = 15_000;
const ECHO_POLL_MS = 300;

function waitForEcho(timeoutMs = ECHO_WAIT_MS): Promise<boolean> {
    if (window.Echo) {
        return Promise.resolve(true);
    }

    return new Promise((resolve) => {
        let waited = 0;
        const timer = window.setInterval(() => {
            waited += ECHO_POLL_MS;
            if (window.Echo) {
                window.clearInterval(timer);
                resolve(true);
            } else if (waited >= timeoutMs) {
                window.clearInterval(timer);
                resolve(false);
            }
        }, ECHO_POLL_MS);
    });
}

/**
 * Typed wrapper around Laravel Echo private channels.
 * Automatically leaves the channel on unmount/channel change.
 */
export function useEchoChannel(channelName: () => string | null, listeners: () => Listeners) {
    let current: { name: string; channel: any } | null = null;
    let setupGeneration = 0;

    function teardown() {
        if (!current || !window.Echo) return;
        window.Echo.leave(current.name);
        current = null;
    }

    async function setup() {
        const generation = ++setupGeneration;
        teardown();

        const name = channelName();
        if (!name) return;

        if (!window.Echo) {
            await waitForEcho();
        }

        if (generation !== setupGeneration || !window.Echo) return;

        const resolvedName = channelName();
        if (!resolvedName) return;

        const channel = window.Echo.private(resolvedName);
        for (const [event, handler] of Object.entries(listeners())) {
            channel.listen(event, handler);
        }
        current = { name: resolvedName, channel };
    }

    watch(() => channelName(), () => {
        void setup();
    }, { immediate: true });

    onBeforeUnmount(teardown);
}
