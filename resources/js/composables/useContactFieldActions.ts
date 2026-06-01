import type { ClientProfileField } from '@/Components/Clients/clientProfileTypes';
import axios from 'axios';
import { useToastStore } from '@/stores/toast';
import { invalidateContactProfileCache } from '@/composables/useContactProfile';

export function useContactFieldActions(options: {
    contactId: () => number | null | undefined;
    chatId?: () => number | null | undefined;
    onProfileUpdated?: (profile: import('@/Components/Clients/clientProfileTypes').ClientProfile) => void;
    onPhotoUpdated?: (url: string | null) => void;
}) {
    const { show: showToast } = useToastStore();

    async function saveField(field: ClientProfileField, value: unknown): Promise<void> {
        const contactId = options.contactId();
        if (!contactId || !field.definition_id) {
            return;
        }

        try {
            const { data } = await axios.patch(route('contacts.fields.update', contactId), {
                fields: [{ field_id: field.definition_id, value }],
            });
            options.onProfileUpdated?.(data.profile);
            if (data.contact?.profile_picture_url !== undefined) {
                options.onPhotoUpdated?.(data.contact.profile_picture_url);
            }
        } catch {
            showToast({ message: 'Не удалось сохранить поле', duration: 3500 });
        }
    }

    async function uploadField(field: ClientProfileField, file: File): Promise<void> {
        const contactId = options.contactId();
        if (!contactId || !field.definition_id) {
            return;
        }

        const formData = new FormData();
        formData.append('file', file);

        try {
            const { data } = await axios.post(
                route('contacts.fields.upload', { contact: contactId, fieldDefinition: field.definition_id }),
                formData,
                { headers: { 'Content-Type': 'multipart/form-data' } },
            );
            options.onProfileUpdated?.(data.profile);
            options.onPhotoUpdated?.(data.contact?.profile_picture_url ?? null);
            showToast({ message: 'Файл загружен', duration: 2500 });
        } catch (e: unknown) {
            const err = e as { response?: { data?: { message?: string } } };
            showToast({ message: err.response?.data?.message || 'Не удалось загрузить файл', duration: 4000 });
        }
    }

    async function clearField(field: ClientProfileField): Promise<void> {
        await saveField(field, null);
    }

    function onFieldsConfigUpdated(): void {
        const contactId = options.contactId();
        if (!contactId) {
            return;
        }
        invalidateContactProfileCache(contactId);
    }

    return {
        saveField,
        uploadField,
        clearField,
        onFieldsConfigUpdated,
    };
}
