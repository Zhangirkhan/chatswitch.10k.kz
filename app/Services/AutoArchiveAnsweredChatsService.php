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
    public function archiveEligibleChats(?int $companyId = null): int
    {
        $archived = 0;

        $this->eligibleQuery($companyId)
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
            Log::info('AutoArchiveAnsweredChats: archived chats', [
                'count' => $archived,
                'company_id' => $companyId,
            ]);
        }

        return $archived;
    }

    public function countEligible(?int $companyId = null): int
    {
        return $this->eligibleQuery($companyId)->count();
    }

    /**
     * @return Builder<Chat>
     */
    private function eligibleQuery(?int $companyId): Builder
    {
        $query = Chat::query()
            ->where('is_archived', false)
            ->where('is_pinned', false)
            ->where('last_message_direction', 'outbound')
            ->whereHas(
                'latestMessage',
                static fn (Builder $q) => $q
                    ->where('direction', 'outbound')
                    ->whereNotNull('sent_by_user_id'),
            );

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        return $query;
    }
}
