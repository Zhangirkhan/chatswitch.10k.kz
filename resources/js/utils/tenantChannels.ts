export function tenantChannel(
    tenantCompanyId: number,
    suffix: string,
): string {
    return `t.${tenantCompanyId}.${suffix}`;
}

export function chatChannel(tenantCompanyId: number, chatId: number): string {
    return `t.${tenantCompanyId}.chat.${chatId}`;
}

export function chatsListChannel(tenantCompanyId: number, userId: number): string {
    return `t.${tenantCompanyId}.chats.list.${userId}`;
}

export function teamConversationChannel(tenantCompanyId: number, conversationId: number): string {
    return `t.${tenantCompanyId}.team-conversation.${conversationId}`;
}

export function teamInboxChannel(tenantCompanyId: number, userId: number): string {
    return `t.${tenantCompanyId}.team-inbox.${userId}`;
}

export function whatsappStatusChannel(tenantCompanyId: number): string {
    return `t.${tenantCompanyId}.whatsapp-status`;
}

export function funnelBoardChannel(tenantCompanyId: number, funnelId: number): string {
    return `t.${tenantCompanyId}.funnel-board.${funnelId}`;
}

export function funnelBoardPresenceChannel(tenantCompanyId: number, funnelId: number): string {
    return `t.${tenantCompanyId}.funnel-board-presence.${funnelId}`;
}
