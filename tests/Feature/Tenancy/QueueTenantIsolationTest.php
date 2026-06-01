<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ApplyTenantToQueue;
use App\Jobs\RunAiFunnelOrchestratorJob;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Tests\TestCase;

final class QueueTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_middleware_clears_stale_tenant_and_resolves_from_tenant_company_id(): void
    {
        $companyA = $this->createTenantCompany(['slug' => 'tenant-a', 'name' => 'Tenant A']);
        $companyB = Company::query()->create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $session = WhatsappSession::factory()->create(['company_id' => $companyB->id]);
        $chat = Chat::factory()->create([
            'company_id' => $companyB->id,
            'whatsapp_session_id' => $session->id,
        ]);
        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Привет',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        app(TenantContext::class)->setCompany($companyA);
        $this->assertNull(Chat::query()->find($chat->id));

        $this->runQueueTenantMiddleware(new RunAiFunnelOrchestratorJob(
            $chat->id,
            $trigger->id,
            $companyB->id,
        ));

        $this->assertSame($companyB->id, app(TenantContext::class)->companyId());
        $this->assertNotNull(Chat::query()->find($chat->id));
    }

    public function test_queue_middleware_resolves_tenant_from_chat_id_when_property_exists(): void
    {
        $companyA = $this->createTenantCompany(['slug' => 'tenant-a', 'name' => 'Tenant A']);
        $companyB = Company::query()->create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $session = WhatsappSession::factory()->create(['company_id' => $companyB->id]);
        $chat = Chat::factory()->create([
            'company_id' => $companyB->id,
            'whatsapp_session_id' => $session->id,
        ]);
        $trigger = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'chat',
            'body' => 'Привет',
            'ack' => 'delivered',
            'message_timestamp' => now(),
        ]);

        app(TenantContext::class)->setCompany($companyA);

        $this->runQueueTenantMiddleware(new RunAiFunnelOrchestratorJob(
            $chat->id,
            $trigger->id,
        ));

        $this->assertSame($companyB->id, app(TenantContext::class)->companyId());
        $this->assertNotNull(Chat::query()->find($chat->id));
    }

    private function runQueueTenantMiddleware(object $command): void
    {
        $queueJob = new class($command)
        {
            public function __construct(private object $command) {}

            /** @return array<string, mixed> */
            public function payload(): array
            {
                return [
                    'data' => [
                        'command' => serialize($this->command),
                    ],
                ];
            }
        };

        app(ApplyTenantToQueue::class)->handle(new JobProcessing('sync', $queueJob));
    }
}
