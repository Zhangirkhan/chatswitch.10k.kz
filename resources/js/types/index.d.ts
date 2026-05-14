export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string | null;
    phone?: string | null;
    phones?: string[];
    department_id: number | null;
    company_id?: number | null;
    company?: { id: number; name: string } | null;
    department_ids?: number[];
    is_active: boolean;
    roles: string[];
    /** Кто может выбирать «AI отвечает от имени» в шапке чата (сервер: administrator | manager). */
    can_pick_ai_responder?: boolean;
    department: Department | null;
    departments?: Department[];
    whatsapp_sessions?: WhatsappSession[];
    whatsapp_session_ids?: number[];
}

export interface Department {
    id: number;
    name: string;
    description: string | null;
    parent_id?: number | null;
    is_active: boolean;
    users_count?: number;
}

export interface WhatsappSession {
    id: number;
    session_name: string;
    phone_number?: string | null;
    display_name: string | null;
    display_color?: string | null;
    wa_name: string | null;
    wa_platform?: string | null;
    status: 'disconnected' | 'connecting' | 'qr_pending' | 'connected';
    is_active: boolean;
    connected_at: string | null;
    disconnected_at: string | null;
}

export interface Contact {
    id: number;
    whatsapp_id: string;
    phone_number: string;
    name: string | null;
    push_name: string | null;
    display_name?: string | null;
    profile_picture_url: string | null;
    is_business: boolean;
}

export interface ChatLastMessagePreview {
    id: number;
    type: string;
    body: string | null;
    direction: 'inbound' | 'outbound' | 'system';
    metadata?: MessageMetadata | null;
    message_timestamp: string | null;
    media?: MessageMedia[];
}

export interface Chat {
    id: number;
    whatsapp_chat_id: string;
    whatsapp_session_id: number | null;
    contact_id: number | null;
    company_id?: number | null;
    chat_name: string | null;
    is_group: boolean;
    last_message_text: string | null;
    last_message_at: string | null;
    last_message_direction?: 'inbound' | 'outbound' | null;
    latest_message?: ChatLastMessagePreview | null;
    unread_count: number;
    is_archived: boolean;
    is_pinned: boolean;
    pinned_message_id?: number | null;
    pinned_message?: {
        id: number;
        direction: 'inbound' | 'outbound' | 'system';
        type: string;
        body: string | null;
        sender_name: string | null;
        sender_phone: string | null;
        sent_by_user?: { id: number; name: string } | null;
        message_timestamp: string | null;
    } | null;
    is_muted: boolean;
    muted_until: string | null;
    is_favorite: boolean;
    ai_enabled?: boolean;
    ai_mode?: 'auto' | 'draft';
    ai_responder_user_id?: number | null;
    ai_responder?: { id: number; name: string } | null;
    can_manage_ai?: boolean;
    contact: Contact | null;
    whatsapp_session: WhatsappSession | null;
    assignments: ChatAssignment[];
    departments?: Department[];
    community_id?: number | null;
}

export interface ContactPayload {
    id?: number | null;
    name: string;
    phone: string;
    email?: string | null;
    company?: string | null;
    avatar_url?: string | null;
    vcard?: string | null;
}

export interface PollPayload {
    question: string;
    options: string[];
    allow_multiple_answers?: boolean;
}

export interface MessageMetadata {
    contact?: ContactPayload;
    poll?: PollPayload;
    videoPosterUrl?: string | null;
    [key: string]: unknown;
}

export interface Message {
    id: number;
    chat_id: number;
    whatsapp_session_id: number | null;
    whatsapp_message_id: string | null;
    direction: 'inbound' | 'outbound' | 'system';
    type: string;
    body: string | null;
    metadata?: MessageMetadata | null;
    sender_phone: string | null;
    sender_name: string | null;
    sent_by_user_id: number | null;
    is_forwarded: boolean;
    /** App-level delivery status (UI-friendly). */
    status?: 'sent' | 'delivered' | 'read';
    quoted_message_id?: string | null;
    quoted_message?: QuotedMessagePreview | null;
    ack: 'pending' | 'sent' | 'delivered' | 'read' | 'failed';
    message_timestamp: string | null;
    created_at: string | null;
    media: MessageMedia[];
    sent_by_user: { id: number; name: string } | null;
    whatsapp_session: { id: number; phone_number: string | null; display_name: string | null } | null;
    reactions?: MessageReaction[];
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

export interface MessageReaction {
    id: number;
    message_id: number;
    user_id: number | null;
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

export interface ChatAssignment {
    id: number;
    chat_id: number;
    user_id: number;
    user: User;
}

export interface AssignableUser {
    id: number;
    name: string;
    email?: string | null;
    department_id?: number | null;
    /** Название отдела пользователя (для подписи у руководителя в списке назначения). */
    department_name?: string | null;
    roles: string[];
}

export interface ScheduledMessage {
    id: number;
    chat_id: number;
    body: string;
    display_body: string | null;
    scheduled_at: string | null;
    scheduled_at_label: string | null;
    status: 'pending' | 'sending' | 'sent' | 'cancelled' | 'failed';
    error: string | null;
    sent_message_id: number | null;
    user: { id: number; name: string } | null;
}

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        success: string | null;
        error: string | null;
    };
    /** Inertia: страница внутреннего чата организации (опционально). */
    selectedConversationId?: number | null;
    /** Суммарно непрочитанных сообщений внутреннего чата (HandleInertiaRequests). */
    teamChatUnreadCount?: number;
};
