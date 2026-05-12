<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chat;
use App\Models\User;
use App\Services\AI\ToneProfileAnalyzer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class AnalyzeEmployeeToneProfileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $userId,
        public readonly int $companyId,
        public readonly ?int $chatId = null,
    ) {}

    public function handle(ToneProfileAnalyzer $analyzer): void
    {
        $user = User::query()->whereKey($this->userId)->first();
        if ($user === null) {
            return;
        }

        $chat = $this->chatId !== null
            ? Chat::query()->whereKey($this->chatId)->first()
            : null;

        try {
            $analyzer->analyze($user, $this->companyId, $chat);
        } catch (\Throwable $e) {
            Log::warning('[ai-tone] failed to analyze profile', [
                'user_id' => $this->userId,
                'company_id' => $this->companyId,
                'chat_id' => $this->chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
