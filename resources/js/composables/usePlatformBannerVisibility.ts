import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

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
