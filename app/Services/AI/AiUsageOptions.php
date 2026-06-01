<?php

declare(strict_types=1);

namespace App\Services\AI;

final readonly class AiUsageOptions
{
    public function __construct(
        public string $scenario,
        public ?int $companyId = null,
    ) {}
}
