<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiUsageEvent;

final class AiUsageRecorder
{
    public function recordChat(
        string $scenario,
        ?int $companyId,
        ?string $model,
        int $tokensInput,
        int $tokensOutput,
    ): void {
        if ($tokensInput === 0 && $tokensOutput === 0) {
            return;
        }

        AiUsageEvent::query()->create([
            'company_id' => $companyId,
            'scenario' => $scenario,
            'kind' => 'chat',
            'model' => $model,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
        ]);
    }

    public function recordEmbedding(
        string $scenario,
        ?int $companyId,
        ?string $model,
        int $tokensInput,
    ): void {
        if ($tokensInput === 0) {
            return;
        }

        AiUsageEvent::query()->create([
            'company_id' => $companyId,
            'scenario' => $scenario,
            'kind' => 'embedding',
            'model' => $model,
            'tokens_input' => $tokensInput,
            'tokens_output' => 0,
        ]);
    }

    public function recordWhisper(
        string $scenario,
        ?int $companyId,
        ?string $model,
        int $audioSeconds,
    ): void {
        if ($audioSeconds <= 0) {
            return;
        }

        AiUsageEvent::query()->create([
            'company_id' => $companyId,
            'scenario' => $scenario,
            'kind' => 'whisper',
            'model' => $model,
            'tokens_input' => 0,
            'tokens_output' => 0,
            'audio_seconds' => $audioSeconds,
        ]);
    }
}
