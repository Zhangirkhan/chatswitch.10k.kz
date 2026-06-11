import { computed, type Ref } from 'vue';
import { useLandingLocale } from '@/composables/useLandingLocale';
import type { MessageKey } from '@/i18n/types';

export type LandingHeadPage = 'home' | 'calculator' | 'faq';

export function useLandingHead(page: Ref<LandingHeadPage> | LandingHeadPage = 'home') {
    const { t } = useLandingLocale();

    const metaTitle = computed(() => {
        const pageKey = typeof page === 'string' ? page : page.value;

        return t(`landing.meta.${pageKey}.title` as MessageKey);
    });

    const metaDescription = computed(() => {
        const pageKey = typeof page === 'string' ? page : page.value;

        return t(`landing.meta.${pageKey}.description` as MessageKey);
    });

    return {
        metaTitle,
        metaDescription,
    };
}
