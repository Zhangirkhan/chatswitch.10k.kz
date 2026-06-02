<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Message;
use App\Services\AI\AutomatedPeerReplyGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AutomatedPeerReplyGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_medical_autoresponder_phrase(): void
    {
        $company = Company::create(['name' => 'Test', 'slug' => 'test-bot-guard']);
        $chat = Chat::factory()->create(['company_id' => $company->id]);
        $message = Message::create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Я специализируюсь только на вопросах рака мочевого пузыря.',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $guard = new AutomatedPeerReplyGuard;

        $this->assertTrue($guard->shouldSuppress($chat, $message));
    }

    public function test_allows_normal_customer_question(): void
    {
        $company = Company::create(['name' => 'Test', 'slug' => 'test-bot-guard-2']);
        $chat = Chat::factory()->create(['company_id' => $company->id]);
        $message = Message::create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Сколько стоит замер окон?',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $guard = new AutomatedPeerReplyGuard;

        $this->assertFalse($guard->shouldSuppress($chat, $message));
    }

    public function test_allows_thank_you_after_active_ai_dialog(): void
    {
        $company = Company::create(['name' => 'Test', 'slug' => 'test-bot-guard-thanks']);
        $chat = Chat::factory()->create(['company_id' => $company->id]);

        foreach ([
            ['inbound', 'Здравствуйте какие услуги есть', now()->subMinutes(4), null],
            ['outbound', 'Каталог услуг', now()->subMinutes(3), ['ai' => ['generated' => true]]],
            ['inbound', 'Қанша уақыт', now()->subMinutes(2), null],
            ['outbound', 'Ответ по срокам', now()->subMinutes(2)->addSeconds(30), ['ai' => ['generated' => true]]],
            ['inbound', 'Қанша уақыт', now()->subMinute(), null],
            ['outbound', 'Повтор по срокам', now()->subMinute()->addSeconds(20), ['ai' => ['generated' => true]]],
        ] as [$direction, $body, $timestamp, $metadata]) {
            Message::create([
                'chat_id' => $chat->id,
                'direction' => $direction,
                'type' => 'chat',
                'body' => $body,
                'ack' => 'delivered',
                'message_timestamp' => $timestamp,
                'metadata' => $metadata ?? null,
            ]);
        }

        $message = Message::create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'спасибо',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        $guard = new AutomatedPeerReplyGuard;

        $this->assertFalse($guard->shouldSuppress($chat, $message));
    }
}
