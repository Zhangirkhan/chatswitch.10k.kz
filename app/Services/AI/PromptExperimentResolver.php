<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiExperiment;
use App\Models\AiExperimentAssignment;
use App\Models\AiExperimentVariant;
use App\Models\Chat;
use App\Support\AiFeatureFlags;
use Illuminate\Support\Facades\Schema;

final class PromptExperimentResolver
{
    public function resolveForChat(Chat $chat, string $target = AiExperiment::TARGET_AI_REPLY): ?PromptExperimentContext
    {
        $companyId = (int) ($chat->company_id ?? 0);
        if ($companyId <= 0 || ! AiFeatureFlags::enabled(AiFeatureFlags::PROMPT_EXPERIMENTS, $companyId)) {
            return null;
        }

        if (! Schema::hasTable('ai_experiments')) {
            return null;
        }

        $experiment = AiExperiment::query()
            ->where('target', $target)
            ->where('status', AiExperiment::STATUS_ACTIVE)
            ->where(function ($query) use ($companyId): void {
                $query->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->orderByRaw('company_id IS NULL ASC')
            ->first();

        if ($experiment === null) {
            return null;
        }

        $trafficPercent = max(0, min(100, (int) $experiment->traffic_percent));
        if ($trafficPercent <= 0) {
            return null;
        }

        $bucket = abs(crc32((string) $chat->id.'|'.$experiment->id)) % 100;
        if ($bucket >= $trafficPercent) {
            return null;
        }

        $assignment = AiExperimentAssignment::query()
            ->with('variant')
            ->where('experiment_id', $experiment->id)
            ->where('chat_id', $chat->id)
            ->first();

        if ($assignment?->variant !== null) {
            return $this->toContext($experiment, $assignment->variant);
        }

        $variants = $experiment->variants()->orderBy('id')->get();
        if ($variants->isEmpty()) {
            return null;
        }

        $variantIndex = abs(crc32((string) $chat->id.'|variant|'.$experiment->id)) % $variants->count();
        /** @var AiExperimentVariant $variant */
        $variant = $variants->get($variantIndex);

        AiExperimentAssignment::query()->create([
            'experiment_id' => $experiment->id,
            'variant_id' => $variant->id,
            'chat_id' => $chat->id,
            'assigned_at' => now(),
        ]);

        return $this->toContext($experiment, $variant);
    }

    private function toContext(AiExperiment $experiment, AiExperimentVariant $variant): PromptExperimentContext
    {
        return new PromptExperimentContext(
            experimentId: (int) $experiment->id,
            variantKey: (string) $variant->key,
            config: is_array($variant->config) ? $variant->config : [],
        );
    }
}
