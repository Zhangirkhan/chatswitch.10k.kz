export type ClientProfileField = {
    code?: string | null;
    definition_id?: number;
    label: string;
    value: string;
    raw_value?: string | Record<string, unknown> | null;
    preview_url?: string | null;
    value_json?: Record<string, unknown> | null;
    source: 'crm' | 'memory' | 'chat' | 'ai' | 'custom';
    type?: string;
    editable?: boolean;
    options?: { choices?: string[] } | null;
};

export type ClientProfileActivityItem = {
    type: 'message' | 'fact' | 'event';
    direction?: string;
    body: string;
    label?: string;
    at: string | null;
    assignee?: string | null;
    source?: string;
};

export type ClientProfileSection = {
    key: string;
    title: string;
    semantic: 'who' | 'context' | 'agreements';
    fields?: ClientProfileField[];
    status?: 'unavailable';
    message?: string;
    activity?: ClientProfileActivityItem[];
    memory_excerpt?: string | null;
    memory_url?: string;
};

export type ClientProfile = {
    contact_id: number;
    display_name: string;
    sections: ClientProfileSection[];
    memory: {
        content: string;
        updated_at: string | null;
        memory_contact_id: number | null;
    };
    ai_enriched?: boolean;
    field_definitions?: Array<{
        id: number;
        code: string;
        label: string;
        type: string;
        section: string;
        is_visible: boolean;
        options?: { choices?: string[] } | null;
    }>;
};

export type ClientListItem = {
    id: number;
    whatsapp_id: string | null;
    phone_number: string | null;
    phone_display: string | null;
    lead_id: string | null;
    name: string | null;
    push_name: string | null;
    profile_picture_url: string | null;
    chats_count: number;
    last_chat_name: string | null;
    last_chat_at: string | null;
    primary_chat_id: number | null;
    unread_count: number;
    stage: { name: string; color: string | null } | null;
    channels: Array<{
        chat_id: number;
        session_id: number | null;
        session_label: string;
        session_phone: string | null;
        chat_name: string | null;
        last_message_at: string | null;
    }>;
    companies: Array<{ id: number; name: string; position: string | null }>;
};
