<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Message;
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
