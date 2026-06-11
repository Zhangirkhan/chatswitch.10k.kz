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

    public function test_post_delivery_feedback_is_not_delivery_address(): void
    {
        $message = 'ого, курьер так быстро приехал, оплатил наличными спустбо за помидоры очень вкусно';

        $this->assertTrue($this->detector->isOrderCompletionFeedback($message));
        $this->assertFalse($this->detector->isProvidingAddressOrDeliveryDetail($message));
        $this->assertSame(
            ClientMessageIntentDetector::INTENT_ORDER_COMPLETION,
            $this->detector->detect($message),
        );
    }

    public function test_imperative_delivery_address_still_detected(): void
    {
        $message = 'привезите на абая 67';

        $this->assertFalse($this->detector->isOrderCompletionFeedback($message));
        $this->assertTrue($this->detector->isProvidingAddressOrDeliveryDetail($message));
        $this->assertSame(
            ClientMessageIntentDetector::INTENT_DELIVERY,
            $this->detector->detect($message),
        );
    }

    public function test_repeat_order_intent_detected_after_closure_message(): void
    {
        $message = 'здравствуйте, хочу еще заказать помидоры';

        $this->assertTrue($this->detector->isRepeatOrderIntent($message));
        $this->assertFalse($this->detector->isOrderCompletionFeedback($message));
    }

    public function test_completion_feedback_is_not_repeat_order(): void
    {
        $message = 'спасибо, курьер быстро приехал';

        $this->assertTrue($this->detector->isOrderCompletionFeedback($message));
        $this->assertFalse($this->detector->isRepeatOrderIntent($message));
    }
}
