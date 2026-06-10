<?php

declare(strict_types=1);

namespace Tests\Unit\Funnel;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\WhatsappSession;
use App\Services\Funnel\FunnelPaymentStageBypass;
use App\Support\FunnelStageType;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FunnelPaymentStageBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['funnel.payment_stages_required' => false]);
    }

    public function test_resolve_target_remaps_payment_stage_to_production(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Bypass test funnel',
            'is_active' => true,
            'position' => 1,
        ]);

        $agreement = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Согласование условий',
            'stage_type' => FunnelStageType::OFFER,
            'position' => 3,
            'is_active' => true,
        ]);
        $payment = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Ожидание предоплаты',
            'stage_type' => FunnelStageType::PAYMENT,
            'position' => 4,
            'is_active' => true,
        ]);
        $production = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'В работе',
            'stage_type' => FunnelStageType::PRODUCTION,
            'position' => 6,
            'is_active' => true,
        ]);

        $session = WhatsappSession::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $agreement->id,
        ]);
        $chat->load(['funnel.stages', 'funnelStage']);

        $bypass = app(FunnelPaymentStageBypass::class);

        $resolved = $bypass->resolveTargetStageId($chat, (int) $payment->id);

        $this->assertSame((int) $production->id, $resolved);
    }

    public function test_resolve_target_keeps_non_payment_stage_when_bypass_active(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Bypass keep funnel',
            'is_active' => true,
            'position' => 1,
        ]);

        $offer = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Предложение',
            'stage_type' => FunnelStageType::OFFER,
            'position' => 2,
            'is_active' => true,
        ]);

        $session = WhatsappSession::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $offer->id,
        ]);
        $chat->load(['funnel.stages', 'funnelStage']);

        $bypass = app(FunnelPaymentStageBypass::class);

        $this->assertSame((int) $offer->id, $bypass->resolveTargetStageId($chat, (int) $offer->id));
    }

    public function test_resolve_target_does_not_remap_when_payment_required(): void
    {
        config(['funnel.payment_stages_required' => true]);

        $company = Company::query()->findOrFail(TenantCompany::id());
        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Payment required funnel',
            'is_active' => true,
            'position' => 1,
        ]);

        $payment = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Ожидание оплаты',
            'stage_type' => FunnelStageType::PAYMENT,
            'position' => 4,
            'is_active' => true,
        ]);

        $session = WhatsappSession::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $payment->id,
        ]);
        $chat->load(['funnel.stages', 'funnelStage']);

        $bypass = app(FunnelPaymentStageBypass::class);

        $this->assertSame((int) $payment->id, $bypass->resolveTargetStageId($chat, (int) $payment->id));
    }
}
