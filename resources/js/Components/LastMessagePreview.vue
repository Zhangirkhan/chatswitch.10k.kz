<script setup lang="ts">
import { computed } from 'vue';
import type { Chat } from '@/types';
import { useI18n } from '@/composables/useI18n';
import { stripWaMarkup } from '@/utils/waMarkup';

/**
 * Превью последнего сообщения в списке чатов.
 *
 * Даёт WhatsApp-подобный формат с иконкой и русской подписью:
 *   • фото              → 📷 Фото (или caption, если есть)
 *   • видео             → 🎥 Видео (или caption)
 *   • голосовое         → 🎤 Голосовое сообщение (0:12)
 *   • документ          → 📄 имя_файла.pdf (или «Документ»)
 *   • стикер/гиф        → соответствующая плашка
 *   • контакт/опрос     → имя контакта / вопрос опроса
 *   • обычный текст     → просто тело сообщения
 *
 * Тип и медиа берём из eager-loaded отношения `chat.latest_message`,
 * а если его нет (старые payload'ы) — фолбек на денормализованное
 * `chat.last_message_text`.
 */

const props = defineProps<{
    chat: Chat;
    emptyText?: string;
}>();

const { t } = useI18n();

type MediaKind =
    | 'image'
    | 'video'
    | 'audio'
    | 'voice'
    | 'sticker'
    | 'gif'
    | 'document'
    | 'contact'
    | 'poll'
    | 'chat';

function detectKind(type: string, mime: string | null): MediaKind {
    const t = (type || '').toLowerCase();
    const m = (mime || '').toLowerCase();

    // Сперва спец-случаи WhatsApp, где type говорит сам за себя.
    if (t === 'ptt' || t === 'voice') return 'voice';
    if (t === 'sticker') return 'sticker';
    if (t === 'gif') return 'gif';
    if (t === 'contact' || t === 'vcard' || t === 'multi_vcard') return 'contact';
    if (t === 'poll' || t === 'poll_creation') return 'poll';
    if (t === 'document') return 'document';

    // Для видео/аудио/картинок тип не всегда совпадает с mime (image/webp = стикер,
    // image/gif = GIF, audio/ogg = голосовое), поэтому уточняем по mime.
    if (t === 'image') {
        if (m === 'image/webp') return 'sticker';
        if (m === 'image/gif') return 'gif';
        return 'image';
    }
    if (t === 'video') return 'video';
    if (t === 'audio') {
        return m === 'audio/ogg' ? 'voice' : 'audio';
    }

    // Фолбэк по одному только mime (если type = 'chat', но media висит).
    if (m === 'image/gif') return 'gif';
    if (m === 'image/webp') return 'sticker';
    if (m.startsWith('image/')) return 'image';
    if (m.startsWith('video/')) return 'video';
    if (m === 'audio/ogg') return 'voice';
    if (m.startsWith('audio/')) return 'audio';

    return 'chat';
}

function formatDuration(total: number): string {
    const s = Math.max(0, Math.round(total));
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m}:${r.toString().padStart(2, '0')}`;
}

function unescapeVcardValue(value: string): string {
    return value
        .replace(/\\n/gi, '\n')
        .replace(/\\,/g, ',')
        .replace(/\\;/g, ';')
        .replace(/\\\\/g, '\\')
        .trim();
}

function contactNameFromVcard(raw: string | null | undefined): string {
    const body = String(raw || '');
    if (!body.includes('BEGIN:VCARD')) {
        return '';
    }
    const lines = body.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    const fnLine = lines.find((line) => /^FN:/i.test(line));
    const nLine = lines.find((line) => /^N:/i.test(line));
    const telLine = lines.find((line) => /^TEL/i.test(line));
    const name = unescapeVcardValue((fnLine || nLine || '').replace(/^[^:]*:/, '').replace(/;+$/g, ''));
    const phone = unescapeVcardValue((telLine || '').replace(/^[^:]*:/, ''));
    return name || phone;
}

const preview = computed<{ kind: MediaKind; label: string } | null>(() => {
    const latest = props.chat.latest_message;
    const fallbackBody = stripWaMarkup(props.chat.last_message_text).trim();

    if (!latest) {
        // Старый payload без eager-loaded latest_message — рендерим просто текст.
        return fallbackBody !== '' ? { kind: 'chat', label: fallbackBody } : null;
    }

    const firstMedia = latest.media?.[0] ?? null;
    const kind = detectKind(latest.type ?? 'chat', firstMedia?.mime_type ?? null);
    const caption = stripWaMarkup(latest.body).trim();

    switch (kind) {
        case 'image':
            return { kind, label: caption !== '' ? caption : t('chats.preview.photo') };
        case 'video':
            return { kind, label: caption !== '' ? caption : t('chats.preview.video') };
        case 'audio':
            return { kind, label: caption !== '' ? caption : t('chats.preview.audio') };
        case 'voice': {
            const raw = (latest.metadata as { media?: { duration?: number } } | null | undefined)
                ?.media?.duration;
            const duration = typeof raw === 'number' && Number.isFinite(raw) ? raw : null;
            const base = t('chats.preview.voice');
            return {
                kind,
                label: duration !== null ? t('chats.preview.voiceWithDuration', { duration: formatDuration(duration) }) : base,
            };
        }
        case 'sticker':
            return { kind, label: t('chats.preview.sticker') };
        case 'gif':
            return { kind, label: caption !== '' ? caption : t('chats.preview.gif') };
        case 'document': {
            const filename = firstMedia?.filename?.trim() || '';
            if (caption !== '') {
                return { kind, label: caption };
            }
            return { kind, label: filename !== '' ? filename : t('chats.preview.document') };
        }
        case 'contact': {
            const metaContact = (latest.metadata as { contact?: { name?: string | null; phone?: string | null } } | null | undefined)?.contact;
            const metaName = (metaContact?.name || metaContact?.phone || '').trim();
            const vcardName = contactNameFromVcard(latest.body);
            return { kind, label: metaName || vcardName || t('chats.preview.contact') };
        }
        case 'poll':
            return { kind, label: caption !== '' ? t('chats.preview.pollWithCaption', { caption }) : t('chats.preview.poll') };
        case 'chat':
        default:
            if (caption !== '') return { kind: 'chat', label: caption };
            return fallbackBody !== '' ? { kind: 'chat', label: fallbackBody } : null;
    }
});
</script>

<template>
    <span v-if="preview" class="inline-flex items-center gap-1 min-w-0">
        <!-- Фото -->
        <svg
            v-if="preview.kind === 'image'"
            class="wa-preview-icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="M4 7h3l2-2h6l2 2h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1z" />
            <circle cx="12" cy="13" r="3.5" />
        </svg>

        <!-- Видео -->
        <svg
            v-else-if="preview.kind === 'video'"
            class="wa-preview-icon"
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
        >
            <path d="M4 6.5A1.5 1.5 0 0 1 5.5 5h9A1.5 1.5 0 0 1 16 6.5v11A1.5 1.5 0 0 1 14.5 19h-9A1.5 1.5 0 0 1 4 17.5v-11zM17 9.3l3.25-1.87A.75.75 0 0 1 21.5 8v8a.75.75 0 0 1-1.25.58L17 14.7v-5.4z" />
        </svg>

        <!-- GIF -->
        <svg
            v-else-if="preview.kind === 'gif'"
            class="wa-preview-icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <path d="M9 10v4" />
            <path d="M7 10h2" />
            <path d="M12 10h3" />
            <path d="M12 12.5h2" />
            <path d="M17 10v4" />
        </svg>

        <!-- Стикер -->
        <svg
            v-else-if="preview.kind === 'sticker'"
            class="wa-preview-icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10l4-4V8l-6-5z" />
            <path d="M14 3v5h6" />
            <path d="M14 14l1.5 3L19 14" />
        </svg>

        <!-- Аудио -->
        <svg
            v-else-if="preview.kind === 'audio'"
            class="wa-preview-icon"
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
        >
            <path d="M12 3a1 1 0 0 1 1 1v11.17A4 4 0 1 1 11 12V7l8-2v7.17A4 4 0 1 1 17 9V4l-5 1.25V4a1 1 0 0 1 0-1z" />
        </svg>

        <!-- Голосовое -->
        <svg
            v-else-if="preview.kind === 'voice'"
            class="wa-preview-icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <rect x="9" y="3" width="6" height="12" rx="3" />
            <path d="M5 11a7 7 0 0 0 14 0" />
            <path d="M12 18v3" />
        </svg>

        <!-- Документ -->
        <svg
            v-else-if="preview.kind === 'document'"
            class="wa-preview-icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5z" />
            <path d="M14 3v5h5" />
            <path d="M9 13h6" />
            <path d="M9 17h6" />
        </svg>

        <!-- Контакт -->
        <svg
            v-else-if="preview.kind === 'contact'"
            class="wa-preview-icon"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.8"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
        >
            <circle cx="12" cy="8" r="3.5" />
            <path d="M5 20a7 7 0 0 1 14 0" />
        </svg>

        <!-- Опрос -->
        <svg
            v-else-if="preview.kind === 'poll'"
            class="wa-preview-icon"
            viewBox="0 0 24 24"
            fill="currentColor"
            aria-hidden="true"
        >
            <rect x="4" y="12" width="3" height="8" rx="1" />
            <rect x="10.5" y="7" width="3" height="13" rx="1" />
            <rect x="17" y="4" width="3" height="16" rx="1" />
        </svg>

        <span class="truncate">{{ preview.label }}</span>
    </span>
    <span v-else class="truncate">{{ emptyText ?? t('chats.preview.noMessages') }}</span>
</template>

<style scoped>
.wa-preview-icon {
    width: 14px;
    height: 14px;
    flex-shrink: 0;
    color: currentColor;
    opacity: 0.85;
}
</style>
