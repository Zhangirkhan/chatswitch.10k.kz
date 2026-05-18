<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Message;

final readonly class OutboundTextSendResult
{
    public function __construct(
        public Message $message,
        public bool $toneProfileLearningScheduled,
    ) {}
}
