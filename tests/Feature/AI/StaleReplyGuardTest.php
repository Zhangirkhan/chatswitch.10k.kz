<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Jobs\GenerateAiReplyJob;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
        config([
            'services.openai.api_key' => 'test-key',
            'funnel.department_routing.enabled' => false,
        ]);
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
            'ai_responder_user_id' => $responder->id,
        ]);

        $trigger = Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'Первое сообщение',
            'message_timestamp' => now()->subSeconds(10),
        ]);

        Http::fake(function () use ($chat) {
            Message::factory()->create([
                'chat_id' => $chat->id,
                'direction' => 'inbound',
                'body' => 'Второе сообщение (новее)',
                'message_timestamp' => now(),
            ]);

            return Http::response([
                'choices' => [
                    ['message' => ['content' => 'Ответ AI на первое сообщение']],
                ],
            ], 200);
        });

        $job = new GenerateAiReplyJob($chat->id, $trigger->id, TenantCompany::id());
        $this->app->call([$job, 'handle']);

        $log = AiResponseLog::query()
            ->where('trigger_message_id', $trigger->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('cancelled', $log->status,
            'Stale reply should be marked cancelled when a newer inbound arrived');
    }
}
