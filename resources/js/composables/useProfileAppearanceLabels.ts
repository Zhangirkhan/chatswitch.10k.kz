import { useI18n } from '@/composables/useI18n';

export function useProfileAppearanceLabels() {
    const { t } = useI18n();

    function messageStyleLabel(id: string): string {
        return t(`profile.messageStyles.${id}.label` as 'profile.messageStyles.whatsapp.label');
    }

    function messageStyleDescription(id: string): string {
        return t(`profile.messageStyles.${id}.description` as 'profile.messageStyles.whatsapp.description');
    }

    function wallpaperLabel(id: string): string {
        return t(`profile.wallpapers.${id}.label` as 'profile.wallpapers.default.label');
    }

    return { messageStyleLabel, messageStyleDescription, wallpaperLabel };
}
