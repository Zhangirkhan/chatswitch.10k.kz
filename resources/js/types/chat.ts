// Shared TypeScript types for chat-related entities.
// Use these across Pages/Chats components and composables to avoid
// duplicated/ad-hoc inline shapes that currently live in monster SFCs.

export interface WhatsappSession {
    id: number;
    session_name: string;
    display_name: string | null;
    phone_number: string | null;
    status: string;
    is_active?: boolean;
}

export interface Contact {
    id: number;
    whatsapp_id: string | null;
    phone_number: string | null;
    name: string | null;
    push_name: string | null;
    avatar_url?: string | null;
}

export interface ChatAssignmentUser {
    id: number;
    name: string;
    email?: string;
    department_id?: number | null;
    roles?: string[];
}

export interface ChatAssignment {
    id: number;
    chat_id: number;
    user_id: number;
    user: ChatAssignmentUser;
}

export interface Department {
    id: number;
    name: string;
    is_active?: boolean;
}

export interface ChatLastMessagePreview {
    id: number;
    type: string;
    body: string | null;
    direction: 'inbound' | 'outbound' | 'system';
    metadata?: Record<string, unknown> | null;
    message_timestamp: string | null;
    media?: MessageMedia[];
}

export interface Chat {
    id: number;
    chat_name: string | null;
    whatsapp_chat_id: string;
    whatsapp_session_id: number;
    contact_id: number | null;
    contact?: Contact | null;
    whatsapp_session?: WhatsappSession;
    assignments?: ChatAssignment[];
    departments?: Department[];
    is_group: boolean;
    is_pinned: boolean;
    is_archived: boolean;
    is_muted: boolean;
    is_favorite: boolean;
    muted_until: string | null;
    unread_count: number;
    last_message_text: string | null;
    last_message_at: string | null;
    last_message_direction: 'inbound' | 'outbound' | null;
    latest_message?: ChatLastMessagePreview | null;
    community_id: number | null;
    created_at?: string;
}

export interface MessageReaction {
    id: number;
    message_id?: number;
    user_id?: number | null;
    external_id?: string | null;
    external_name?: string | null;
    emoji: string;
    user?: { id: number; name: string } | null;
}

export interface MessageMedia {
    id: number;
    mime_type: string;
    filename: string | null;
}

export interface QuotedMessagePreview {
    id: number;
    direction: 'inbound' | 'outbound' | 'system';
    type: string;
    body: string | null;
    sender_name: string | null;
    sender_phone: string | null;
    sent_by_user?: { id: number; name: string } | null;
    media?: MessageMedia[];
}

export interface Message {
    id: number;
    chat_id: number;
    whatsapp_session_id: number | null;
    whatsapp_message_id: string | null;
    direction: 'inbound' | 'outbound' | 'system';
    type: string;
    body: string | null;
    sender_phone: string | null;
    sender_name: string | null;
    sent_by_user_id: number | null;
    is_forwarded: boolean;
    ack: 'pending' | 'sent' | 'delivered' | 'read' | 'failed';
    message_timestamp: string | null;
    created_at: string | null;
    media: MessageMedia[];
    reactions?: MessageReaction[];
    metadata?: Record<string, unknown> | null;
    sent_by_user?: { id: number; name: string } | null;
    whatsapp_session?: WhatsappSession;
    quoted_message_id?: string | null;
    quoted_message?: QuotedMessagePreview | null;
}

export type MediaKind = 'image' | 'video' | 'audio' | 'voice' | 'sticker' | 'gif' | 'document';
