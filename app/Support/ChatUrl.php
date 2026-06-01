<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Chat;

final class ChatUrl
{
    public static function show(Chat|int $chat): string
    {
        $model = $chat instanceof Chat
            ? $chat
            : Chat::query()->withoutGlobalScope('tenant')->whereKey($chat)->first();

        if ($model === null) {
            return '';
        }

        $model->loadMissing('tenantCompany');
        $slug = $model->tenantCompany?->slug;
        $params = $slug !== null && $slug !== ''
            ? ['tenant' => $slug, 'chat' => $model]
            : ['chat' => $model];

        return route('chats.show', $params);
    }
}
