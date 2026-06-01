export type ClientSummarySection = {
    title: string;
    body: string;
};

export type ClientSummary = {
    contact_id: number;
    identity: {
        display_name: string;
        phone: string | null;
        avatar: string | null;
        companies: string[];
    };
    crm: {
        deal: {
            chat_id?: number;
            funnel?: { name?: string };
            stage?: { name?: string };
        } | null;
        upcoming_events_count: number;
        open_tasks_count: number;
    };
    memory_updated_at: string | null;
    ai: {
        headline: string;
        sections: ClientSummarySection[];
        confidence: 'high' | 'medium' | 'low';
    };
    primary_chat_id: number | null;
    candidate_contact_ids?: number[];
};

export type WorkspaceContact = {
    id: number;
    name: string;
    phone_number: string | null;
    companies: string[];
    chat_id: number | null;
    last_message_at: string | null;
    unread_count?: number;
};

export type WorkspaceMedia = {
    id: number;
    filename: string | null;
    mime_type: string | null;
    url: string;
    chat_id: number | null;
    chat_name: string | null;
    contact_name: string | null;
    message_at: string | null;
};

export type WorkspaceMessage = {
    id: number;
    body: string;
    direction: string;
    chat_id: number | null;
    chat_name: string | null;
    contact_name: string | null;
    message_at: string | null;
};

export type WorkspaceFunnelDeal = {
    id: number;
    name: string;
    funnel_name: string;
    stage_name: string;
    unread_count?: number;
    assignees?: Array<{ id: number; name: string }>;
};

export type WorkspaceCalendarEvent = {
    id: number;
    title: string;
    starts_at: string;
    ends_at: string;
    all_day?: boolean;
    assignee?: { id: number; name: string } | null;
};

export type WorkspaceDepartmentPost = {
    id: number;
    title: string;
    status: string;
    due_at: string | null;
    department_name: string | null;
    assignees?: Array<{ id: number; name: string }>;
};

export type WorkspaceEmployee = {
    id: number;
    name: string;
    email: string | null;
};

export type ResultTabId =
    | 'contacts'
    | 'media'
    | 'messages'
    | 'calendar'
    | 'funnel'
    | 'tasks'
    | 'employees';

export type WorkspaceResults = {
    contacts: WorkspaceContact[];
    media: WorkspaceMedia[];
    messages: WorkspaceMessage[];
    funnel_deals: WorkspaceFunnelDeal[];
    calendar_events: WorkspaceCalendarEvent[];
    department_posts: WorkspaceDepartmentPost[];
    employees: WorkspaceEmployee[];
};

export type TabCounts = Record<ResultTabId, number>;
