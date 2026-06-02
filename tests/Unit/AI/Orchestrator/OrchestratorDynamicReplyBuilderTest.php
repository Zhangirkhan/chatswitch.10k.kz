<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Orchestrator;

use App\Services\AI\Orchestrator\OrchestratorDynamicReplyBuilder;
use Tests\TestCase;

final class OrchestratorDynamicReplyBuilderTest extends TestCase
{
    public function test_time_reply_uses_kazakh_for_kazakh_message(): void
    {
        $builder = app(OrchestratorDynamicReplyBuilder::class);

        $reply = $builder->buildForMessage('Қанша уақыт', 1);

        $this->assertNotNull($reply);
        $this->assertStringContainsString('мерзім', mb_strtolower($reply['reply']));
        $this->assertStringNotContainsString('в каталоге', mb_strtolower($reply['reply']));
    }

    public function test_time_reply_uses_russian_for_russian_message(): void
    {
        $builder = app(OrchestratorDynamicReplyBuilder::class);

        $reply = $builder->buildForMessage('сколько времени займёт', 1);

        $this->assertNotNull($reply);
        $this->assertStringContainsString('срок', mb_strtolower($reply['reply']));
    }

    public function test_acknowledgement_reply_is_localized(): void
    {
        $builder = app(OrchestratorDynamicReplyBuilder::class);

        $reply = $builder->buildForMessage('спасибо', 1);

        $this->assertNotNull($reply);
        $this->assertStringContainsString('пожалуйста', mb_strtolower($reply['reply']));
    }
}
