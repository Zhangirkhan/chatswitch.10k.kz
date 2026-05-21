import axios from 'axios';
import { computed, ref, type ComputedRef, type Ref } from 'vue';

export type ConnectionOverlayMode = 'offline' | 'reconnecting' | 'server';

type PusherConnection = {
    state: string;
    bind: (event: string, cb: (...args: unknown[]) => void) => void;
    unbind: (event: string, cb: (...args: unknown[]) => void) => void;
};

export type ConnectionStatusState = {
    visible: Ref<boolean>;
    mode: ComputedRef<ConnectionOverlayMode>;
    retrying: Ref<boolean>;
    retry: () => Promise<void>;
};

const SHOW_DELAY_MS = 700;
const HIDE_DELAY_MS = 500;
const PROBE_INTERVAL_MS = 12_000;
const PROBE_TIMEOUT_MS = 8_000;

type ConnectionStatusInternal = ConnectionStatusState & {
    markNetworkUnreachable: () => void;
    markNetworkReachable: () => void;
    runProbe: () => Promise<void>;
    bindGlobals: () => void;
    teardownGlobals: () => void;
};

let shared: ConnectionStatusInternal | null = null;

function createConnectionStatus(): ConnectionStatusInternal {
    const browserOnline = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
    const networkReachable = ref(true);
    const socketState = ref<'connected' | 'connecting' | 'disconnected'>('connected');
    const wasSocketConnected = ref(false);
    const visible = ref(false);
    const retrying = ref(false);

    let showTimer: number | null = null;
    let hideTimer: number | null = null;
    let echoPollTimer: number | null = null;
    let probeTimer: number | null = null;
    let probing = false;
    let globalsBound = false;

    const mode = computed<ConnectionOverlayMode>(() => {
        if (!browserOnline.value || !networkReachable.value) {
            return 'offline';
        }
        if (socketState.value === 'connecting') {
            return 'reconnecting';
        }

        return 'server';
    });

    const shouldBlock = computed(() => {
        if (!browserOnline.value || !networkReachable.value) {
            return true;
        }

        if (!wasSocketConnected.value) {
            return false;
        }

        return socketState.value !== 'connected';
    });

    function clearTimers(): void {
        if (showTimer !== null) {
            window.clearTimeout(showTimer);
            showTimer = null;
        }
        if (hideTimer !== null) {
            window.clearTimeout(hideTimer);
            hideTimer = null;
        }
    }

    function syncVisibility(): void {
        clearTimers();

        if (shouldBlock.value) {
            hideTimer = null;
            showTimer = window.setTimeout(() => {
                visible.value = true;
                showTimer = null;
            }, SHOW_DELAY_MS);
            return;
        }

        if (!visible.value) {
            return;
        }

        hideTimer = window.setTimeout(() => {
            visible.value = false;
            hideTimer = null;
        }, HIDE_DELAY_MS);
    }

    async function probeNetwork(): Promise<boolean> {
        if (typeof navigator !== 'undefined' && !navigator.onLine) {
            return false;
        }

        try {
            const response = await fetch('/up', {
                method: 'GET',
                cache: 'no-store',
                credentials: 'same-origin',
                signal: AbortSignal.timeout(PROBE_TIMEOUT_MS),
            });

            return response.ok;
        } catch {
            return false;
        }
    }

    async function runProbe(): Promise<void> {
        if (probing || typeof document === 'undefined' || document.visibilityState === 'hidden') {
            return;
        }

        probing = true;
        try {
            const reachable = await probeNetwork();
            networkReachable.value = reachable;
            if (!reachable && typeof navigator !== 'undefined') {
                browserOnline.value = navigator.onLine;
            }
            syncVisibility();
        } finally {
            probing = false;
        }
    }

    function mapPusherState(state: string): 'connected' | 'connecting' | 'disconnected' {
        if (state === 'connected') {
            return 'connected';
        }
        if (state === 'connecting' || state === 'initialized') {
            return 'connecting';
        }

        return 'disconnected';
    }

    function onStateChange(states: unknown): void {
        const payload = states as { current?: string };
        const current = typeof payload?.current === 'string' ? payload.current : 'disconnected';
        const mapped = mapPusherState(current);

        if (mapped === 'connected') {
            wasSocketConnected.value = true;
        }

        socketState.value = mapped;
        syncVisibility();
    }

    function onConnected(): void {
        wasSocketConnected.value = true;
        socketState.value = 'connected';
        syncVisibility();
    }

    function onDisconnected(): void {
        if (wasSocketConnected.value) {
            socketState.value = 'disconnected';
            syncVisibility();
        }
    }

    let boundConnection: PusherConnection | null = null;
    let stateChangeHandler: ((states: unknown) => void) | null = null;
    let connectedHandler: (() => void) | null = null;
    let disconnectedHandler: (() => void) | null = null;

    function detachPusher(): void {
        if (!boundConnection) {
            return;
        }

        if (stateChangeHandler) {
            boundConnection.unbind('state_change', stateChangeHandler);
        }
        if (connectedHandler) {
            boundConnection.unbind('connected', connectedHandler);
        }
        if (disconnectedHandler) {
            boundConnection.unbind('disconnected', disconnectedHandler);
        }

        boundConnection = null;
        stateChangeHandler = null;
        connectedHandler = null;
        disconnectedHandler = null;
    }

    function attachPusher(): boolean {
        const Echo = (window as Window & { Echo?: { connector?: { pusher?: { connection?: PusherConnection } } } }).Echo;
        const connection = Echo?.connector?.pusher?.connection;
        if (!connection || boundConnection === connection) {
            return Boolean(connection);
        }

        detachPusher();
        boundConnection = connection;

        stateChangeHandler = onStateChange;
        connectedHandler = onConnected;
        disconnectedHandler = onDisconnected;

        connection.bind('state_change', stateChangeHandler);
        connection.bind('connected', connectedHandler);
        connection.bind('disconnected', disconnectedHandler);

        const mapped = mapPusherState(connection.state);
        if (mapped === 'connected') {
            wasSocketConnected.value = true;
        }
        socketState.value = mapped;
        syncVisibility();

        return true;
    }

    function onBrowserOnline(): void {
        browserOnline.value = true;
        void runProbe().then(() => {
            attachPusher();
        });
    }

    function onBrowserOffline(): void {
        browserOnline.value = false;
        networkReachable.value = false;
        if (wasSocketConnected.value) {
            socketState.value = 'disconnected';
        }
        syncVisibility();
    }

    function onVisibilityChange(): void {
        if (document.visibilityState === 'visible') {
            void runProbe();
        }
    }

    function startEchoPoll(): void {
        if (attachPusher() || echoPollTimer !== null) {
            return;
        }

        let waited = 0;
        echoPollTimer = window.setInterval(() => {
            waited += 400;
            if (attachPusher() || waited >= 20_000) {
                if (echoPollTimer !== null) {
                    window.clearInterval(echoPollTimer);
                    echoPollTimer = null;
                }
            }
        }, 400);
    }

    function startProbeInterval(): void {
        if (probeTimer !== null) {
            return;
        }

        probeTimer = window.setInterval(() => {
            void runProbe();
        }, PROBE_INTERVAL_MS);
    }

    function bindGlobals(): void {
        if (globalsBound || typeof window === 'undefined') {
            return;
        }

        globalsBound = true;

        window.addEventListener('online', onBrowserOnline);
        window.addEventListener('offline', onBrowserOffline);
        document.addEventListener('visibilitychange', onVisibilityChange);

        browserOnline.value = navigator.onLine;
        startEchoPoll();
        startProbeInterval();
        void runProbe();
        syncVisibility();
    }

    function teardownGlobals(): void {
        if (!globalsBound || typeof window === 'undefined') {
            return;
        }

        globalsBound = false;
        window.removeEventListener('online', onBrowserOnline);
        window.removeEventListener('offline', onBrowserOffline);
        document.removeEventListener('visibilitychange', onVisibilityChange);

        if (echoPollTimer !== null) {
            window.clearInterval(echoPollTimer);
            echoPollTimer = null;
        }
        if (probeTimer !== null) {
            window.clearInterval(probeTimer);
            probeTimer = null;
        }

        clearTimers();
        detachPusher();
    }

    async function retry(): Promise<void> {
        if (retrying.value) {
            return;
        }

        retrying.value = true;
        try {
            const Echo = (window as Window & { Echo?: { connector?: { pusher?: { connect?: () => void } } } }).Echo;
            Echo?.connector?.pusher?.connect?.();

            browserOnline.value = navigator.onLine;
            await runProbe();

            if (networkReachable.value) {
                await axios.get('/up', {
                    timeout: 12_000,
                    validateStatus: (status) => status < 500,
                });
            }

            attachPusher();
            syncVisibility();

            if (
                browserOnline.value
                && networkReachable.value
                && (!wasSocketConnected.value || socketState.value === 'connected')
            ) {
                visible.value = false;
                return;
            }

            window.location.reload();
        } catch {
            window.location.reload();
        } finally {
            retrying.value = false;
        }
    }

    function markNetworkUnreachable(): void {
        networkReachable.value = false;
        syncVisibility();
    }

    function markNetworkReachable(): void {
        if (!networkReachable.value) {
            networkReachable.value = true;
            syncVisibility();
        }
    }

    return {
        visible,
        mode,
        retrying,
        retry,
        markNetworkUnreachable,
        markNetworkReachable,
        runProbe,
        bindGlobals,
        teardownGlobals,
    };
}

export function useConnectionStatus(): ConnectionStatusState {
    if (!shared) {
        shared = createConnectionStatus();
    }

    shared.bindGlobals();

    return shared;
}

export function notifyNetworkUnreachable(): void {
    if (!shared) {
        shared = createConnectionStatus();
        shared.bindGlobals();
    }

    shared.markNetworkUnreachable();
}

export function notifyNetworkReachable(): void {
    shared?.markNetworkReachable();
}

export function __resetConnectionStatusForTests(): void {
    shared?.teardownGlobals();
    shared = null;
}
