import { computed } from 'vue';
import { useLandingLocale } from '@/composables/useLandingLocale';

export function useLandingHead() {
    const { t } = useLandingLocale();

    const metaTitle = computed(() => t('landing.ogTitle'));
    const metaDescription = computed(() => t('landing.metaDescription'));

    return {
        metaTitle,
        metaDescription,
    };
}
