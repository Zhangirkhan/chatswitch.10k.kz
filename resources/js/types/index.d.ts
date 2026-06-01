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

export interface DepartmentWorkDaySlot {
    enabled: boolean;
    from: string;
    to: string;
}

export interface Department {
    id: number;
    name: string;
    description: string | null;
    parent_id?: number | null;
    is_active: boolean;
    users_count?: number;
    work_schedule_enabled?: boolean;
    work_schedule_timezone?: string | null;
    work_schedule?: Record<string, DepartmentWorkDaySlot>;
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
    company?: { id: number; name: string } | null;
    chat_name: string | null;
    is_group: boolean;
    last_message_text: string | null;
    last_message_at: string | null;
    last_message_direction?: 'inbound' | 'outbound' | null;
    /** Последнее сообщение — автоответ AI (вкладки «Клиенты» / «Сотрудники»). */
    last_message_is_ai?: boolean;
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
    updated_at?: string | null;
    funnel?: { id: number; name: string; color: string } | null;
    funnel_stage?: { id: number; name: string; color: string; stage_type?: string; position: number } | null;
    funnel_tracking_enabled?: boolean;
    funnel_stage_locked?: boolean;
    funnel_progress_percent?: number;
    funnel_progress?: { percent: number; stage_index: number | null; stages_count: number };
    funnel_ai_last_reason?: string | null;
    ai_orchestrator_status?: 'pending' | 'running' | 'completed' | 'needs_manager' | 'skipped' | 'failed' | null;
    ai_orchestrator_last_run_id?: number | null;
    ai_orchestrator_last_action_at?: string | null;
    ai_orchestrator_last_summary?: string | null;
    attention_reason?: string | null;
    attention_severity?: 'critical' | 'danger' | 'warning' | 'normal' | null;
}

export interface FunnelCatalogEntry {
    id: number;
    name: string;
    description: string | null;
    color: string;
    stages: Array<{ id: number; name: string; color: string; stage_type?: string; position: number }>;
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

export interface MessageAiDecisionChip {
    label: string;
    type: string;
}

export interface MessageAiDecision {
    source: 'reply' | 'orchestrator';
    label: string;
    reason: string;
    chips: MessageAiDecisionChip[];
    confidence: number | null;
    plan?: Record<string, unknown> | null;
}

export interface MessageProductAttachment {
    id: number;
    name: string;
    sku?: string | null;
    description?: string | null;
    price?: string | null;
    price_formatted?: string | null;
    image_url?: string | null;
    attributes?: Record<string, unknown> | null;
}

export interface MessageMetadata {
    contact?: ContactPayload;
    poll?: PollPayload;
    product?: MessageProductAttachment;
    videoPosterUrl?: string | null;
    [key: string]: unknown;
}

export interface MessageTranscript {
    kind: string;
    status: 'pending' | 'processing' | 'completed' | 'failed' | 'skipped';
    text?: string | null;
    error_message?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
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
    transcript?: MessageTranscript | null;
    sent_by_user: { id: number; name: string } | null;
    whatsapp_session: { id: number; phone_number: string | null; display_name: string | null } | null;
    reactions?: MessageReaction[];
    ai_decision?: MessageAiDecision | null;
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
    modules?: {
        calendar?: boolean;
        analytics?: boolean;
        tasks?: boolean;
        /** Посты-задачи по отделам в «Организация» (вкладка «Задачи»). */
        org_tasks?: boolean;
        funnels?: boolean;
        products?: boolean;
        services?: boolean;
        knowledge?: boolean;
        ai_quality?: boolean;
    };
    recaptcha?: {
        enabled: boolean;
        siteKey: string | null;
        version: 'v2' | 'v3';
    };
};
