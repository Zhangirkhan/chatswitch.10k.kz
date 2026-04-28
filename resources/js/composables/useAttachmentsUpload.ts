import axios from 'axios';
import { ref } from 'vue';
import type { MediaKind } from '../types/chat';

export interface PendingAttachment {
    file: File;
    kind: MediaKind;
    caption: string;
    previewUrl: string | null;
}

function detectKind(file: File): MediaKind {
    const mime = file.type;
    if (mime === 'image/gif') return 'gif';
    if (mime === 'image/webp') return 'sticker';
    if (mime.startsWith('image/')) return 'image';
    if (mime.startsWith('video/')) return 'video';
    if (mime === 'audio/ogg') return 'voice';
    if (mime.startsWith('audio/')) return 'audio';
    return 'document';
}

export function useAttachmentsUpload(uploadUrl: () => string) {
    const queue = ref<PendingAttachment[]>([]);
    const uploading = ref(false);
    const lastError = ref<string | null>(null);

    function enqueue(files: FileList | File[]) {
        const arr = Array.isArray(files) ? files : Array.from(files);
        for (const f of arr) {
            const kind = detectKind(f);
            queue.value.push({
                file: f,
                kind,
                caption: '',
                previewUrl: kind === 'image' || kind === 'gif' || kind === 'sticker' ? URL.createObjectURL(f) : null,
            });
        }
    }

    function remove(index: number) {
        const item = queue.value[index];
        if (item?.previewUrl) URL.revokeObjectURL(item.previewUrl);
        queue.value.splice(index, 1);
    }

    function clear() {
        for (const item of queue.value) {
            if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
        }
        queue.value = [];
    }

    async function uploadAll() {
        if (queue.value.length === 0) return [];
        uploading.value = true;
        lastError.value = null;

        const uploaded: unknown[] = [];
        try {
            for (const item of queue.value) {
                const form = new FormData();
                form.append('file', item.file);
                form.append('type', item.kind);
                if (item.caption) form.append('caption', item.caption);

                const { data } = await axios.post(uploadUrl(), form, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });
                uploaded.push(data);
            }
            clear();
            return uploaded;
        } catch (err: any) {
            lastError.value = err?.response?.data?.message ?? err?.message ?? 'Upload failed';
            throw err;
        } finally {
            uploading.value = false;
        }
    }

    return { queue, uploading, lastError, enqueue, remove, clear, uploadAll };
}
