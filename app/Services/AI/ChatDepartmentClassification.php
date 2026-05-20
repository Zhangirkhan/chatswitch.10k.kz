<?php

declare(strict_types=1);

namespace App\Services\AI;

final readonly class ChatDepartmentClassification
{
    public function __construct(
        public int $departmentId,
        public float $confidence,
        public string $reason,
    ) {}
}
