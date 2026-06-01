<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\AiSafeErrorMessage;
use Tests\TestCase;

final class AiSafeErrorMessageTest extends TestCase
{
    public function test_admin_sees_quota_message(): void
    {
        $message = AiSafeErrorMessage::forUser(
            'insufficient_quota: You exceeded your current quota',
            true,
        );

        $this->assertStringContainsString('квота OpenAI', $message);
        $this->assertStringContainsString('platform.openai.com', $message);
    }

    public function test_operator_sees_generic_quota_message(): void
    {
        $message = AiSafeErrorMessage::forUser(
            'insufficient_quota: You exceeded your current quota',
            false,
        );

        $this->assertStringContainsString('лимит', $message);
        $this->assertStringNotContainsString('platform.openai.com', $message);
    }
}
