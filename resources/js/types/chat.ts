// Shared TypeScript types for chat-related entities.
// Use these across Pages/Chats components and composables to avoid
// duplicated/ad-hoc inline shapes that currently live in monster SFCs.

export interface WhatsappSession {
    id: number;
    session_name: string;
    display_name: string | null;
    display_color?: string | null;
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
    display_name?: string | null;
    avatar_url?: string | null;
    /** Laravel `Contact::$casts` / API */
    profile_picture_url?: string | null;
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
    /** Может быть null, если сессию удалили (FK SET NULL), пока чат ещё в UI. */
    whatsapp_session_id: number | null;
    company_id?: number | null;
    contact_id: number | null;
    contact?: Contact | null;
    company?: { id: number; name: string } | null;
    whatsapp_session?: WhatsappSession;
    assignments?: ChatAssignment[];
    departments?: Department[];
    is_group: boolean;
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
    is_archived: boolean;
    is_muted: boolean;
    is_favorite: boolean;
    muted_until: string | null;
    unread_count: number;
    last_message_text: string | null;
    last_message_at: string | null;
    last_message_direction: 'inbound' | 'outbound' | null;
    /** Последнее сообщение — автоответ AI (для вкладки «Клиенты» / «Сотрудники»). */
    last_message_is_ai?: boolean;
    latest_message?: ChatLastMessagePreview | null;
    community_id: number | null;
    ai_enabled?: boolean;
    ai_mode?: 'auto' | 'draft';
    ai_paused_at?: string | null;
    conflict_state?: string;
    conflict_situation?: string | null;
    ai_responder_user_id?: number | null;
    ai_responder?: { id: number; name: string } | null;
    can_manage_ai?: boolean;
    created_at?: string;
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

export interface FunnelCatalogEntry {
    id: number;
    name: string;
    description: string | null;
    color: string;
    stages: Array<{ id: number; name: string; color: string; stage_type?: string; position: number }>;
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

export interface MessageTranscript {
    kind: string;
    status: 'pending' | 'processing' | 'completed' | 'failed' | 'skipped';
    text?: string | null;
    error_message?: string | null;
    started_at?: string | null;
    completed_at?: string | null;
}

export interface MessageMedia {
    id: number;
    mime_type: string;
    filename: string | null;
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
    /** App-level delivery status (UI-friendly). */
    status?: 'sent' | 'delivered' | 'read';
    ack: 'pending' | 'sent' | 'delivered' | 'read' | 'failed';
    message_timestamp: string | null;
    created_at: string | null;
    media: MessageMedia[];
    transcript?: MessageTranscript | null;
    reactions?: MessageReaction[];
    metadata?: Record<string, unknown> | null;
    sent_by_user?: { id: number; name: string } | null;
    whatsapp_session?: WhatsappSession;
    quoted_message_id?: string | null;
    quoted_message?: QuotedMessagePreview | null;
    ai_decision?: MessageAiDecision | null;
}

export type MediaKind = 'image' | 'video' | 'audio' | 'voice' | 'sticker' | 'gif' | 'document';
