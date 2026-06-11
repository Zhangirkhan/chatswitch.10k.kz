<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\GenerateAiReplyJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\AiReplyGenerator;
use App\Services\AI\AiResponderResolver;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StaleReplyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }
        TenantCompany::ensureExists();
    }

    /**
     * C6: If a newer inbound message arrives while the LLM is generating,
     * the stale reply should be discarded (AiResponseLog marked 'cancelled').
     */
    public function test_stale_reply_is_discarded_when_newer_inbound_arrives(): void
    {
        $responder = User::factory()->create([
            'company_id' => TenantCompany::id(),
            'is_active' => true,
        ]);
        $responder->assignRole('manager');

        $chat = Chat::factory()->create([
            'company_id' => TenantCompany::id(),
            'ai_enabled' => true,
            'ai_mode' => 'auto',
        ]);

        $chat->forceFill(['ai_responder_user_id' => $responder->id])->save();

        // Trigger message
        $trigger = Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'Первое сообщение',
            'message_timestamp' => now()->subSeconds(10),
        ]);

        // Newer inbound that arrived AFTER the trigger — simulates rapid burst.
        Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'Второе сообщение (новее)',
            'message_timestamp' => now()->subSeconds(2),
        ]);

        // Create the log in 'generating' state (as the job would after claiming).
        $log = AiResponseLog::create([
            'company_id' => TenantCompany::id(),
            'chat_id' => $chat->id,
            'user_id' => $responder->id,
            'trigger_message_id' => $trigger->id,
            'mode' => 'auto',
            'status' => 'generating',
        ]);

        // Mock the generator to return a reply (simulating LLM having returned).
        $generatorMock = $this->createMock(AiReplyGenerator::class);
        $generatorMock->method('generate')->willReturn([
            'reply' => 'Ответ AI на первое сообщение',
            'prompt_hash' => 'testhash',
            'metadata' => [],
        ]);
        $this->app->instance(AiReplyGenerator::class, $generatorMock);

        // Run the job — it should detect the newer inbound and cancel.
        $job = new GenerateAiReplyJob($chat->id, $trigger->id, TenantCompany::id());
        $job->handle(
            $generatorMock,
            app(\App\Services\OutboundChatMessageDispatcher::class),
            app(\App\Services\AI\WhatsappAiTypingService::class),
            app(AiResponderResolver::class),
            app(\App\Services\AI\ChatDepartmentRoutingService::class),
            app(\App\Services\AI\ChatOffHoursReplyService::class),
            app(\App\Services\AI\AutomatedPeerReplyGuard::class),
            app(\App\Services\AI\ChatIdleAiReplyService::class),
            app(\App\Services\AI\ChatConflictService::class),
        );

        $log->refresh();
        $this->assertEquals('cancelled', $log->status,
            'Stale reply should be marked cancelled when a newer inbound arrived');
    }
}
