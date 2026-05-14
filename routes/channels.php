<?php

declare(strict_types=1);

use App\Models\Chat;
use App\Models\TeamConversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.{chatId}', function (User $user, int $chatId): bool {
    $chat = Chat::find($chatId);
    if (! $chat) {
        return false;
    }

    return $user->can('view', $chat);
});

Broadcast::channel('chats.list.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});

Broadcast::channel('team-conversation.{conversationId}', function (User $user, int $conversationId): bool {
    $conversation = TeamConversation::query()->find($conversationId);
    if ($conversation === null) {
        return false;
    }

    return $conversation->participants()->where('users.id', $user->id)->exists();
});

Broadcast::channel('team-inbox.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});

Broadcast::channel('whatsapp-status', function (User $user): bool {
    return $user->hasAnyRole(['administrator', 'manager']);
});
