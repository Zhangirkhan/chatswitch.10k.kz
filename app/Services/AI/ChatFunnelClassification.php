<?php

declare(strict_types=1);

namespace App\Services\AI;

final readonly class ChatFunnelClassification
{
    public function __construct(
        public int $funnelId,
        public int $funnelStageId,
        public float $confidence,
        public string $reason,
    ) {}
}
