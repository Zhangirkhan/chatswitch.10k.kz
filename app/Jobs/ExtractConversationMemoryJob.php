<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chat;
use App\Services\AI\ConversationMemoryExtractor;
use App\Support\AiFeatureFlags;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Debounced async job that extracts durable client facts from conversation history
 * and persists them to EntityMemory (the managed "AI-факты (авто)" section).
 *
 * Dispatched after each inbound message (debounced) and after each AI reply is sent,
 * so the memory is kept fresh without incurring synchronous LLM latency.
 *
 * Only executes when the ai.memory_extraction feature flag is enabled for the tenant.
 */
final class ExtractConversationMemoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        public readonly int $chatId,
        public readonly ?int $tenantCompanyId = null,
    ) {}

    public function handle(ConversationMemoryExtractor $extractor): void
    {
        $chat = Chat::query()
            ->with(['contact:id,name,push_name,phone_number'])
            ->whereKey($this->chatId)
            ->first();

        if ($chat === null) {
            return;
        }

        if (! AiFeatureFlags::enabled(AiFeatureFlags::MEMORY_EXTRACTION, $chat->company_id)) {
            return;
        }

        if ($chat->contact_id === null) {
            return;
        }

        Log::info('[memory-extractor] extracting conversation memory', [
            'chat_id' => $chat->id,
            'contact_id' => $chat->contact_id,
        ]);

        $extractor->extractAndPersist($chat);
    }

    public function viaQueue(): string
    {
        return 'default';
    }

    /**
     * Dispatch with a debounce delay so rapid bursts produce only one extraction call.
     */
    public static function dispatchDebounced(int $chatId, ?int $tenantCompanyId = null): void
    {
        $delaySeconds = max(10, (int) config('ai.memory_extraction_debounce_seconds', 30));

        self::dispatch($chatId, $tenantCompanyId)
            ->delay(now()->addSeconds($delaySeconds));
    }
}
