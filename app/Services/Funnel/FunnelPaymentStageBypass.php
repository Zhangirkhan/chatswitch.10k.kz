<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\FunnelStage;
use App\Support\FunnelStageType;
use Illuminate\Support\Collection;

final class FunnelPaymentStageBypass
{
    public function isRequired(): bool
    {
        return (bool) config('funnel.payment_stages_required', false);
    }

    public function isBypassActive(): bool
    {
        return ! $this->isRequired();
    }

    public function isPaymentStage(FunnelStage $stage): bool
    {
        if (FunnelStageType::normalize($stage->stage_type) === FunnelStageType::PAYMENT) {
            return true;
        }

        $name = mb_strtolower(trim($stage->name));

        return $name !== ''
            && (str_contains($name, 'оплат') || str_contains($name, 'предоплат'));
    }

    public function resolveTargetStageId(Chat $chat, ?int $targetStageId): ?int
    {
        if ($targetStageId === null || ! $this->isBypassActive()) {
            return $targetStageId;
        }

        $chat->loadMissing(['funnel.stages', 'funnelStage']);
        if ($chat->funnel === null) {
            return $targetStageId;
        }

        $target = $chat->funnel->stages->firstWhere('id', $targetStageId);
        if (! $target instanceof FunnelStage || ! $this->isPaymentStage($target)) {
            return $targetStageId;
        }

        return $this->nextStageAfterPayment($chat) ?? $targetStageId;
    }

    public function nextStageAfterPayment(Chat $chat): ?int
    {
        $chat->loadMissing(['funnel.stages', 'funnelStage']);
        if ($chat->funnel === null) {
            return null;
        }

        /** @var Collection<int, FunnelStage> $stages */
        $stages = $chat->funnel->stages
            ->filter(fn (FunnelStage $stage): bool => $stage->is_active)
            ->sortBy(fn (FunnelStage $stage): array => [$stage->position, $stage->id])
            ->values();

        if ($stages->isEmpty()) {
            return null;
        }

        $currentPosition = $chat->funnelStage?->position;

        $candidates = $stages->filter(function (FunnelStage $stage) use ($currentPosition): bool {
            if ($this->isPaymentStage($stage)) {
                return false;
            }

            if ($currentPosition !== null && $stage->position <= $currentPosition) {
                return false;
            }

            return true;
        });

        $production = $candidates->first(
            fn (FunnelStage $stage): bool => FunnelStageType::normalize($stage->stage_type) === FunnelStageType::PRODUCTION,
        );
        if ($production instanceof FunnelStage) {
            return (int) $production->id;
        }

        $delivery = $candidates->first(
            fn (FunnelStage $stage): bool => FunnelStageType::normalize($stage->stage_type) === FunnelStageType::DELIVERY,
        );
        if ($delivery instanceof FunnelStage) {
            return (int) $delivery->id;
        }

        $first = $candidates->first();
        if ($first instanceof FunnelStage) {
            return (int) $first->id;
        }

        return null;
    }

    public function chatIsOnPaymentStage(Chat $chat): bool
    {
        $stage = $chat->funnelStage;

        return $stage instanceof FunnelStage && $this->isPaymentStage($stage);
    }
}
