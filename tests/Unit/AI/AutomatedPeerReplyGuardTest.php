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
}
