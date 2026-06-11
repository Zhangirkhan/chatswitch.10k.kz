import { router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { useEchoChannel } from '@/composables/useEchoChannel';

export type PlatformBannerItem = {
    id: number;
    message: string;
    background_color: string;
    text_color: string;
};

const STORAGE_KEY = 'accel.platformBanner.dismissed';

function readDismissed(): number[] {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed)
            ? parsed.filter((id): id is number => typeof id === 'number')
            : [];
    } catch {
        return [];
    }
}

export function reloadPlatformBanners(): void {
    router.reload({ only: ['platformBanners'] });
}

export function usePlatformBannerVisibility() {
    const page = usePage();
    const dismissedIds = ref<number[]>([]);

    const allBanners = computed(() => {
        const raw = page.props.platformBanners as PlatformBannerItem[] | undefined;

        return Array.isArray(raw) ? raw : [];
    });

    const visibleBanners = computed(() =>
        allBanners.value.filter((banner) => !dismissedIds.value.includes(banner.id)),
    );

    const visibleCount = computed(() => visibleBanners.value.length);

    function dismiss(id: number): void {
        if (!dismissedIds.value.includes(id)) {
            dismissedIds.value = [...dismissedIds.value, id];
            localStorage.setItem(STORAGE_KEY, JSON.stringify(dismissedIds.value));
        }
    }

    onMounted(() => {
        dismissedIds.value = readDismissed();
    });

    watch(allBanners, () => {
        const activeIds = new Set(allBanners.value.map((b) => b.id));
        const next = dismissedIds.value.filter((id) => activeIds.has(id));
        if (next.length !== dismissedIds.value.length) {
            dismissedIds.value = next;
            localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
        }
    });

    return {
        visibleBanners,
        visibleCount,
        dismiss,
    };
}

/** Subscribe once from PlatformBannerStack — reload top bar when banners change in Super Admin. */
export function usePlatformBannerRealtime(): void {
    const page = usePage();

    const tenantChannel = computed(() => {
        const tenantId = Number(page.props.tenantCompanyId || 0);

        return tenantId > 0 ? `t.${tenantId}.platform-banners` : null;
    });

    const adminChannel = computed(() => (
        (page.props.isSuperAdminHost as boolean | undefined) === true ? 'platform.admin-banners' : null
    ));

    useEchoChannel(() => tenantChannel.value, () => ({
        '.platform-banners.changed': () => reloadPlatformBanners(),
    }));

    useEchoChannel(() => adminChannel.value, () => ({
        '.platform-banners.changed': () => reloadPlatformBanners(),
    }));
}
