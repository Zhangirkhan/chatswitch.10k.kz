<?php

declare(strict_types=1);

namespace Tests\Feature\Funnel;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\WhatsappSession;
use App\Services\Funnel\ChatFunnelIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ChatFunnelIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_repair_maps_foreign_funnel_to_same_named_company_funnel(): void
    {
        $companyA = Company::query()->create([
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);
        $companyB = Company::query()->create([
            'name' => 'Tenant B',
            'slug' => 'tenant-b',
            'is_active' => true,
            'subscription_status' => 'active',
        ]);

        $foreignFunnel = Funnel::query()->create([
            'company_id' => $companyA->id,
            'name' => 'Универсальная продажа',
            'is_active' => true,
            'position' => 0,
        ]);
        $foreignStage = FunnelStage::query()->create([
            'funnel_id' => $foreignFunnel->id,
            'name' => 'Квалификация',
            'color' => '#000000',
            'stage_type' => 'open',
            'position' => 1,
            'is_active' => true,
        ]);

        $localFunnel = Funnel::query()->create([
            'company_id' => $companyB->id,
            'name' => 'Универсальная продажа',
            'is_active' => true,
            'position' => 0,
        ]);
        $localStage = FunnelStage::query()->create([
            'funnel_id' => $localFunnel->id,
            'name' => 'Квалификация',
            'color' => '#000000',
            'stage_type' => 'open',
            'position' => 1,
            'is_active' => true,
        ]);

        $session = WhatsappSession::factory()->create(['company_id' => $companyB->id]);
        $chat = Chat::factory()->create([
            'company_id' => $companyB->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $foreignFunnel->id,
            'funnel_stage_id' => $foreignStage->id,
        ]);

        $repaired = app(ChatFunnelIntegrityService::class)->repair($chat->fresh() ?? $chat);

        $this->assertTrue($repaired);
        $chat->refresh();
        $this->assertSame($localFunnel->id, $chat->funnel_id);
        $this->assertSame($localStage->id, $chat->funnel_stage_id);
    }
}
