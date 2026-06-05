import { onMounted, ref, watch } from 'vue';

export const RAIL_NAV_ORDER_KEY = 'accel.settings.railNavOrder';

export const DEFAULT_RAIL_NAV_ORDER = [
    'chats',
    'clients',
    'broadcasts',
    'ai_chat',
    'analytics',
    'calendar',
    'funnels',
] as const;

export type RailNavId = (typeof DEFAULT_RAIL_NAV_ORDER)[number];

function normalizeOrder(stored: unknown): RailNavId[] {
    const allowed = new Set<string>(DEFAULT_RAIL_NAV_ORDER);
    const parsed = Array.isArray(stored)
        ? stored.filter((id): id is RailNavId => typeof id === 'string' && allowed.has(id))
        : [];

    const merged = [...parsed];
    for (const id of DEFAULT_RAIL_NAV_ORDER) {
        if (!merged.includes(id)) {
            merged.push(id);
        }
    }

    return merged;
}

function loadOrder(): RailNavId[] {
    if (typeof window === 'undefined') {
        return [...DEFAULT_RAIL_NAV_ORDER];
    }

    try {
        const raw = localStorage.getItem(RAIL_NAV_ORDER_KEY);
        if (raw) {
            return normalizeOrder(JSON.parse(raw));
        }
    } catch {
        /* ignore */
    }

    return [...DEFAULT_RAIL_NAV_ORDER];
}

export function useRailNavOrder() {
    const order = ref<RailNavId[]>(loadOrder());

    onMounted(() => {
        order.value = loadOrder();
    });

    watch(
        order,
        (value) => {
            try {
                localStorage.setItem(RAIL_NAV_ORDER_KEY, JSON.stringify(value));
            } catch {
                /* ignore quota / private mode */
            }
        },
        { deep: true },
    );

    function moveItem(fromIndex: number, toIndex: number): void {
        if (fromIndex === toIndex || fromIndex < 0 || toIndex < 0 || fromIndex >= order.value.length) {
            return;
        }

        const next = [...order.value];
        const [item] = next.splice(fromIndex, 1);
        next.splice(toIndex, 0, item);
        order.value = next;
    }

    return {
        order,
        moveItem,
    };
}
