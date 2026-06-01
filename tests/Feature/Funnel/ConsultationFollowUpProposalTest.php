<?php

declare(strict_types=1);

namespace Tests\Feature\Funnel;

use App\Models\AiFollowUpProposal;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Company;
use App\Models\CompanyPromotion;
use App\Models\Funnel;
use App\Models\FunnelStage;
use App\Models\FunnelStageAiRule;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\Funnel\ConsultationFollowUpProposalService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ConsultationFollowUpProposalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['administrator', 'manager', 'employee'] as $role) {
            Role::findOrCreate($role);
        }

        SystemSetting::setValue('module_funnels', 'on');
        TenantCompany::ensureExists();
    }

    public function test_scheduler_creates_proposal_for_silent_chat_after_outbound(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $operator = User::factory()->create(['company_id' => $company->id]);
        $operator->assignRole('employee');

        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Продажи',
            'description' => null,
            'color' => '#25d366',
            'is_active' => true,
            'position' => 0,
        ]);
        $stage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'КП отправлено',
            'color' => '#3b82f6',
            'position' => 0,
            'is_active' => true,
        ]);

        $promo = CompanyPromotion::query()->create([
            'company_id' => $company->id,
            'name' => 'Скидка 10%',
            'discount_type' => CompanyPromotion::TYPE_PERCENT,
            'percent' => 10,
            'is_active' => true,
        ]);

        FunnelStageAiRule::query()->create([
            'company_id' => $company->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
            'goal' => 'Дожать после КП',
            'follow_up_strategy' => FunnelStageAiRule::FOLLOW_UP_STRATEGY_MANAGER_PROPOSALS,
            'follow_up_silence_after' => FunnelStageAiRule::FOLLOW_UP_SILENCE_OUTBOUND,
            'follow_up_delay_hours' => 1,
            'follow_up_cooldown_hours' => 48,
            'follow_up_max_count' => 2,
        ]);

        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'funnel_id' => $funnel->id,
            'funnel_stage_id' => $stage->id,
            'funnel_tracking_enabled' => true,
            'chat_name' => 'Айдар',
            'is_group' => false,
            'last_message_direction' => 'outbound',
            'last_message_at' => now()->subHours(3),
        ]);

        ChatAssignment::query()->create([
            'chat_id' => $chat->id,
            'user_id' => $operator->id,
            'assigned_by' => $operator->id,
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Стоимость пакета — 150 000 ₸',
            'message_timestamp' => now()->subHours(3),
        ]);

        config(['services.openai.api_key' => 'test-key']);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'proposals' => [
                                    ['id' => 'soft', 'label' => 'Мягко', 'body' => 'Здравствуйте, Айдар!', 'uses_promo' => false, 'promo_ref' => null],
                                ],
                                'recommended_id' => 'soft',
                                'manager_note' => 'Клиент молчит после цены.',
                                'context_summary' => 'КП отправлено.',
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $created = app(ConsultationFollowUpProposalService::class)->scheduleDue();
        $this->assertSame(1, $created);

        $this->assertDatabaseHas('ai_follow_up_proposals', [
            'chat_id' => $chat->id,
            'status' => AiFollowUpProposal::STATUS_NEEDS_MANAGER,
        ]);
    }

    public function test_inbound_message_dismisses_pending_proposal(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'is_group' => false,
        ]);

        AiFollowUpProposal::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'status' => AiFollowUpProposal::STATUS_NEEDS_MANAGER,
            'proposals' => [['id' => 'soft', 'label' => 'A', 'body' => 'Hi', 'uses_promo' => false, 'promo_ref' => null]],
            'recommended_id' => 'soft',
        ]);

        app(ConsultationFollowUpProposalService::class)->dismissPendingForChat($chat);

        $this->assertDatabaseHas('ai_follow_up_proposals', [
            'chat_id' => $chat->id,
            'status' => AiFollowUpProposal::STATUS_DISMISSED,
        ]);
    }
}
