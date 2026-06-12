<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\ChatFirstContactAckService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatFirstContactAckServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('manager');
    }

    public function test_should_attempt_for_first_inbound_without_outbound(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $manager = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $manager->assignRole('manager');
        $session = WhatsappSession::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => false,
            'ai_mode' => 'auto',
        ]);
        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Здравствуйте, интересует кухня',
            'message_timestamp' => now(),
        ]);

        $service = app(ChatFirstContactAckService::class);

        $this->assertTrue($service->shouldAttempt($chat, $trigger));
        $this->assertFalse($service->willFullAutoPipelineSend($chat));
    }

    public function test_should_not_attempt_when_outbound_already_exists(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => false,
        ]);
        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => 'Ранее отправлено',
            'message_timestamp' => now()->subMinute(),
        ]);
        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Ещё вопрос',
            'message_timestamp' => now(),
        ]);

        $service = app(ChatFirstContactAckService::class);

        $this->assertFalse($service->shouldAttempt($chat, $trigger));
    }

    public function test_will_full_auto_pipeline_send_when_ai_enabled_and_auto_mode(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'ai_mode' => 'auto',
        ]);

        $service = app(ChatFirstContactAckService::class);

        $this->assertTrue($service->willFullAutoPipelineSend($chat));
    }

    public function test_will_not_full_auto_pipeline_send_in_draft_mode(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'ai_mode' => 'draft',
        ]);

        $service = app(ChatFirstContactAckService::class);

        $this->assertFalse($service->willFullAutoPipelineSend($chat));
    }
}
