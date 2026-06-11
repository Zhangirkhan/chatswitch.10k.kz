<?php

declare(strict_types=1);

namespace App\Support\PlatformChangelog;

use Carbon\CarbonImmutable;

final readonly class GitCommitSnapshot
{
    public function __construct(
        public string $hash,
        public string $subject,
        public string $body,
        public CarbonImmutable $committedAt,
    ) {}
}
