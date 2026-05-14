<script setup lang="ts">
import { computed, reactive, ref, onBeforeUnmount, nextTick } from 'vue';
import axios from 'axios';
import { router, usePage } from '@inertiajs/vue3';
import EmojiPicker from './EmojiPicker.vue';
import MessageReactions from './MessageReactions.vue';
import MessageStatus from './MessageStatus.vue';
import { useToastStore } from '@/stores/toast';
import type { Chat, Message, MessageMedia, MessageReaction } from '@/types';
import { formatPhone, isPlausibleInboundSenderPhone } from '@/utils/phone';
import { renderWaMarkup, stripWaMarkup } from '@/utils/waMarkup';
import LinkPreview from '@/Components/LinkPreview.vue';
import { useTranslationLang } from '@/composables/useTranslationLang';

const props = defineProps<{
    message: Message;
    chat?: Chat | null;
    isGroupChat?: boolean;
    searchMode?: boolean;
    selectionMode?: boolean;
    selected?: boolean;
}>();

const emit = defineEmits<{
    (e: 'reply', message: Message): void;
    (e: 'deleted', id: number): void;
    (e: 'reactions-updated', payload: { id: number; reactions: MessageReaction[] }): void;
    (e: 'message-info', message: Message): void;
    (e: 'jump-to', id: number): void;
    (e: 'jump-to-message', id: number): void;
    (e: 'forward', message: Message): void;
    (e: 'toggle-select', id: number): void;
}>();

const page = usePage<any>();
const { show: showToast } = useToastStore();
const currentUserId = computed<number | undefined>(() => page.props.auth?.user?.id);

// ─── Translation ─────────────────────────────────────────────────────────────
const { lang: translateLang, currentOption: translateCurrent } = useTranslationLang();

const translationText  = ref<string | null>(null);
const translationLoading = ref(false);
const translationError = ref(false);
const translationVisible = ref(false);

const TRANSLATION_CACHE_KEY = (msgId: number, lang: string) =>
    `chatswitch.translation.${msgId}.${lang}`;

function getCachedTranslation(msgId: number, lang: string): string | null {
    try { return localStorage.getItem(TRANSLATION_CACHE_KEY(msgId, lang)); } catch { return null; }
}

function setCachedTranslation(msgId: number, lang: string, text: string): void {
    try { localStorage.setItem(TRANSLATION_CACHE_KEY(msgId, lang), text); } catch { /* quota */ }
}

async function toggleTranslation(): Promise<void> {
    const lang = translateLang.value;
    if (lang === 'off') return;

    // Already shown → hide
    if (translationVisible.value) {
        translationVisible.value = false;
        return;
    }

    // Try cache first
    const cached = getCachedTranslation(props.message.id, lang);
    if (cached !== null) {
        translationText.value = cached;
        translationError.value = false;
        translationVisible.value = true;
        return;
    }

    translationLoading.value = true;
    translationError.value = false;
    translationVisible.value = true;

    try {
        const { data } = await axios.post(route('messages.translate', props.message.id), { lang });
        const text = data.translation ?? '';
        setCachedTranslation(props.message.id, lang, text);
        translationText.value = text;
    } catch {
        translationError.value = true;
        translationText.value = null;
    } finally {
        translationLoading.value = false;
    }
}
const roles = computed<string[]>(() => page.props.auth?.user?.roles || []);
const isAdmin = computed(() => roles.value.includes('administrator'));
const isInternalUser = computed(() => roles.value.some((role) => ['administrator', 'manager', 'employee'].includes(role)));
const isAiGenerated = computed(() => {
    const meta = props.message.metadata as { ai?: { generated?: boolean } } | null | undefined;

    return isInternalUser.value && props.message.direction === 'outbound' && meta?.ai?.generated === true;
});
const aiQualityModuleEnabled = computed<boolean>(() => Boolean(page.props.modules?.ai_quality ?? true));

type AiFeedbackRating = 'good' | 'style' | 'facts' | 'long' | 'context';

const aiFeedbackOptions: { id: AiFeedbackRating; label: string; title: string }[] = [
    { id: 'good', label: 'Хорошо', title: 'AI-ответ подходит, правки не нужны' },
    { id: 'style', label: 'Не тот тон', title: 'Ответ звучит не так: слишком сухо, грубо или не в стиле компании' },
    { id: 'facts', label: 'Ошибка', title: 'В ответе есть неверная информация' },
    { id: 'long', label: 'Длинно', title: 'Ответ слишком длинный, нужно короче' },
    { id: 'context', label: 'Нет данных', title: 'AI не хватило информации из базы знаний' },
];

const aiFeedbackSubmitting = ref(false);
const canShowAiFeedback = computed(() => showMessageBody.value && isAiGenerated.value && isInternalUser.value && aiQualityModuleEnabled.value);

async function submitAiFeedback(rating: AiFeedbackRating): Promise<void> {
    if (aiFeedbackSubmitting.value) {
        return;
    }
    aiFeedbackSubmitting.value = true;
    try {
        await axios.post(route('messages.ai-feedback', props.message.id), { rating });
        showToast({ message: 'Оценка сохранена', duration: 2500 });
    } catch (e: any) {
        const raw = e?.response?.data?.message ?? e?.message ?? 'Не удалось сохранить оценку';
        showToast({ message: typeof raw === 'string' ? raw : 'Не удалось сохранить оценку', duration: 4000 });
    } finally {
        aiFeedbackSubmitting.value = false;
    }
}

const pickerOpen = ref(false);
const fullPickerOpen = ref(false);
const pickerX = ref(0);
const pickerY = ref(0);
const isReacting = ref(false);
const menuOpen = ref(false);
const menuX = ref(0);
const menuY = ref(0);
const hovered = ref(false);

const isOutbound = computed(() => props.message.direction === 'outbound');
const isInbound = computed(() => props.message.direction === 'inbound');
const isSystemMessage = computed(() => props.message.direction === 'system');
const defaultQuickReactionEmojis = ['👍', '❤️', '😂', '😮', '😢'];
const quickReactionEmojis = computed<string[]>(() => {
    const configured = page.props.quickReactions;
    if (!Array.isArray(configured)) {
        return defaultQuickReactionEmojis;
    }

    const emojis = configured
        .filter((value): value is string => typeof value === 'string' && value.trim() !== '')
        .map((value) => value.trim())
        .slice(0, 5);

    return emojis.length === 5 ? emojis : defaultQuickReactionEmojis;
});

/** Контекстное меню + полоска реакций (компактно, под референс Telegram / WA). */
const MSG_MENU_WIDTH = 208;
const MSG_MENU_HEIGHT_EST = computed(() => (canShowAiFeedback.value ? 560 : 400));
const QUICK_REACTION_BAR_W = computed(() => (quickReactionEmojis.value.length + 1) * 24 + quickReactionEmojis.value.length * 2 + 16);
const QUICK_REACTION_BAR_H = 32;
const MENU_REACTION_GAP = 6;
const showGroupSender = computed(() => !!props.isGroupChat && isInbound.value);
const isGroup = computed(() => !!props.isGroupChat);
const senderPhoneDigits = computed(() => {
    const raw = (props.message.sender_phone || '').trim();
    if (!raw || !isPlausibleInboundSenderPhone(raw)) return '';
    return raw.replace(/\D/g, '');
});
const canReplyPrivately = computed(() => isGroup.value && isInbound.value && senderPhoneDigits.value !== '' && senderPhoneDigits.value !== '0');
const canShowMessageInfo = computed(() => !isGroup.value && canViewMessageInfo.value);
const groupSenderLabel = computed(() => {
    const name = (props.message.sender_name || '').trim();
    const phoneRaw = (props.message.sender_phone || '').trim();
    const phone = phoneRaw && isPlausibleInboundSenderPhone(phoneRaw) ? formatPhone(phoneRaw) : '';
    if (!name) return phone || '';
    if (!phone) return `~ ${name}`;
    return `~ ${name} · ${phone}`;
});
const canDelete = computed(() => {
    // Only allow deleting own outbound messages. Admins can delete any outbound messages,
    // but never inbound (messages from the contact).
    if (props.message.direction !== 'outbound') return false;
    if (isAdmin.value) return true;
    return props.message.sent_by_user_id === currentUserId.value;
});
const canViewMessageInfo = computed(() =>
    props.message.direction === 'outbound' && props.message.sent_by_user_id === currentUserId.value,
);

const mediaItems = computed(() => props.message.media ?? []);
const hasMediaAttachments = computed(() => mediaItems.value.length > 0);

const quoted = computed(() => (props.message as any).quoted_message || null);
const hasQuoted = computed(() => !!quoted.value && typeof quoted.value === 'object');
const quotedAuthor = computed(() => {
    const q = quoted.value as any;
    if (!q) return '';
    const sentBy = q.sent_by_user?.name ? String(q.sent_by_user.name).trim() : '';
    if (sentBy) return sentBy;
    const name = q.sender_name ? String(q.sender_name).trim() : '';
    if (name) return name;
    const phoneRaw = q.sender_phone ? String(q.sender_phone).trim() : '';
    if (phoneRaw && isPlausibleInboundSenderPhone(phoneRaw)) {
        return formatPhone(phoneRaw) || phoneRaw;
    }
    return '';
});
const quotedPreview = computed(() => {
    const q = quoted.value as any;
    if (!q) return '';
    const text = q.body ? String(q.body).trim() : '';
    if (text) return text;
    if (q.type && q.type !== 'chat') return 'Медиа';
    return '';
});

function onBubbleClick() {
    if (props.selectionMode) {
        emit('toggle-select', props.message.id);
        return;
    }
    if (props.searchMode) {
        emit('jump-to', props.message.id);
    }
}

function jumpToQuoted() {
    const q = quoted.value as any;
    const id = q?.id;
    if (typeof id === 'number') {
        emit('jump-to-message', id);
    }
}

function mediaSrc(mediaId: number): string {
    return route('media.show', mediaId);
}

function startPrivateChat() {
    const phone = senderPhoneDigits.value;
    const sessionId = props.chat?.whatsapp_session_id;
    if (!phone || !sessionId) return;
    router.post(route('chats.start'), { phone, whatsapp_session_id: sessionId }, { preserveScroll: true });
}

function startPrivateChatLabeled() {
    startPrivateChat();
}

function mime(m: { mime_type?: string | null }): string {
    return (m.mime_type || '').trim();
}

function isImageMime(m: { mime_type?: string | null }): boolean {
    return mime(m).startsWith('image/');
}

function isVideoMime(m: { mime_type?: string | null }): boolean {
    return mime(m).startsWith('video/');
}

function isGifLikeMedia(m: MessageMedia): boolean {
    const mt = mime(m).toLowerCase();
    const fn = (m.filename || '').toLowerCase();
    const t = normalizedMessageType.value;
    return t === 'gif' || mt === 'image/gif' || fn.endsWith('.gif');
}

const normalizedMessageType = computed(() => (props.message.type ?? '').toString().toLowerCase());

const isVoiceMessageType = computed(
    () =>
        normalizedMessageType.value === 'ptt' ||
        normalizedMessageType.value === 'voice' ||
        normalizedMessageType.value === 'audio',
);

const VOICE_WAVE_BAR_COUNT = 34;

const showVoiceFallback = computed(
    () => isVoiceMessageType.value && mediaItems.value.length === 0,
);

/** Длительность из метаданных только для заглушки без файла. */
const voiceFallbackDurationLabel = computed((): string | null => {
    const meta = props.message.metadata as { media?: { duration?: number } } | null | undefined;
    const sec = meta?.media?.duration;
    if (typeof sec !== 'number' || !Number.isFinite(sec) || sec < 0) {
        return null;
    }
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return `${m}:${s.toString().padStart(2, '0')}`;
});

const voiceWaveFallbackBars = computed(() => waveformBarHeights(props.message.id));

const contactAvatarUrl = computed((): string | null => {
    const c = props.chat?.contact;
    if (!c) return null;
    const u = (c.profile_picture_url || '').trim();
    return u !== '' ? u : null;
});

const operatorDisplayName = computed(() => {
    const n = (props.message.sent_by_user?.name || page.props.auth?.user?.name || '').trim();
    return n !== '' ? n : '?';
});

const operatorInitial = computed(() => {
    const n = operatorDisplayName.value;
    return n.charAt(0).toUpperCase();
});

type MentionMeta = { id: string; number: string; label: string };
type ContactCard = { name: string; phone: string; avatarUrl?: string | null; contactId?: number | null };
type BodySegment =
    | { type: 'text'; text: string }
    | { type: 'mention'; text: string; number: string; id: string; label: string };

function mentionMetaList(): MentionMeta[] {
    const meta = props.message.metadata as any;
    const list = meta?.mentions;
    if (!Array.isArray(list)) return [];
    return list
        .filter((x: any) => x && typeof x === 'object')
        .map((x: any) => ({
            id: String(x.id || '').trim(),
            number: String(x.number || '').replace(/\D/g, '').trim(),
            label: String(x.label || '').trim(),
        }))
        .filter((m: MentionMeta) => m.id !== '' && m.number !== '' && m.label !== '')
        .slice(0, 20);
}

const bodySegments = computed<BodySegment[]>(() => {
    const body = (props.message.body || '').toString();
    if (!body) return [];
    const mentions = mentionMetaList();
    if (mentions.length === 0) return [{ type: 'text', text: body }];

    // Replace occurrences sequentially in text using metadata order.
    // This is robust against duplicate labels: we always match the next one.
    let remaining = body;
    const out: BodySegment[] = [];
    for (const m of mentions) {
        const needle = `@${m.label}`;
        const idx = remaining.indexOf(needle);
        if (idx < 0) {
            continue;
        }
        const before = remaining.slice(0, idx);
        if (before) out.push({ type: 'text', text: before });
        out.push({ type: 'mention', text: needle, number: m.number, id: m.id, label: m.label });
        remaining = remaining.slice(idx + needle.length);
    }
    if (remaining) out.push({ type: 'text', text: remaining });
    return out.length ? out : [{ type: 'text', text: body }];
});

function openChatForMention(m: { number: string }) {
    const phone = String(m.number || '').replace(/\D/g, '').trim();
    const sessionId = props.chat?.whatsapp_session_id;
    if (!phone || !sessionId) return;
    router.post(route('chats.start'), { phone, whatsapp_session_id: sessionId }, { preserveScroll: true });
}

function unescapeVcardValue(value: string): string {
    return value
        .replace(/\\n/gi, '\n')
        .replace(/\\,/g, ',')
        .replace(/\\;/g, ';')
        .replace(/\\\\/g, '\\')
        .trim();
}

function parseVcardContact(raw: string): ContactCard | null {
    const start = raw.indexOf('BEGIN:VCARD');
    if (start < 0) return null;
    const end = raw.indexOf('END:VCARD', start);
    const vcard = raw.slice(start, end >= 0 ? end + 'END:VCARD'.length : undefined);
    const lines = vcard.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    const fnLine = lines.find((line) => /^FN:/i.test(line));
    const nLine = lines.find((line) => /^N:/i.test(line));
    const telLine = lines.find((line) => /^TEL/i.test(line));
    const name = unescapeVcardValue((fnLine || nLine || '').replace(/^[^:]*:/, '').replace(/;+$/g, ''));
    const phoneRaw = unescapeVcardValue((telLine || '').replace(/^[^:]*:/, ''));
    const phone = phoneRaw.trim();
    if (!name && !phone) return null;
    return { name: name || phone, phone };
}

const contactCard = computed<ContactCard | null>(() => {
    const meta = props.message.metadata as any;
    const contact = meta?.contact;
    if (contact && typeof contact === 'object') {
        const name = String(contact.name || '').trim();
        const phone = String(contact.phone || '').trim();
        const avatarUrl = contact.avatar_url ? String(contact.avatar_url) : null;
        const contactId = Number(contact.id || 0) > 0 ? Number(contact.id) : null;
        if (name || phone) {
            return { name: name || phone, phone, avatarUrl, contactId };
        }
    }

    const body = String(props.message.body || '');
    if (normalizedMessageType.value === 'vcard' || normalizedMessageType.value === 'contact' || body.includes('BEGIN:VCARD')) {
        return parseVcardContact(body);
    }

    return null;
});

function openChatForContactCard(card: ContactCard): void {
    const phone = String(card.phone || '').replace(/\D/g, '').trim();
    const sessionId = props.chat?.whatsapp_session_id;
    if (!phone || !sessionId) return;
    const payload: {
        phone: string;
        whatsapp_session_id: number;
        name: string;
        contact_id?: number;
    } = {
        phone,
        whatsapp_session_id: sessionId,
        name: card.name,
    };
    if (card.contactId) {
        payload.contact_id = card.contactId;
    }
    router.post(route('chats.start'), payload, { preserveScroll: true });
}

function renderSegmentHtml(text: string): string {
    // Keep newlines: message bubble uses whitespace-pre-wrap for text nodes,
    // but v-html needs explicit <br>.
    return renderWaMarkup(text).replace(/\n/g, '<br>');
}

function extractFirstUrl(text: string): string | null {
    const t = (text || '').trim();
    if (!t) return null;
    const m = t.match(/((?:https?:\/\/|www\.)[^\s<>"']+)/i);
    if (!m) return null;
    const raw = (m[1] || '').trim();
    if (!raw) return null;
    return raw.startsWith('http') ? raw : `https://${raw}`;
}

const linkPreviewUrl = computed(() => {
    const body = stripWaMarkup(props.message.body).trim();
    const url = extractFirstUrl(body);
    if (!url) return null;

    // Don't show preview for media-only messages or system types.
    if (normalizedMessageType.value !== 'chat' && normalizedMessageType.value !== 'text') {
        return null;
    }
    if (hasMediaAttachments.value) {
        return null;
    }

    return url;
});

/** Псевдо-волна как в WhatsApp (высоты детерминированы от id сообщения). */
function waveformBarHeights(seed: number): number[] {
    const out: number[] = [];
    let s = Math.abs(seed) % 2147483647;
    if (s === 0) s = 7919;
    for (let i = 0; i < 34; i++) {
        s = (s * 48271 + i) % 2147483647;
        out.push(3 + (s % 15));
    }
    return out;
}

/** Уникальная псевдо-волна на каждое вложение (не только на сообщение). */
function voiceWaveSeed(mediaId: number): number {
    return (props.message.id << 10) ^ mediaId;
}

function voiceWaveBarsForMedia(mediaId: number): number[] {
    return waveformBarHeights(voiceWaveSeed(mediaId));
}

const voiceAudioRefs = new Map<number, HTMLAudioElement>();
const playingVoiceMediaId = ref<number | null>(null);
/** Голос «занят» (после старта до конца): играет или на паузе — для кнопки скорости и контекста */
const voiceEngagedMediaId = ref<number | null>(null);
/** 0..1 для позиции playhead по длине трека */
const voiceProgressRatio = reactive<Record<number, number>>({});
const voiceProgressCleanups = new Map<number, () => void>();
/** Перетаскивание синей точки / скраб по волне */
const voiceScrubbingMediaId = ref<number | null>(null);
/** Скорость воспроизведения по id вложения (1 / 1.5 / 2) */
const voicePlaybackRates = reactive<Record<number, number>>({});

function voicePlaybackRate(mediaId: number): number {
    const r = voicePlaybackRates[mediaId];
    return r === 1.5 || r === 2 ? r : 1;
}

function setVoicePlaybackRate(mediaId: number, rate: 1 | 1.5 | 2): void {
    voicePlaybackRates[mediaId] = rate;
    const el = voiceAudioRefs.get(mediaId);
    if (el) {
        el.playbackRate = rate;
    }
}

/** Один тап по скорости: 1× → 1.5× → 2× → 1× */
function cycleVoicePlaybackRate(mediaId: number): void {
    const cur = voicePlaybackRate(mediaId);
    const next: 1 | 1.5 | 2 = cur === 1 ? 1.5 : cur === 1.5 ? 2 : 1;
    setVoicePlaybackRate(mediaId, next);
}

function voicePlaybackRateButtonLabel(mediaId: number): string {
    const r = voicePlaybackRate(mediaId);
    if (r === 2) return '2x';
    if (r === 1.5) return '1.5x';
    return '1x';
}

function formatVoiceClockSeconds(sec: number): string {
    const s = Math.max(0, Math.floor(sec));
    const m = Math.floor(s / 60);
    const r = s % 60;
    return `${m}:${r.toString().padStart(2, '0')}`;
}

/** Нарастающее время воспроизведения (позиция), не оставшееся. */
function voiceElapsedLabel(mediaId: number): string {
    const el = voiceAudioRefs.get(mediaId);
    const duration = el?.duration;
    if (typeof duration === 'number' && Number.isFinite(duration) && duration > 0) {
        const raw = voiceProgressRatio[mediaId];
        const ratio = typeof raw === 'number' && Number.isFinite(raw) ? Math.min(1, Math.max(0, raw)) : 0;

        return formatVoiceClockSeconds(ratio * duration);
    }

    return '0:00';
}

function voiceWaveBarClass(mediaId: number, barIndex: number): Record<string, boolean> {
    /* «Живая» волна только во время воспроизведения; на паузе — ровная «мертвая» */
    if (playingVoiceMediaId.value !== mediaId) {
        return {};
    }
    const n = VOICE_WAVE_BAR_COUNT;
    if (n <= 0) return {};
    const raw = voiceProgressRatio[mediaId];
    const ratio = typeof raw === 'number' && Number.isFinite(raw) ? Math.min(1, Math.max(0, raw)) : 0;
    if (ratio <= 0) return {};
    const threshold = (barIndex + 1) / n;

    return { 'wa-voice-bar--played': threshold <= ratio };
}

function seekVoiceFromClientX(mediaId: number, clientX: number, trackEl: HTMLElement): void {
    const el = voiceAudioRefs.get(mediaId);
    if (!el) return;
    const rect = trackEl.getBoundingClientRect();
    const pad = 4;
    const usable = rect.width - pad * 2;
    const x = clientX - rect.left - pad;
    const ratio = usable > 0 ? Math.min(1, Math.max(0, x / usable)) : 0;
    const d = el.duration;
    if (Number.isFinite(d) && d > 0) {
        el.currentTime = ratio * d;
    }
    voiceProgressRatio[mediaId] = ratio;
}

function onVoiceWavePointerDown(e: PointerEvent, mediaId: number): void {
    if (props.selectionMode) return;
    const track = e.currentTarget as HTMLElement | null;
    const el = voiceAudioRefs.get(mediaId);
    if (!track || !el || !Number.isFinite(el.duration) || el.duration <= 0) return;
    e.preventDefault();
    e.stopPropagation();
    try {
        track.setPointerCapture(e.pointerId);
    } catch {
        return;
    }
    voiceScrubbingMediaId.value = mediaId;
    seekVoiceFromClientX(mediaId, e.clientX, track);

    const onMove = (ev: PointerEvent): void => {
        if (voiceScrubbingMediaId.value !== mediaId) return;
        seekVoiceFromClientX(mediaId, ev.clientX, track);
    };
    const onUp = (ev: PointerEvent): void => {
        try {
            track.releasePointerCapture(ev.pointerId);
        } catch {
            /* ignore */
        }
        track.removeEventListener('pointermove', onMove);
        track.removeEventListener('pointerup', onUp);
        track.removeEventListener('pointercancel', onUp);
        if (voiceScrubbingMediaId.value === mediaId) {
            voiceScrubbingMediaId.value = null;
        }
        syncVoiceProgressFromAudio(mediaId, el);
    };
    track.addEventListener('pointermove', onMove);
    track.addEventListener('pointerup', onUp);
    track.addEventListener('pointercancel', onUp);
}

function voicePlayheadPositionStyle(mediaId: number): Record<string, string> {
    const r = voiceProgressRatio[mediaId];
    const t = typeof r === 'number' && Number.isFinite(r) ? Math.min(1, Math.max(0, r)) : 0;
    return {
        left: `calc(4px + (100% - 8px) * ${t})`,
        top: '50%',
        transform: 'translate(-50%, -50%)',
    };
}

function syncVoiceProgressFromAudio(mediaId: number, el: HTMLAudioElement): void {
    if (voiceScrubbingMediaId.value === mediaId) {
        return;
    }
    const d = el.duration;
    if (!Number.isFinite(d) || d <= 0) {
        voiceProgressRatio[mediaId] = 0;
        return;
    }
    voiceProgressRatio[mediaId] = el.currentTime / d;
}

function detachVoiceProgress(mediaId: number): void {
    const cleanup = voiceProgressCleanups.get(mediaId);
    if (cleanup) {
        cleanup();
        voiceProgressCleanups.delete(mediaId);
    }
}

function attachVoiceProgress(mediaId: number, el: HTMLAudioElement): void {
    detachVoiceProgress(mediaId);
    const onSeekOrMeta = () => syncVoiceProgressFromAudio(mediaId, el);
    el.addEventListener('seeked', onSeekOrMeta);
    el.addEventListener('loadedmetadata', onSeekOrMeta);

    let rafHandle = 0;
    const loop = () => {
        if (el.paused || el.ended) {
            return;
        }
        syncVoiceProgressFromAudio(mediaId, el);
        rafHandle = requestAnimationFrame(loop);
    };
    rafHandle = requestAnimationFrame(loop);

    voiceProgressCleanups.set(mediaId, () => {
        el.removeEventListener('seeked', onSeekOrMeta);
        el.removeEventListener('loadedmetadata', onSeekOrMeta);
        cancelAnimationFrame(rafHandle);
    });
    syncVoiceProgressFromAudio(mediaId, el);
}

function bindVoiceAudio(mediaId: number, el: unknown) {
    if (el instanceof HTMLAudioElement) {
        voiceAudioRefs.set(mediaId, el);
    } else {
        detachVoiceProgress(mediaId);
        voiceAudioRefs.delete(mediaId);
    }
}

function isVoiceLikeMedia(m: MessageMedia): boolean {
    const fn = (m.filename || '').toLowerCase();
    const mt = mime(m).toLowerCase();
    const t = normalizedMessageType.value;

    if (fn.startsWith('voice-') || fn.startsWith('wa-voice-')) {
        return true;
    }
    if (/\.(ogg|opus|m4a|mp3|wav|aac|oga|caf|flac)(\?|$)/i.test(fn)) {
        return true;
    }
    // .webm часто даётся как video/webm даже для голоса — не считаем роликом, если тип сообщения голосовой / audio mime.
    if (/\.webm(\?|$)/i.test(fn)) {
        if (t === 'video' || t === 'image' || t === 'gif' || t === 'sticker') {
            return false;
        }
        return t === 'ptt' || t === 'voice' || t === 'audio' || mt.startsWith('audio/');
    }
    if (t === 'ptt' || t === 'voice' || t === 'audio') {
        return true;
    }

    return mt.startsWith('audio/');
}

const hasVoiceLikeAttachment = computed(() => mediaItems.value.some((m) => isVoiceLikeMedia(m)));

/** Только подпись оператора — как в WA, не показываем над пузырём голосового. */
const isOperatorSignatureOnlyBody = computed(() => {
    const b = (props.message.body || '').trim();

    return b !== '' && /^\*[^*\n]+\*\s*$/.test(b);
});

const showMessageBody = computed(() => {
    if (!props.message.body?.trim()) {
        return false;
    }
    if (contactCard.value) {
        return false;
    }
    if (showGroupSender.value && groupSenderLabel.value) {
        return true;
    }
    if (isOperatorSignatureOnlyBody.value && hasVoiceLikeAttachment.value) {
        return false;
    }

    return true;
});

function isVisualAttachment(m: MessageMedia): boolean {
    return isImageMime(m) || isVideoMime(m);
}

/** Только картинки/видео — можно full-bleed как в WhatsApp. */
const allMediaAreVisual = computed(
    () => mediaItems.value.length > 0 && mediaItems.value.every((m) => isVisualAttachment(m)),
);

/** Без текста/цитаты/заглушки голоса: фото на всю ширину пузырька, время поверх. */
const fullBleedVisualBubble = computed(
    () =>
        allMediaAreVisual.value &&
        !hasQuoted.value &&
        !showMessageBody.value &&
        !showVoiceFallback.value,
);

const fullBleedHasVideo = computed(
    () => fullBleedVisualBubble.value && mediaItems.value.some((m) => isVideoMime(m) && !isGifLikeMedia(m)),
);

/** Шире обычного пузырька для визуальных вложений (как в мессенджерах на телефоне). */
const wideImageBubble = computed(
    () => allMediaAreVisual.value && !hasQuoted.value && !showVoiceFallback.value,
);

const imageLightboxMediaId = ref<number | null>(null);
const gifVideoRefs = new Map<number, HTMLVideoElement>();

const imageLightboxUrl = computed((): string =>
    imageLightboxMediaId.value != null ? mediaSrc(imageLightboxMediaId.value) : '',
);

function bindGifVideo(mediaId: number, el: unknown) {
    if (el instanceof HTMLVideoElement) {
        gifVideoRefs.set(mediaId, el);
        return;
    }
    gifVideoRefs.delete(mediaId);
}

async function playGifPreview(mediaId: number): Promise<void> {
    const el = gifVideoRefs.get(mediaId);
    if (!el) return;
    try {
        await el.play();
    } catch {
        // ignore autoplay restrictions
    }
}

function stopGifPreview(mediaId: number): void {
    const el = gifVideoRefs.get(mediaId);
    if (!el) return;
    el.pause();
    el.currentTime = 0;
}

function openImageLightbox(mediaId: number): void {
    if (props.selectionMode) {
        emit('toggle-select', props.message.id);
        return;
    }
    imageLightboxMediaId.value = mediaId;
}

function closeImageLightbox(): void {
    imageLightboxMediaId.value = null;
}

/** Скрыть нативный chrome (дублируется классом `.wa-voice-audio-engine` в app.css). */
const hiddenAudioStyle =
    'position:absolute;width:0;height:0;padding:0;margin:0;overflow:hidden;clip-path:inset(50%);opacity:0;pointer-events:none;border:0';

function onVoiceEnded(mediaId: number) {
    if (playingVoiceMediaId.value === mediaId) {
        playingVoiceMediaId.value = null;
    }
    if (voiceEngagedMediaId.value === mediaId) {
        voiceEngagedMediaId.value = null;
    }
    detachVoiceProgress(mediaId);
    voiceProgressRatio[mediaId] = 0;
    delete voicePlaybackRates[mediaId];
    const el = voiceAudioRefs.get(mediaId);
    if (el) {
        el.playbackRate = 1;
    }
}

function toggleVoicePlay(mediaId: number) {
    const el = voiceAudioRefs.get(mediaId);
    if (!el) return;
    if (playingVoiceMediaId.value === mediaId && !el.paused) {
        el.pause();
        detachVoiceProgress(mediaId);
        playingVoiceMediaId.value = null;
        voiceEngagedMediaId.value = mediaId;
        return;
    }
    voiceAudioRefs.forEach((a, id) => {
        if (id !== mediaId) {
            a.pause();
            detachVoiceProgress(id);
            if (playingVoiceMediaId.value === id) {
                playingVoiceMediaId.value = null;
            }
            if (voiceEngagedMediaId.value === id) {
                voiceEngagedMediaId.value = null;
            }
        }
    });
    el.onended = () => onVoiceEnded(mediaId);
    el.playbackRate = voicePlaybackRate(mediaId);
    el.play()
        .then(() => {
            playingVoiceMediaId.value = mediaId;
            voiceEngagedMediaId.value = mediaId;
            attachVoiceProgress(mediaId, el);
        })
        .catch(() => {
            playingVoiceMediaId.value = null;
            if (voiceEngagedMediaId.value === mediaId) {
                voiceEngagedMediaId.value = null;
            }
            detachVoiceProgress(mediaId);
        });
}

function messageTime(): string {
    const value = props.message.message_timestamp || props.message.created_at;
    if (!value) return '';
    return new Date(value).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
}

async function react(emoji: string): Promise<void> {
    if (isReacting.value) return;
    isReacting.value = true;
    try {
        const { data } = await axios.post(route('messages.react', props.message.id), { emoji });
        const reactions = Array.isArray(data.reactions) ? (data.reactions as MessageReaction[]) : [];
        emit('reactions-updated', { id: props.message.id, reactions });
    } catch (e) {
        console.error('React failed', e);
        const message = axios.isAxiosError(e)
            ? (e.response?.data?.message ?? e.response?.data?.error ?? 'Не удалось отправить реакцию.')
            : 'Не удалось отправить реакцию.';
        showToast({ message: String(message), duration: 5000 });
    } finally {
        isReacting.value = false;
        pickerOpen.value = false;
        fullPickerOpen.value = false;
        menuOpen.value = false;
    }
}

async function destroyMessage(): Promise<void> {
    try {
        await axios.delete(route('messages.destroy', props.message.id));
        emit('deleted', props.message.id);
    } catch (e) {
        console.error('Delete failed', e);
    }
}

async function copyMessage(): Promise<void> {
    if (!props.message.body) return;
    try {
        await navigator.clipboard.writeText(props.message.body);
    } catch (e) {
        console.error('Copy failed', e);
    }
}

function replyToMessage() {
    emit('reply', props.message);
}

function showMessageInfo() {
    if (!canViewMessageInfo.value) return;
    emit('message-info', props.message);
}

function reactionPanelBounds(trigger?: HTMLElement | null): DOMRect {
    return trigger?.closest('.chat-bg')?.getBoundingClientRect() ?? document.body.getBoundingClientRect();
}

function placeReactionPanel(x: number, y: number, width: number, height: number, bounds?: DOMRect) {
    const b = bounds ?? document.body.getBoundingClientRect();
    const minX = b.left + 8;
    const maxX = Math.max(minX, b.right - width - 8);
    const minY = 8;
    const maxY = Math.max(minY, window.innerHeight - height - 8);

    pickerX.value = Math.min(Math.max(minX, x), maxX);
    pickerY.value = Math.min(Math.max(minY, y), maxY);
}

function openQuickReactionsAt(x: number, y: number, bounds?: DOMRect) {
    placeReactionPanel(x, y, QUICK_REACTION_BAR_W.value, QUICK_REACTION_BAR_H, bounds);
    pickerOpen.value = true;
    fullPickerOpen.value = false;
}

function openFullPickerAt(x: number, y: number, bounds?: DOMRect) {
    placeReactionPanel(x, y, 360, 360, bounds);
    pickerOpen.value = false;
    fullPickerOpen.value = true;
}

function togglePickerFromTrigger(e: PointerEvent) {
    e.preventDefault();
    e.stopPropagation();

    // Только полоска от ☺ — повторный клик закрывает её
    if (pickerOpen.value && !menuOpen.value) {
        pickerOpen.value = false;
        return;
    }

    if (menuOpen.value) {
        closeMenu();
    }

    fullPickerOpen.value = false;

    const trigger = e.currentTarget as HTMLElement | null;
    const bounds = reactionPanelBounds(trigger);
    const rect = trigger?.getBoundingClientRect();
    if (!rect) {
        openQuickReactionsAt(e.clientX, e.clientY, bounds);
        return;
    }

    const PANEL_WIDTH = QUICK_REACTION_BAR_W.value;
    const PANEL_HEIGHT = QUICK_REACTION_BAR_H;
    const triggerCenterX = rect.left + rect.width / 2;
    const x = triggerCenterX - PANEL_WIDTH / 2;
    let y = rect.top - PANEL_HEIGHT - 8;
    if (y < 8) {
        y = rect.bottom + 8;
    }

    openQuickReactionsAt(x, y, bounds);
}

/** Полный выбор эмодзи из пункта «Отреагировать» — панель над меню, меню закрываем. */
function openFullPickerFromMenu(): void {
    const bounds = reactionPanelBounds(null);
    const pickerW = 360;
    const pickerH = 360;
    const centerX = menuX.value + MSG_MENU_WIDTH / 2 - pickerW / 2;
    const idealY = menuY.value - MENU_REACTION_GAP - pickerH;
    placeReactionPanel(centerX, idealY, pickerW, pickerH, bounds);
    pickerOpen.value = false;
    fullPickerOpen.value = true;
    menuOpen.value = false;
}

function openFullPickerFromQuickBar(): void {
    openFullPickerAt(pickerX.value, pickerY.value);
    menuOpen.value = false;
}

async function reactFromQuickBar(emoji: string): Promise<void> {
    await react(emoji);
}

/**
 * Меню у курсора/кнопки + полоска реакций над ним (как ПКМ в WhatsApp Web).
 * При нехватке места сверху сдвигаем меню вниз, чтобы полоска поместилась.
 */
function openMenuAt(x: number, y: number): void {
    const vw = window.innerWidth;
    const vh = window.innerHeight;

    let menuLeft = x;
    let menuTop = y;
    if (menuLeft + MSG_MENU_WIDTH + 8 > vw) {
        menuLeft = vw - MSG_MENU_WIDTH - 8;
    }
    if (menuLeft < 8) {
        menuLeft = 8;
    }
    if (menuTop + MSG_MENU_HEIGHT_EST.value + 8 > vh) {
        menuTop = Math.max(8, vh - MSG_MENU_HEIGHT_EST.value - 8);
    }

    let barTop = menuTop - MENU_REACTION_GAP - QUICK_REACTION_BAR_H;
    if (barTop < 8) {
        const shift = 8 - barTop;
        menuTop += shift;
        if (menuTop + MSG_MENU_HEIGHT_EST.value + 8 > vh) {
            menuTop = Math.max(8, vh - MSG_MENU_HEIGHT_EST.value - 8);
        }
        barTop = menuTop - MENU_REACTION_GAP - QUICK_REACTION_BAR_H;
    }

    menuX.value = menuLeft;
    menuY.value = menuTop;

    const barLeft = Math.min(
        Math.max(8, menuLeft + MSG_MENU_WIDTH / 2 - QUICK_REACTION_BAR_W.value / 2),
        vw - QUICK_REACTION_BAR_W.value - 8,
    );
    pickerX.value = barLeft;
    pickerY.value = Math.max(8, barTop);

    menuOpen.value = true;
    pickerOpen.value = true;
    fullPickerOpen.value = false;
    nextTick(() => {});
}

function openMenuFromButton(e: MouseEvent) {
    e.preventDefault();
    e.stopPropagation();
    const target = e.currentTarget as HTMLElement | null;
    const rect = target?.getBoundingClientRect();
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    let x = rect ? rect.right - MSG_MENU_WIDTH : e.clientX;
    let y = rect ? rect.bottom + 6 : e.clientY;
    if (x + MSG_MENU_WIDTH + 8 > vw) x = vw - MSG_MENU_WIDTH - 8;
    if (x < 8) x = 8;
    if (y + MSG_MENU_HEIGHT_EST.value + 8 > vh) y = Math.max(8, vh - MSG_MENU_HEIGHT_EST.value - 8);
    openMenuAt(x, y);
}

function onContextMenu(e: MouseEvent) {
    e.preventDefault();
    e.stopPropagation();
    openMenuAt(e.clientX, e.clientY);
}

function closeMenu() {
    menuOpen.value = false;
    pickerOpen.value = false;
    fullPickerOpen.value = false;
}

function mediaDownloadUrl(mediaId: number): string {
    const base = route('media.show', mediaId) as string;
    return base.includes('?') ? `${base}&download=1` : `${base}?download=1`;
}

function downloadFirstAttachment(): void {
    const first = mediaItems.value[0];
    if (!first) return;
    window.open(mediaDownloadUrl(first.id), '_blank', 'noopener,noreferrer');
}

function onEscape(e: KeyboardEvent) {
    if (e.key !== 'Escape') {
        return;
    }
    if (imageLightboxMediaId.value != null) {
        closeImageLightbox();
        return;
    }
    closeMenu();
}
window.addEventListener('keydown', onEscape);
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onEscape);
    mediaItems.value.forEach((m) => {
        detachVoiceProgress(m.id);
        delete voiceProgressRatio[m.id];
    });
});
</script>

<template>
    <!-- Системные сообщения (лог назначений / отделов): не пузырь, а центрированная пилюля как в WhatsApp -->
    <div
        v-if="isSystemMessage"
        class="mb-3 flex w-full justify-center px-2 sm:px-3"
        :data-message-id="message.id"
    >
        <div class="flex max-w-full items-center justify-center gap-2">
            <button
                v-if="selectionMode"
                type="button"
                class="shrink-0 z-20 flex h-7 w-7 items-center justify-center rounded-full border"
                :style="{
                    background: selected ? 'var(--wa-accent)' : 'var(--wa-panel)',
                    borderColor: selected ? 'transparent' : 'var(--wa-border-strong)',
                    color: selected ? 'white' : 'var(--wa-text-secondary)',
                }"
                :title="selected ? 'Снять выбор' : 'Выбрать'"
                @click.stop="emit('toggle-select', message.id)"
            >
                <svg v-if="selected" class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M9 16.2l-3.5-3.5L4 14.2l5 5 12-12-1.4-1.4z" />
                </svg>
                <svg v-else class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path
                        d="M12 2a10 10 0 1010 10A10.012 10.012 0 0012 2zm0 18a8 8 0 118-8 8.009 8.009 0 01-8 8z"
                    />
                </svg>
            </button>
            <div
                class="wa-system-chat-msg min-w-0 max-w-[min(86%,28rem)] rounded-[8px] px-3 py-1 text-center text-[11.5px] leading-snug shadow-none"
                :class="selected ? 'ring-2 ring-[var(--wa-accent)] ring-offset-1 ring-offset-[var(--wa-bg)]' : ''"
                role="status"
                :style="{
                    background: 'var(--wa-system-chat-pill-bg)',
                    color: 'var(--wa-system-chat-pill-text)',
                }"
                @click="onBubbleClick"
                @contextmenu="onContextMenu"
            >
                <p class="m-0 whitespace-pre-wrap break-words text-center" style="word-break: break-word">
                    {{ message.body }}
                </p>
            </div>
        </div>
    </div>

    <div v-else class="group mb-2 flex" :class="isOutbound ? 'justify-end' : 'justify-start'">
        <div
            class="wa-msg-bubble relative w-fit min-w-0 text-[14.2px] leading-[19px]"
            :data-message-id="message.id"
            :class="[
                wideImageBubble ? 'max-w-[min(94%,36rem)]' : 'max-w-[72%]',
                fullBleedVisualBubble ? 'px-0 py-0' : 'px-2 py-1',
                isOutbound ? 'wa-msg-bubble-out' : 'wa-msg-bubble-in',
                selected ? 'wa-msg-selected' : '',
            ]"
            :style="{
                background: isOutbound ? 'var(--wa-bubble-out)' : 'var(--wa-bubble-in)',
                color: 'var(--wa-bubble-text)',
            }"
            @mouseenter="hovered = true"
            @mouseleave="hovered = false"
            @click="onBubbleClick"
            @contextmenu="onContextMenu"
        >
            <button
                v-if="selectionMode"
                type="button"
                class="absolute top-2 z-20 h-7 w-7 rounded-full flex items-center justify-center border"
                :class="isOutbound ? '-left-10' : '-right-10'"
                :style="{
                    background: selected ? 'var(--wa-accent)' : 'var(--wa-panel)',
                    borderColor: selected ? 'transparent' : 'var(--wa-border-strong)',
                    color: selected ? 'white' : 'var(--wa-text-secondary)',
                }"
                :title="selected ? 'Снять выбор' : 'Выбрать'"
                @click.stop="emit('toggle-select', message.id)"
            >
                <svg v-if="selected" class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M9 16.2l-3.5-3.5L4 14.2l5 5 12-12-1.4-1.4z" />
                </svg>
                <svg v-else class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path
                        d="M12 2a10 10 0 1010 10A10.012 10.012 0 0012 2zm0 18a8 8 0 118-8 8.009 8.009 0 01-8 8z"
                    />
                </svg>
            </button>

            <button
                v-if="!selectionMode"
                type="button"
                class="wa-msg-emoji-trigger absolute top-1 z-[60] flex h-7 w-7 items-center justify-center rounded-full text-base shadow-lg transition hover:scale-105"
                :class="isOutbound ? '-left-9' : '-right-9'"
                :style="{ background: 'var(--wa-panel-header)', color: 'var(--wa-icon)' }"
                title="Добавить/изменить реакцию"
                data-emoji-trigger
                @click.stop.prevent
                @pointerdown.stop.prevent="togglePickerFromTrigger"
            >
                <span class="select-none leading-none" aria-hidden="true">☺</span>
            </button>

            <!-- Hover menu button (WhatsApp-like chevron) -->
            <button
                v-show="hovered"
                type="button"
                class="msg-hover-menu-btn"
                :class="isOutbound ? 'msg-hover-menu-btn-out' : 'msg-hover-menu-btn-in'"
                title="Меню"
                @click="openMenuFromButton"
            >
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M12 15.5a1 1 0 01-.71-.29l-5-5a1 1 0 011.42-1.42L12 13.09l4.29-4.3a1 1 0 011.42 1.42l-5 5a1 1 0 01-.71.29z" />
                </svg>
            </button>

            <div
                v-if="message.is_forwarded"
                class="mb-1 inline-flex items-center gap-1 text-[11px] font-medium opacity-80"
                :style="{ color: 'var(--wa-text-secondary)' }"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                    <path d="M14 7l5 5-5 5v-3H5v-4h9V7z" />
                </svg>
                <span>Переслано</span>
            </div>

            <div v-if="contactCard" class="wa-contact-card mb-1" :class="fullBleedVisualBubble ? '' : 'pr-14'">
                <span
                    v-if="showGroupSender && groupSenderLabel"
                    class="mb-1 block text-[12px] font-medium"
                    :style="{ color: 'var(--wa-accent)' }"
                >
                    {{ groupSenderLabel }}
                </span>
                <span v-if="isAiGenerated" class="ai-message-badge" title="Ответ подготовлен AI">
                    {{ operatorDisplayName }} (AI)
                </span>
                <div class="wa-contact-card-main">
                    <div class="wa-contact-avatar">
                        <img
                            v-if="contactCard.avatarUrl"
                            :src="contactCard.avatarUrl"
                            alt=""
                        />
                        <svg v-else class="w-7 h-7" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v1h16v-1c0-2.66-5.33-4-8-4z" />
                        </svg>
                    </div>
                    <div class="wa-contact-info">
                        <div class="wa-contact-name">{{ contactCard.name }}</div>
                        <div v-if="contactCard.phone" class="wa-contact-phone">{{ formatPhone(contactCard.phone) || contactCard.phone }}</div>
                    </div>
                </div>
                <button
                    type="button"
                    class="wa-contact-action"
                    @click.stop="openChatForContactCard(contactCard)"
                >
                    Сообщение
                </button>
            </div>

            <!-- Quoted / reply preview -->
            <button
                v-if="hasQuoted"
                type="button"
                class="mb-1 w-full text-left rounded-md px-2 py-1 border-l-4"
                :style="{ background: 'rgba(0,0,0,0.06)', borderColor: 'var(--wa-accent)' }"
                @click.stop="jumpToQuoted"
                title="Перейти к сообщению"
            >
                <div class="text-[12px] font-medium truncate" :style="{ color: 'var(--wa-accent)' }">
                    {{ quotedAuthor }}
                </div>
                <div class="text-[12px] truncate" :style="{ color: 'var(--wa-text-secondary)' }">
                    {{ quotedPreview }}
                </div>
            </button>

            <p
                v-if="showMessageBody"
                class="wa-msg-text mb-0.5 whitespace-pre-wrap break-words"
                style="word-break: break-word;"
            >
                <span
                    v-if="showGroupSender && groupSenderLabel"
                    class="mb-0.5 block text-[12px] font-medium"
                    :style="{ color: 'var(--wa-accent)' }"
                >
                    {{ groupSenderLabel }}
                </span>
                <span v-if="isAiGenerated" class="ai-message-badge" title="Ответ подготовлен AI">
                    {{ operatorDisplayName }} (AI)
                </span>
                <template v-for="(seg, i) in bodySegments" :key="i">
                    <span v-if="seg.type === 'text'" v-html="renderSegmentHtml(seg.text)"></span>
                    <button
                        v-else
                        type="button"
                        class="wa-mention-link"
                        @click.stop="openChatForMention(seg)"
                        :title="`Открыть чат: ${seg.text}`"
                    >
                        {{ seg.text }}
                    </button>
                </template>
            </p>

            <!-- Кнопка перевода + блок с переводом -->
            <div v-if="showMessageBody && translateLang !== 'off'" class="translate-wrap">
                <button
                    type="button"
                    class="translate-btn"
                    :class="{ 'translate-btn-active': translationVisible }"
                    @click.stop="toggleTranslation"
                    :disabled="translationLoading"
                    :title="translationVisible ? 'Скрыть перевод' : `Перевести на ${translateCurrent().label}`"
                >
                    <svg class="translate-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/>
                    </svg>
                    <span v-if="!translationLoading">{{ translateCurrent().flag }} {{ translateCurrent().label }}</span>
                    <span v-else class="translate-spinner">
                        <svg class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" d="M21 12a9 9 0 11-9-9"/>
                        </svg>
                    </span>
                </button>

                <div v-if="translationVisible" class="translate-result">
                    <div v-if="translationLoading" class="translate-loading">Переводим…</div>
                    <div v-else-if="translationError" class="translate-error">Не удалось перевести</div>
                    <p v-else-if="translationText" class="translate-text whitespace-pre-wrap break-words" style="word-break: break-word">
                        {{ translationText }}
                    </p>
                </div>
            </div>

            <div v-if="linkPreviewUrl" class="mb-1 min-w-[14rem]">
                <LinkPreview :url="linkPreviewUrl" />
            </div>

            <div
                v-if="showVoiceFallback"
                class="wa-voice-shell mb-1 min-w-[220px] max-w-[min(100%,320px)]"
            >
                <div class="wa-voice-row">
                    <div v-if="isOutbound" class="wa-voice-avatar-wrap" :title="operatorDisplayName">
                        <div class="wa-voice-avatar wa-voice-avatar--op">{{ operatorInitial }}</div>
                        <span class="wa-voice-avatar-mic" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="currentColor" class="wa-voice-avatar-mic-svg">
                                <path
                                    d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z"
                                />
                            </svg>
                        </span>
                    </div>
                    <span class="wa-voice-play wa-voice-play--disabled" aria-hidden="true">
                        <svg class="wa-voice-play-icon" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M8 5v14l11-7z" />
                        </svg>
                    </span>
                    <div class="wa-voice-wave-column">
                        <div class="wa-voice-wave-area">
                            <div class="wa-voice-wave-track">
                                <div class="wa-voice-wave" aria-hidden="true">
                                    <span
                                        v-for="(h, i) in voiceWaveFallbackBars"
                                        :key="i"
                                        class="wa-voice-bar wa-voice-bar--muted"
                                        :style="{ height: h + 'px' }"
                                    />
                                </div>
                                <span class="wa-voice-playhead" aria-hidden="true" style="left: 4px; top: 50%; transform: translate(-50%, -50%)" />
                            </div>
                        </div>
                        <span v-if="voiceFallbackDurationLabel" class="wa-voice-time wa-voice-time-below">{{ voiceFallbackDurationLabel }}</span>
                        <span v-else class="wa-voice-time wa-voice-time-below wa-voice-time--dim">—</span>
                    </div>
                    <div v-if="isInbound" class="wa-voice-avatar-wrap" :title="(message.sender_name || '').trim() || ''">
                        <img
                            v-if="contactAvatarUrl"
                            :src="contactAvatarUrl"
                            alt=""
                            class="wa-voice-avatar wa-voice-avatar--img"
                        />
                        <div v-else class="wa-voice-avatar wa-voice-avatar--in">
                            {{ (message.sender_name || '?').charAt(0).toUpperCase() }}
                        </div>
                        <span class="wa-voice-avatar-mic wa-voice-avatar-mic--in" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="currentColor" class="wa-voice-avatar-mic-svg">
                                <path
                                    d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z"
                                />
                            </svg>
                        </span>
                    </div>
                </div>
                <p class="wa-voice-fallback-hint">Медиа не загрузилось — откройте в WhatsApp на телефоне.</p>
            </div>

            <div
                v-if="mediaItems.length"
                class="mb-1"
                :class="
                    fullBleedVisualBubble
                        ? [
                              'relative',
                              'space-y-0.5',
                              'overflow-hidden',
                              'rounded-lg',
                              isOutbound ? 'rounded-tr-none' : 'rounded-tl-none',
                          ]
                        : 'space-y-2'
                "
            >
                <template v-for="m in mediaItems" :key="m.id">
                    <button
                        v-if="isImageMime(m)"
                        type="button"
                        class="block w-full cursor-zoom-in border-0 bg-transparent p-0 text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--wa-accent)] focus-visible:ring-offset-1"
                        :class="fullBleedVisualBubble ? '' : 'overflow-hidden rounded-md'"
                        title="Открыть"
                        @click.stop="openImageLightbox(m.id)"
                    >
                        <img
                            :src="mediaSrc(m.id)"
                            :alt="m.filename || 'image'"
                            class="block w-full max-h-64 object-cover"
                            loading="lazy"
                            decoding="async"
                        />
                    </button>
                    <div
                        v-else-if="isVoiceLikeMedia(m)"
                        class="wa-voice-shell min-w-[220px] max-w-[min(100%,320px)]"
                    >
                        <audio
                            class="wa-voice-audio-engine"
                            :ref="(el) => bindVoiceAudio(m.id, el)"
                            :src="mediaSrc(m.id)"
                            preload="metadata"
                            playsinline
                            :style="hiddenAudioStyle"
                            tabindex="-1"
                            aria-hidden="true"
                            @ended="onVoiceEnded(m.id)"
                        />
                        <div class="wa-voice-row">
                            <div v-if="isOutbound" class="wa-voice-avatar-wrap" :title="operatorDisplayName">
                                <div v-if="voiceEngagedMediaId === m.id" class="wa-voice-speed-cluster">
                                    <button
                                        type="button"
                                        class="wa-voice-speed-btn wa-voice-speed-btn--active"
                                        title="Скорость: нажмите, чтобы переключить 1x → 1.5x → 2x"
                                        @click.stop="cycleVoicePlaybackRate(m.id)"
                                    >
                                        {{ voicePlaybackRateButtonLabel(m.id) }}
                                    </button>
                                </div>
                                <template v-else>
                                    <div class="wa-voice-avatar wa-voice-avatar--op">{{ operatorInitial }}</div>
                                    <span class="wa-voice-avatar-mic" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="currentColor" class="wa-voice-avatar-mic-svg">
                                            <path
                                                d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z"
                                            />
                                        </svg>
                                    </span>
                                </template>
                            </div>
                            <button
                                type="button"
                                class="wa-voice-play"
                                :title="playingVoiceMediaId === m.id ? 'Пауза' : 'Воспроизвести'"
                                @click.stop="toggleVoicePlay(m.id)"
                            >
                                <svg
                                    v-if="playingVoiceMediaId !== m.id"
                                    class="wa-voice-play-icon"
                                    viewBox="0 0 24 24"
                                    fill="currentColor"
                                >
                                    <path d="M8 5v14l11-7z" />
                                </svg>
                                <svg v-else class="wa-voice-play-icon" viewBox="0 0 24 24" fill="currentColor">
                                    <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z" />
                                </svg>
                            </button>
                            <div class="wa-voice-wave-column">
                                <div class="wa-voice-wave-area">
                                    <div class="wa-voice-wave-track" @pointerdown="(ev) => onVoiceWavePointerDown(ev, m.id)">
                                        <div
                                            class="wa-voice-wave"
                                            :class="{ 'wa-voice-wave--alive': playingVoiceMediaId === m.id }"
                                            aria-hidden="true"
                                        >
                                            <span
                                                v-for="(h, i) in voiceWaveBarsForMedia(m.id)"
                                                :key="i"
                                                class="wa-voice-bar"
                                                :class="voiceWaveBarClass(m.id, i)"
                                                :style="{ height: h + 'px' }"
                                            />
                                        </div>
                                        <span class="wa-voice-playhead" aria-hidden="true" :style="voicePlayheadPositionStyle(m.id)" />
                                    </div>
                                </div>
                                <span class="wa-voice-time wa-voice-time-below">{{ voiceElapsedLabel(m.id) }}</span>
                            </div>
                            <div v-if="isInbound" class="wa-voice-avatar-wrap" :title="(message.sender_name || '').trim() || ''">
                                <div v-if="voiceEngagedMediaId === m.id" class="wa-voice-speed-cluster">
                                    <button
                                        type="button"
                                        class="wa-voice-speed-btn wa-voice-speed-btn--active"
                                        title="Скорость: нажмите, чтобы переключить 1x → 1.5x → 2x"
                                        @click.stop="cycleVoicePlaybackRate(m.id)"
                                    >
                                        {{ voicePlaybackRateButtonLabel(m.id) }}
                                    </button>
                                </div>
                                <template v-else>
                                    <img
                                        v-if="contactAvatarUrl"
                                        :src="contactAvatarUrl"
                                        alt=""
                                        class="wa-voice-avatar wa-voice-avatar--img"
                                    />
                                    <div v-else class="wa-voice-avatar wa-voice-avatar--in">
                                        {{ (message.sender_name || '?').charAt(0).toUpperCase() }}
                                    </div>
                                    <span class="wa-voice-avatar-mic wa-voice-avatar-mic--in" aria-hidden="true">
                                        <svg viewBox="0 0 24 24" fill="currentColor" class="wa-voice-avatar-mic-svg">
                                            <path
                                                d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm5.3-3c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.41 2.72 6.23 6 6.72V21h2v-3.28c3.28-.48 6-3.3 6-6.72h-1.7z"
                                            />
                                        </svg>
                                    </span>
                                </template>
                            </div>
                        </div>
                    </div>
                    <div v-else-if="isGifLikeMedia(m)" class="relative">
                        <video
                            :ref="(el) => bindGifVideo(m.id, el)"
                            :src="mediaSrc(m.id)"
                            class="block w-full max-h-64 object-cover"
                            :class="fullBleedVisualBubble ? '' : 'rounded-md'"
                            muted
                            loop
                            playsinline
                            preload="metadata"
                            @mouseenter="playGifPreview(m.id)"
                            @mouseleave="stopGifPreview(m.id)"
                        />
                        <span
                            class="pointer-events-none absolute left-2 top-2 rounded px-1.5 py-0.5 text-[10px] font-semibold"
                            :style="{ background: 'rgba(0,0,0,0.5)', color: '#fff' }"
                        >
                            GIF
                        </span>
                    </div>
                    <video
                        v-else-if="isVideoMime(m)"
                        :src="mediaSrc(m.id)"
                        class="block w-full max-h-64 object-cover"
                        :class="fullBleedVisualBubble ? '' : 'rounded-md'"
                        controls
                        playsinline
                    />
                    <a
                        v-else
                        :href="mediaSrc(m.id)"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 break-all underline"
                        :style="{ color: 'var(--wa-accent)' }"
                    >
                        {{ m.filename || 'Файл' }}
                    </a>
                </template>
                <template v-if="fullBleedVisualBubble">
                    <div
                        class="pointer-events-none absolute inset-x-0 bottom-0 z-[1] h-14 bg-gradient-to-t from-black/55 to-transparent"
                        aria-hidden="true"
                    />
                    <div
                        class="absolute right-1.5 z-[2] flex items-center gap-1 text-[11px] font-medium text-white drop-shadow-[0_1px_2px_rgba(0,0,0,0.85)]"
                        :class="fullBleedHasVideo ? 'bottom-11' : 'bottom-1'"
                        @click.stop
                    >
                        <span class="tabular-nums opacity-95">{{ messageTime() }}</span>
                        <MessageStatus v-if="isOutbound" :status="message.status" :ack="message.ack" />
                    </div>
                </template>
            </div>

            <div
                v-if="!fullBleedVisualBubble"
                class="float-right -mb-1 -mt-1 ml-2 flex items-center gap-1 text-[11px] opacity-80"
            >
                <span>{{ messageTime() }}</span>
                <MessageStatus v-if="isOutbound" :status="message.status" :ack="message.ack" />
            </div>
            <div v-if="!fullBleedVisualBubble" class="clear-both"></div>

            <div :class="fullBleedVisualBubble ? 'px-2' : ''">
                <MessageReactions
                    :reactions="message.reactions || []"
                    :current-user-id="currentUserId"
                    @react="react"
                />
            </div>
        </div>
    </div>

    <!-- Подложка + меню: подложка на всё окно при меню / быстрых реакциях / полном пикере (клик снаружи = closeMenu, как у эмодзи) -->
    <teleport to="body">
        <div v-if="menuOpen || pickerOpen || fullPickerOpen">
            <div
                class="fixed inset-0 z-[1040]"
                @pointerdown="closeMenu"
                @click="closeMenu"
                @contextmenu.prevent="closeMenu"
            ></div>
            <div
                v-if="menuOpen"
                class="msg-menu-panel fixed z-[1050] rounded-[12px] py-1"
                :style="{
                    left: menuX + 'px',
                    top: menuY + 'px',
                    width: MSG_MENU_WIDTH + 'px',
                }"
            >
                <button class="msg-menu-item" type="button" @click="closeMenu(); replyToMessage()">
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a6 6 0 016 6v1M3 10l6 6M3 10l6-6" />
                    </svg>
                    Ответить
                </button>

                <button class="msg-menu-item" type="button" :disabled="!message.body" @click="closeMenu(); copyMessage()">
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
                    </svg>
                    Копировать
                </button>

                <button class="msg-menu-item" type="button" @click="closeMenu(); openFullPickerFromMenu()">
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Отреагировать
                </button>

                <button class="msg-menu-item" type="button" @click="closeMenu(); emit('forward', message)">
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 8l5 4-5 4M4 12h16" />
                    </svg>
                    Переслать
                </button>

                <template v-if="canShowAiFeedback">
                    <div class="msg-menu-divider"></div>
                    <div class="msg-menu-section-label">Оценить AI-ответ</div>
                    <button
                        v-for="opt in aiFeedbackOptions"
                        :key="opt.id"
                        class="msg-menu-item"
                        type="button"
                        :title="opt.title"
                        :disabled="aiFeedbackSubmitting"
                        @click="closeMenu(); submitAiFeedback(opt.id)"
                    >
                        <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 4.5l1.5 1.5 3.75-3.75M4.5 13.5l3 3 7.5-7.5M4.5 6.75h4.5M4.5 20.25h15" />
                        </svg>
                        {{ opt.label }}
                    </button>
                </template>

                <button
                    v-if="canDelete"
                    class="msg-menu-item msg-menu-item-danger"
                    type="button"
                    @click="closeMenu(); destroyMessage()"
                >
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V4a1 1 0 011-1h6a1 1 0 011 1v3" />
                    </svg>
                    Удалить
                </button>

                <div class="msg-menu-divider"></div>

                <button
                    v-if="hasMediaAttachments"
                    class="msg-menu-item"
                    type="button"
                    @click="closeMenu(); downloadFirstAttachment()"
                >
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                    </svg>
                    Скачать
                </button>

                <button
                    v-if="canShowMessageInfo"
                    class="msg-menu-item"
                    type="button"
                    @click="closeMenu(); showMessageInfo()"
                >
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Данные о сообщении
                </button>

                <button class="msg-menu-item" type="button" @click="closeMenu(); emit('toggle-select', message.id)">
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 11l3 3L22 4M2 12a10 10 0 1010-10" />
                    </svg>
                    Выбрать
                </button>

                <button
                    v-if="canReplyPrivately"
                    class="msg-menu-item"
                    type="button"
                    @click="closeMenu(); startPrivateChat()"
                >
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h8M8 14h5m7 7l-3.5-3.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    Ответить лично
                </button>

                <button
                    v-if="canReplyPrivately"
                    class="msg-menu-item"
                    type="button"
                    @click="closeMenu(); startPrivateChatLabeled()"
                >
                    <svg class="msg-menu-icon" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zM12 14c-4.418 0-8 2.239-8 5v1h16v-1c0-2.761-3.582-5-8-5z" />
                    </svg>
                    Написать контакту {{ formatPhone(senderPhoneDigits) || senderPhoneDigits }}
                </button>
            </div>
        </div>
    </teleport>

    <teleport to="body">
        <Transition name="wa-quick-reactions">
            <div
                v-if="pickerOpen"
                class="wa-quick-reactions fixed z-[1060]"
                :style="{ left: pickerX + 'px', top: pickerY + 'px' }"
                @pointerdown.stop
            >
                <button
                    v-for="(emoji, i) in quickReactionEmojis"
                    :key="emoji"
                    type="button"
                    class="wa-quick-reaction-btn"
                    :style="{ '--reaction-delay': `${i * 22}ms` }"
                    @click="reactFromQuickBar(emoji)"
                >
                    {{ emoji }}
                </button>
                <button
                    type="button"
                    class="wa-quick-reaction-btn wa-quick-reaction-btn--plus"
                    title="Больше эмодзи"
                    @click="openFullPickerFromQuickBar"
                >
                    +
                </button>
            </div>
        </Transition>

        <Transition name="wa-full-picker">
            <div
                v-if="fullPickerOpen"
                class="fixed z-[1060]"
                :style="{ left: pickerX + 'px', top: pickerY + 'px' }"
                @pointerdown.stop
            >
                <EmojiPicker @select="react" @close="closeMenu" />
            </div>
        </Transition>
    </teleport>

    <teleport to="body">
        <div
            v-if="imageLightboxMediaId != null"
            class="fixed inset-0 z-[2000] flex items-center justify-center bg-black/88 p-4"
            role="dialog"
            aria-modal="true"
            aria-label="Просмотр изображения"
            @click.self="closeImageLightbox"
        >
            <button
                type="button"
                class="absolute right-3 top-3 z-[1] flex h-10 w-10 items-center justify-center rounded-full text-white transition hover:bg-white/15"
                aria-label="Закрыть"
                @click="closeImageLightbox"
            >
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
            <img
                :src="imageLightboxUrl"
                alt=""
                class="max-h-[min(92vh,1200px)] max-w-full object-contain shadow-2xl"
                @click.stop
            />
        </div>
    </teleport>
</template>

<style scoped>
/* ── Translation ─────────────────────────────────────────────────────────── */
.translate-wrap {
    margin-top: 2px;
    margin-bottom: 2px;
}

.translate-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 7px;
    border-radius: 999px;
    border: 1px solid var(--wa-border-strong);
    background: transparent;
    color: var(--wa-text-secondary);
    font-size: 0.68rem;
    font-weight: 500;
    cursor: pointer;
    transition: color 0.12s, border-color 0.12s, background 0.12s;
    line-height: 1.5;
}
.translate-btn:hover:not(:disabled) {
    color: var(--wa-text);
    border-color: var(--wa-accent);
    background: color-mix(in srgb, var(--wa-accent) 10%, transparent);
}
.translate-btn.translate-btn-active {
    color: var(--wa-accent);
    border-color: var(--wa-accent);
    background: color-mix(in srgb, var(--wa-accent) 10%, transparent);
}
.translate-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.translate-icon {
    width: 11px;
    height: 11px;
    flex-shrink: 0;
}
.translate-spinner { display: flex; align-items: center; }

.translate-result {
    margin-top: 4px;
    padding: 6px 8px;
    border-radius: 6px;
    border-left: 3px solid var(--wa-accent);
    background: color-mix(in srgb, var(--wa-accent) 8%, var(--wa-bubble-in));
    font-size: 0.85rem;
    color: var(--wa-text);
}
.translate-loading, .translate-error {
    font-size: 0.78rem;
    color: var(--wa-text-secondary);
}
.translate-error { color: var(--wa-danger, #ef4444); }
.translate-text { margin: 0; font-size: 0.85rem; }

.ai-message-badge {
    color: var(--wa-accent);
    display: block;
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 2px;
}

.wa-msg-bubble {
    border-radius: 7.5px;
    box-shadow: 0 1px 0.5px var(--wa-bubble-tail-shadow);
    overflow: visible;
    width: fit-content;
    max-width: min(72%, 42rem);
}

.wa-msg-text {
    display: block;
    min-width: 0;
    max-width: 100%;
}

.wa-msg-text::after {
    content: "";
    display: inline-block;
    width: 3.7rem;
}

.wa-msg-bubble::before {
    content: "";
    position: absolute;
    top: 0;
    width: 9px;
    height: 13px;
    background: inherit;
    pointer-events: none;
}

.wa-msg-bubble-in {
    border-top-left-radius: 0;
}

.wa-msg-bubble-in::before {
    left: -8px;
    clip-path: polygon(100% 0, 0 0, 100% 100%);
}

.wa-msg-bubble-out {
    border-top-right-radius: 0;
}

.wa-msg-bubble-out::before {
    right: -8px;
    clip-path: polygon(0 0, 100% 0, 0 100%);
}

.msg-hover-menu-btn {
    position: absolute;
    top: 6px;
    right: 6px;
    width: 28px;
    height: 28px;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: var(--wa-text-secondary);
    background: transparent;
    opacity: 0;
    transform: translateY(-2px);
    transition: opacity 0.12s ease, transform 0.12s ease, background-color 0.12s ease, color 0.12s ease;
    z-index: 5;
}
.group:hover .msg-hover-menu-btn {
    opacity: 1;
    transform: translateY(0);
}
.msg-hover-menu-btn:hover {
    background: var(--wa-panel-hover);
    color: var(--wa-text);
}

.msg-menu-panel {
    background: var(--msg-menu-bg);
    border: 1px solid var(--msg-menu-border);
    box-shadow: var(--msg-menu-shadow);
}

.msg-menu-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    width: 100%;
    min-width: 0;
    padding: 0.4375rem 0.625rem;
    font-size: 0.8125rem;
    line-height: 1.3;
    color: var(--wa-text);
    text-align: left;
    transition: background-color 0.1s ease;
}
.msg-menu-item:not(:disabled):hover {
    background-color: var(--msg-menu-item-hover);
}
.msg-menu-item:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
.msg-menu-icon {
    width: 0.9375rem;
    height: 0.9375rem;
    color: var(--wa-text-secondary);
    flex-shrink: 0;
}
.msg-menu-divider {
    height: 1px;
    margin: 0.1875rem 0.375rem;
    background: var(--msg-menu-divider);
}
.msg-menu-section-label {
    padding: 0.25rem 0.625rem 0.1875rem;
    color: var(--wa-text-secondary);
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    line-height: 1.2;
    text-transform: uppercase;
}
.msg-menu-item-danger {
    color: var(--wa-danger);
}
.msg-menu-item-danger .msg-menu-icon {
    color: var(--wa-danger);
}
.msg-menu-item-danger:hover {
    background-color: color-mix(in srgb, var(--wa-danger) 10%, transparent);
}

.wa-msg-selected {
    box-shadow: 0 0 0 2px rgba(37, 211, 102, 0.25);
}

.wa-contact-card {
    width: 100%;
    min-width: min(280px, 100%);
    overflow: hidden;
}
.wa-contact-card-main {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 8px 12px;
}
.wa-contact-avatar {
    width: 48px;
    height: 48px;
    border-radius: 9999px;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    /* Контраст относительно фона пузыря: в light исходящий — светло-зелёный, не тёмный */
    color: color-mix(in srgb, var(--wa-bubble-text) 78%, var(--wa-accent) 22%);
    background: color-mix(in srgb, var(--wa-bubble-text) 14%, transparent);
}
.wa-contact-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.wa-contact-info {
    min-width: 0;
    flex: 1;
}
.wa-contact-name {
    font-size: 14px;
    line-height: 18px;
    font-weight: 700;
    color: var(--wa-bubble-text);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.wa-contact-phone {
    margin-top: 2px;
    font-size: 12px;
    color: color-mix(in srgb, var(--wa-bubble-text) 72%, transparent);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.wa-contact-action {
    display: block;
    width: 100%;
    margin: 0;
    padding: 10px 12px;
    border-top: 1px solid color-mix(in srgb, var(--wa-bubble-text) 14%, transparent);
    color: var(--wa-accent);
    font-size: 13px;
    font-weight: 700;
    text-align: center;
    transition: background-color 0.12s ease;
}
.wa-contact-action:hover {
    background: color-mix(in srgb, var(--wa-bubble-text) 8%, transparent);
}

.wa-quick-reactions {
    display: flex;
    align-items: center;
    gap: 2px;
    height: 32px;
    padding: 2px 6px;
    border: 1px solid var(--msg-reaction-bar-border);
    border-radius: 9999px;
    background: var(--msg-reaction-bar-bg);
    box-shadow: var(--msg-reaction-bar-shadow);
    transform-origin: 50% 100%;
    will-change: transform, opacity;
}

.wa-quick-reaction-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    border: 0;
    border-radius: 9999px;
    background: transparent;
    color: var(--msg-reaction-emoji-color);
    font-size: 16px;
    line-height: 1;
    opacity: 0;
    animation: wa-reaction-pop 180ms cubic-bezier(0.2, 0.85, 0.2, 1.2) forwards;
    animation-delay: var(--reaction-delay, 0ms);
    transition: background-color 0.12s ease, transform 0.12s ease;
    will-change: transform, opacity;
}

.wa-quick-reaction-btn:hover {
    background: var(--msg-reaction-btn-hover);
    transform: translateY(-1px) scale(1.06);
}

.wa-quick-reaction-btn--plus {
    font-size: 17px;
    font-weight: 300;
    animation-delay: 132ms;
}

.wa-quick-reactions-enter-active {
    transition: opacity 140ms ease-out, transform 180ms cubic-bezier(0.2, 0.85, 0.2, 1.08);
}

.wa-quick-reactions-leave-active {
    transition: opacity 90ms ease-in, transform 90ms ease-in;
}

.wa-quick-reactions-enter-from,
.wa-quick-reactions-leave-to {
    opacity: 0;
    transform: translateY(8px) scale(0.88);
}

.wa-quick-reactions-enter-to,
.wa-quick-reactions-leave-from {
    opacity: 1;
    transform: translateY(0) scale(1);
}

.wa-full-picker-enter-active {
    transition: opacity 140ms ease-out, transform 160ms ease-out;
}

.wa-full-picker-leave-active {
    transition: opacity 90ms ease-in, transform 90ms ease-in;
}

.wa-full-picker-enter-from,
.wa-full-picker-leave-to {
    opacity: 0;
    transform: translateY(6px) scale(0.98);
}

@keyframes wa-reaction-pop {
    from {
        opacity: 0;
        transform: translateY(5px) scale(0.82);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/*
 * Не использовать tailwind hidden + group-hover:flex: порядок утилит даёт display:none,
 * клик уходит сквозь кнопку на голос/скролл — «нажимаю на ☺ и ничего».
 */
.wa-msg-emoji-trigger {
    display: flex;
    opacity: 0;
    pointer-events: none;
}
@media (hover: hover) and (pointer: fine) {
    .group:hover .wa-msg-emoji-trigger {
        opacity: 1;
        pointer-events: auto;
    }
}
@media (hover: none), (pointer: coarse) {
    .wa-msg-emoji-trigger {
        opacity: 1;
        pointer-events: auto;
    }
}
</style>

