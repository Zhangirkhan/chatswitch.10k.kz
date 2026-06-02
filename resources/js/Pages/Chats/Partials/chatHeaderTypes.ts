import type { AssignableUser, Chat, Department, FunnelCatalogEntry } from '@/types';

export type AiRiskyEnableModalState = {
    message: string;
    warnings: string[];
    readinessScore: number | null;
    settingsUrl: string;
};

export type AiStatus = {
    id: number;
    mode: string;
    status: string;
    label: string;
    message: string;
    hint: string | null;
    knowledge_context: {
        rules: number;
        products: number;
        services: number;
    } | null;
    tone_source: {
        source: string;
        label: string;
        hint: string;
    } | null;
    draft_reply: string | null;
    technical_error: string | null;
    updated_at: string | null;
};

export type ChatHeaderProps = {
    chat: Chat;
    typingUsers: Map<number, string>;
    departments?: Department[];
    assignableUsers?: AssignableUser[];
    aiStatus?: AiStatus | null;
    funnelCatalog?: FunnelCatalogEntry[];
};

export type ChatHeaderEmit = {
    (e: 'toggle-search'): void;
    (e: 'show-contact-info'): void;
    (e: 'open-ai'): void;
};
