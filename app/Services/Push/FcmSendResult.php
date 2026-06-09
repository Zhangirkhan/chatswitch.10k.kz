<?php

declare(strict_types=1);

namespace App\Services\Push;

final readonly class FcmSendResult
{
    public function __construct(
        public bool $success,
        public bool $tokenInvalid = false,
        public ?string $error = null,
    ) {}
}
