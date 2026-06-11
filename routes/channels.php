<?php

declare(strict_types=1);

use App\Models\Chat;
use App\Models\Funnel;
use App\Models\SystemSetting;
use App\Models\TeamConversation;
use App\Models\User;
use App\Tenancy\TenantChannels;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel(TenantChannels::CHAT, function (User $user, int $companyId, int $chatId): bool {
    if ((int) $user->company_id !== $companyId) {
        return false;
    }

    $chat = Chat::withoutGlobalScope('tenant')->find($chatId);
    if (! $chat || (int) $chat->company_id !== $companyId) {
        return false;
    }

    return $user->can('view', $chat);
});

Broadcast::channel(TenantChannels::CHATS_LIST, function (User $user, int $companyId, int $userId): bool {
    return $user->id === $userId && (int) $user->company_id === $companyId;
});

Broadcast::channel(TenantChannels::TEAM_CONVERSATION, function (User $user, int $companyId, int $conversationId): bool {
    if ((int) $user->company_id !== $companyId) {
        return false;
    }

    $conversation = TeamConversation::withoutGlobalScope('tenant')->find($conversationId);
    if ($conversation === null || (int) $conversation->company_id !== $companyId) {
        return false;
    }

    return $conversation->participants()->where('users.id', $user->id)->exists();
});

Broadcast::channel(TenantChannels::TEAM_INBOX, function (User $user, int $companyId, int $userId): bool {
    return $user->id === $userId && (int) $user->company_id === $companyId;
});

Broadcast::channel(TenantChannels::WHATSAPP_STATUS, function (User $user, int $companyId): bool {
    return $user->hasAnyRole(['administrator', 'manager'])
        && (int) $user->company_id === $companyId;
});

Broadcast::channel(TenantChannels::FUNNEL_BOARD, function (User $user, int $companyId, int $funnelId): bool {
    if (SystemSetting::getValue('module_funnels', 'on') !== 'on') {
        return false;
    }

    $funnel = Funnel::withoutGlobalScope('tenant')->find($funnelId);

    return $funnel !== null
        && (int) $funnel->company_id === $companyId
        && (int) $user->company_id === $companyId;
});

Broadcast::channel(TenantChannels::FUNNEL_BOARD_PRESENCE, function (User $user, int $companyId, int $funnelId): array|false {
    if (SystemSetting::getValue('module_funnels', 'on') !== 'on') {
        return false;
    }

    $funnel = Funnel::withoutGlobalScope('tenant')->find($funnelId);
    if ($funnel === null
        || (int) $funnel->company_id !== $companyId
        || (int) $user->company_id !== $companyId) {
        return false;
    }

    return [
        'id' => (int) $user->id,
        'name' => (string) $user->name,
    ];
});

Broadcast::channel(TenantChannels::PLATFORM_BANNERS, function (User $user, int $companyId): bool {
    return (int) $user->company_id === $companyId;
});

Broadcast::channel('platform.admin-banners', function (User $user): bool {
    return $user->is_super_admin;
});
