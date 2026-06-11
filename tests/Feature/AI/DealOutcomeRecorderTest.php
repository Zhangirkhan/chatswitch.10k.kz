<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\DealOutcome;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\WhatsappSession;
use App\Services\AI\DealOutcomeRecorder;
use App\Support\AiFeatureFlags;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class DealOutcomeRecorderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('employee');
        TenantCompany::ensureExists();
    }

    public function test_won_stage_creates_deal_outcome(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        AiFeatureFlags::enable(AiFeatureFlags::WIN_LOSS_LEARNING, $company->id);

        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $session = WhatsappSession::factory()->create(['company_id' => $company->id]);
        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'color' => '#000',
            'is_active' => true,
            'position' => 0,
        ]);
        $wonStage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Успешно закрыто',
            'color' => '#0f0',
            'position' => 5,
            'is_active' => true,
        ]);

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $wonStage->id,
            'sales_state' => ['score' => 80, 'grade' => 'A'],
        ]);

        app(DealOutcomeRecorder::class)->recordFromStageTransition($chat, $wonStage);

        $outcome = DealOutcome::query()->where('chat_id', $chat->id)->first();
        $this->assertNotNull($outcome);
        $this->assertTrue($outcome->won);
        $this->assertSame('исход: выигран', ContactTag::query()
            ->where('contact_id', $contact->id)
            ->where('name', 'like', 'исход:%')
            ->value('name'));
    }

    public function test_lost_stage_creates_lost_outcome(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        AiFeatureFlags::enable(AiFeatureFlags::WIN_LOSS_LEARNING, $company->id);

        $session = WhatsappSession::factory()->create(['company_id' => $company->id]);
        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'color' => '#000',
            'is_active' => true,
            'position' => 0,
        ]);
        $lostStage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Отказ',
            'color' => '#f00',
            'position' => 6,
            'is_active' => true,
        ]);

        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $lostStage->id,
        ]);

        app(DealOutcomeRecorder::class)->recordFromStageTransition($chat, $lostStage);

        $outcome = DealOutcome::query()->where('chat_id', $chat->id)->first();
        $this->assertNotNull($outcome);
        $this->assertFalse($outcome->won);
    }
}
