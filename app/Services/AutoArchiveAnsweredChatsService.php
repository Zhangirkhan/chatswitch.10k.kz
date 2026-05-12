<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Chat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Ночной сброс «ожидающих» диалогов: в архив уходят чаты, где последнее
 * сообщение — ответ сотрудника (исходящее с привязкой к пользователю).
 */
final class AutoArchiveAnsweredChatsService
{
    public function archiveEligibleChats(): int
    {
        $archived = 0;

        Chat::query()
            ->where('is_archived', false)
            ->where('is_pinned', false)
            ->where('last_message_direction', 'outbound')
            ->whereHas(
                'latestMessage',
                static fn (Builder $q) => $q
                    ->where('direction', 'outbound')
                    ->whereNotNull('sent_by_user_id'),
            )
            ->select('id')
            ->chunkById(500, function ($chats) use (&$archived): void {
                $ids = $chats->pluck('id')->all();
                if ($ids === []) {
                    return;
                }

                $n = Chat::query()
                    ->whereIn('id', $ids)
                    ->update(['is_archived' => true]);

                $archived += $n;
            });

        if ($archived > 0) {
            Log::info('AutoArchiveAnsweredChats: archived chats', ['count' => $archived]);
        }

        return $archived;
    }

    /**
     * @return int Количество затронутых строк (для тестов / dry-run)
     */
    public function countEligible(): int
    {
        return Chat::query()
            ->where('is_archived', false)
            ->where('is_pinned', false)
            ->where('last_message_direction', 'outbound')
            ->whereHas(
                'latestMessage',
                static fn (Builder $q) => $q
                    ->where('direction', 'outbound')
                    ->whereNotNull('sent_by_user_id'),
            )
            ->count();
    }
}
