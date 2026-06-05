import type { Chat } from '@/types';
import { formatPhone, isPlausibleInboundSenderPhone, normalizePhone } from '@/utils/phone';

/**
 * WhatsApp @lid иногда присылает chatName в виде «+7 700 …» — это не реальный номер.
 */
export function isFakeLidPhoneLabel(chat: Pick<Chat, 'whatsapp_chat_id' | 'chat_name'>): boolean {
    const waId = String(chat.whatsapp_chat_id || '').toLowerCase();
    if (! waId.endsWith('@lid')) {
        return false;
    }

    const name = String(chat.chat_name || '').trim();
    if (name === '') {
        return false;
    }

    return isPlausibleInboundSenderPhone(normalizePhone(name));
}

export function chatDisplayTitle(
    chat: Pick<Chat, 'whatsapp_chat_id' | 'chat_name' | 'contact' | 'is_group'>,
    fallback = '',
): string {
    const chatName = String(chat.chat_name || '').trim();
    if (chatName !== '' && ! isFakeLidPhoneLabel(chat)) {
        return chatName;
    }

    const contactName = String(chat.contact?.name || '').trim();
    if (contactName !== '') {
        return contactName;
    }

    const pushName = String(chat.contact?.push_name || '').trim();
    if (pushName !== '') {
        return pushName.startsWith('~') ? pushName : `~ ${pushName}`;
    }

    const phone = formatPhone(chat.contact?.phone_number);
    if (phone !== '') {
        return phone;
    }

    if (chatName !== '' && isFakeLidPhoneLabel(chat)) {
        return 'Контакт WhatsApp';
    }

    return fallback;
}
