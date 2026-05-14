<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TeamMessage;

final readonly class TeamChatSendResult
{
    public function __construct(
        public TeamMessage $message,
        public bool $duplicate,
    ) {}
}
