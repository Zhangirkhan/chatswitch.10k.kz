<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Orchestrator;

use App\Services\AI\Orchestrator\ClientMessageIntentDetector;
use Tests\TestCase;

final class ClientMessageIntentDetectorTest extends TestCase
{
    private ClientMessageIntentDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = app(ClientMessageIntentDetector::class);
    }

    public function test_detects_kazakh_time_question(): void
    {
        $this->assertSame(
            ClientMessageIntentDetector::INTENT_TIME,
            $this->detector->detect('Қанша уақыт'),
        );
    }

    public function test_detects_topic_shift_from_catalog_to_time(): void
    {
        $this->assertTrue($this->detector->isTopicShift(
            'Здравствуйте какие услуги есть',
            'Қанша уақыт',
        ));
    }

    public function test_vague_follow_up_is_not_specific(): void
    {
        $this->assertTrue($this->detector->isVagueFollowUp('ок'));
        $this->assertFalse($this->detector->isSpecific('ок'));
    }

    public function test_price_question_is_specific(): void
    {
        $this->assertSame(
            ClientMessageIntentDetector::INTENT_PRICE,
            $this->detector->detect('қанша тұрады'),
        );
    }
}
