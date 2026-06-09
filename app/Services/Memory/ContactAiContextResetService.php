<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Support\TenantCompany;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * После «Очистить память» AI не должен автоматически отвечать на старые входящие,
 * которые остались в истории чата.
 */
final class ContactAiContextResetService
{
    public function markContactsReset(array $contactIds): void
    {
        $contactIds = array_values(array_unique(array_map('intval', $contactIds)));
        if ($contactIds === []) {
            return;
        }

        $resetAt = now()->toIso8601String();
        foreach ($contactIds as $contactId) {
            if ($contactId <= 0) {
                continue;
            }

            Cache::forever($this->cacheKey($contactId), $resetAt);
        }

        $chatIds = Chat::query()
            ->whereIn('contact_id', $contactIds)
            ->pluck('id')
            ->all();

        if ($chatIds === []) {
            return;
        }

        AiResponseLog::query()
            ->whereIn('chat_id', $chatIds)
            ->whereIn('status', ['pending', 'generating'])
            ->update([
                'status' => 'failed',
                'error' => 'AI context reset after memory clear.',
            ]);
    }

    public function resetAt(int $contactId): ?Carbon
    {
        if ($contactId <= 0) {
            return null;
        }

        $raw = Cache::get($this->cacheKey($contactId));
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return Carbon::parse($raw);
    }

    public function isMessageBeforeReset(int $contactId, Message $message): bool
    {
        $resetAt = $this->resetAt($contactId);
        if ($resetAt === null) {
            return false;
        }

        $messageAt = $message->message_timestamp;
        if ($messageAt === null) {
            return false;
        }

        $at = $messageAt instanceof Carbon ? $messageAt : Carbon::parse((string) $messageAt);

        return $at->lte($resetAt);
    }

    private function cacheKey(int $contactId): string
    {
        return sprintf('entity_memory_ai_reset:%d:%d', TenantCompany::id(), $contactId);
    }
}
