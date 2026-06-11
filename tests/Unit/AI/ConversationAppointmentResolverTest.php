<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\ConversationAppointmentResolver;
use App\Support\TenantCompany;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConversationAppointmentResolverTest extends TestCase
{
    use RefreshDatabase;

    private ConversationAppointmentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        TenantCompany::ensureExists();
        $this->resolver = app(ConversationAppointmentResolver::class);
    }

    public function test_parses_time_from_follow_up_after_today_booking_question(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->createInbound($chat, $session->id, 'Можно сегодня записаться?', now()->subMinutes(2));
        $trigger = $this->createInbound($chat, $session->id, 'можно ли в 18', now());

        $parsed = $this->resolver->parseDateTimeFromConversation($chat, $trigger);

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed->isToday());
        $this->assertSame('18:00', $parsed->format('H:i'));
    }

    public function test_parses_kazakh_split_booking_messages(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->createInbound($chat, $session->id, 'Бүгін жазылуға бола ма?', now()->subMinutes(3));
        $trigger = $this->createInbound($chat, $session->id, '18:00-ге бола ма?', now());

        $parsed = $this->resolver->parseDateTimeFromConversation($chat, $trigger);

        $this->assertNotNull($parsed);
        $this->assertTrue($parsed->isToday());
        $this->assertSame('18:00', $parsed->format('H:i'));
    }

    public function test_resolves_appointment_from_available_slot(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-05 10:00:00', config('app.timezone')));

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->createInbound($chat, $session->id, 'Можно сегодня записаться?', now()->subMinute());
        $trigger = $this->createInbound($chat, $session->id, 'в 18', now());

        $slotStart = now()->copy()->setTime(18, 0)->toIso8601String();
        $slotEnd = now()->copy()->setTime(19, 0)->toIso8601String();

        $appointment = $this->resolver->resolve($chat, $trigger, [[
            'user_id' => 7,
            'user_name' => 'Operator',
            'starts_at' => $slotStart,
            'ends_at' => $slotEnd,
        ]]);

        $this->assertNotNull($appointment);
        $this->assertSame(7, $appointment['assignee_user_id']);
        $this->assertSame('18:00', Carbon::parse($appointment['starts_at'])->format('H:i'));

        Carbon::setTestNow();
    }

    public function test_detects_semantic_visit_intent_without_booking_word(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->createInbound($chat, $session->id, 'Можно сегодня подъехать и посмотреть?', now()->subMinutes(2));
        $trigger = $this->createInbound($chat, $session->id, 'в 18', now());

        $this->assertTrue($this->resolver->conversationHasBookingIntent($chat, $trigger));
        $this->assertTrue($this->resolver->shouldTriggerAppointmentAnalysis($chat, $trigger));
    }

    public function test_detects_scheduling_flow_after_outbound_time_question(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->createOutbound($chat, $session->id, 'Когда вам удобно подъехать сегодня?', now()->subMinute());
        $trigger = $this->createInbound($chat, $session->id, 'в 18', now());

        $this->assertTrue($this->resolver->conversationHasBookingIntent($chat, $trigger));
        $this->assertTrue($this->resolver->shouldTriggerAppointmentAnalysis($chat, $trigger));
    }

    public function test_detects_pickup_intent_phrasing(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->createInbound($chat, $session->id, 'Хочу сегодня забрать заказ', now()->subMinute());
        $trigger = $this->createInbound($chat, $session->id, 'к 18:00', now());

        $this->assertTrue($this->resolver->shouldTriggerAppointmentAnalysis($chat, $trigger));
    }

    public function test_ignores_calendar_date_token_when_parsing_confirmation_reply_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00', config('app.timezone')));

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->createInbound($chat, $session->id, 'хочу сделать заказ на сегодня в 14:00', now()->subMinutes(2));
        $this->createOutbound($chat, $session->id, 'Записала вас на заказ помидоров 10.06 в 14:00.', now()->subMinute());
        $trigger = $this->createInbound($chat, $session->id, 'привезите на абая 67', now());

        $parsed = $this->resolver->parseDateTimeFromConversation($chat, $trigger);

        $this->assertNotNull($parsed);
        $this->assertSame('14:00', $parsed->format('H:i'));

        Carbon::setTestNow();
    }

    public function test_address_follow_up_after_booking_is_not_new_appointment_request(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00', config('app.timezone')));

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->createInbound($chat, $session->id, 'хочу сделать заказ на сегодня в 14:00', now()->subMinutes(2));
        $this->createOutbound($chat, $session->id, 'Записала вас на заказ помидоров 10.06 в 14:00.', now()->subMinute());
        $trigger = $this->createInbound($chat, $session->id, 'привезите на абая 67', now());

        $this->assertFalse($this->resolver->shouldTriggerAppointmentAnalysis($chat, $trigger));
        $this->assertTrue($this->resolver->isSupplementalDetailAfterBooking($chat, $trigger));
        $this->assertFalse($this->resolver->triggerAddsSchedulingRequest($trigger));
        $this->assertStringContainsString('Приняла адрес доставки', $this->resolver->supplementalDeliveryReply($chat, $trigger));

        Carbon::setTestNow();
    }

    public function test_address_follow_up_matches_existing_chat_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-10 12:00:00', config('app.timezone')));

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);
        $user = User::factory()->create();

        $this->createInbound($chat, $session->id, 'хочу сделать заказ на сегодня в 14:00', now()->subMinutes(2));
        CalendarEvent::query()->create([
            'user_id' => $user->id,
            'assignee_user_id' => $user->id,
            'chat_id' => $chat->id,
            'title' => 'Заказ',
            'starts_at' => now()->copy()->setTime(14, 0),
            'ends_at' => now()->copy()->setTime(15, 0),
            'all_day' => false,
            'source' => CalendarEvent::SOURCE_AI_AUTO,
        ]);
        $trigger = $this->createInbound($chat, $session->id, 'привезите на абая 67', now());

        $booking = $this->resolver->findMatchingChatBooking($chat, $trigger);

        $this->assertNotNull($booking);
        $this->assertTrue($this->resolver->isSupplementalDetailAfterBooking($chat, $trigger));

        Carbon::setTestNow();
    }

    public function test_post_delivery_feedback_is_not_supplemental_address(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-12 01:00:00', config('app.timezone')));

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create(['whatsapp_session_id' => $session->id]);

        $this->createInbound($chat, $session->id, '5 кг помидоров, Сейфуллина 56', now()->subMinutes(3));
        $this->createOutbound($chat, $session->id, 'Да, подтверждаем заказ! ожидайте курьера!', now()->subMinutes(2));
        $trigger = $this->createInbound(
            $chat,
            $session->id,
            'ого, курьер так быстро приехал, оплатил наличными спустбо за помидоры очень вкусно',
            now(),
        );

        $this->assertFalse($this->resolver->isSupplementalDetailAfterBooking($chat, $trigger));

        Carbon::setTestNow();
    }

    private function createOutbound(Chat $chat, int $sessionId, string $body, Carbon $at): Message
    {
        return Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $sessionId,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => $body,
            'message_timestamp' => $at,
        ]);
    }

    private function createInbound(Chat $chat, int $sessionId, string $body, Carbon $at): Message
    {
        return Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $sessionId,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => $body,
            'message_timestamp' => $at,
        ]);
    }
}
