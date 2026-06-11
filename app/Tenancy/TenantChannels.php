<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Chat;
use App\Models\TeamConversation;

final class TenantChannels
{
    public static function companyIdForChat(int $chatId): int
    {
        return (int) Chat::withoutGlobalScope('tenant')->whereKey($chatId)->value('company_id');
    }

    public static function companyIdForConversation(int $conversationId): int
    {
        return (int) TeamConversation::withoutGlobalScope('tenant')->whereKey($conversationId)->value('company_id');
    }

    public const CHAT = 't.{companyId}.chat.{chatId}';

    public const CHATS_LIST = 't.{companyId}.chats.list.{userId}';

    public const TEAM_CONVERSATION = 't.{companyId}.team-conversation.{conversationId}';

    public const TEAM_INBOX = 't.{companyId}.team-inbox.{userId}';

    public const WHATSAPP_STATUS = 't.{companyId}.whatsapp-status';

    public const FUNNEL_BOARD = 't.{companyId}.funnel-board.{funnelId}';

    public const FUNNEL_BOARD_PRESENCE = 't.{companyId}.funnel-board-presence.{funnelId}';

    public const PLATFORM_BANNERS = 't.{companyId}.platform-banners';

    public static function platformBanners(int $companyId): string
    {
        return "t.{$companyId}.platform-banners";
    }

    public static function chat(int $companyId, int $chatId): string
    {
        return "t.{$companyId}.chat.{$chatId}";
    }

    public static function chatsList(int $companyId, int $userId): string
    {
        return "t.{$companyId}.chats.list.{$userId}";
    }

    public static function teamConversation(int $companyId, int $conversationId): string
    {
        return "t.{$companyId}.team-conversation.{$conversationId}";
    }

    public static function teamInbox(int $companyId, int $userId): string
    {
        return "t.{$companyId}.team-inbox.{$userId}";
    }

    public static function whatsappStatus(int $companyId): string
    {
        return "t.{$companyId}.whatsapp-status";
    }

    public static function funnelBoard(int $companyId, int $funnelId): string
    {
        return "t.{$companyId}.funnel-board.{$funnelId}";
    }

    public static function funnelBoardPresence(int $companyId, int $funnelId): string
    {
        return "t.{$companyId}.funnel-board-presence.{$funnelId}";
    }
}
