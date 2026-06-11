<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Models\Company;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\AI\ChatLeadScoreService;
use App\Services\AI\ChatSalesStateService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatLeadScoreServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('employee');
        TenantCompany::ensureExists();
    }

    public function test_empty_lead_scores_grade_c(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        $partial = [
            'budget_known' => false,
            'requirements_known' => false,
            'timeline_known' => false,
            'decision_maker_known' => false,
            'objections_open' => [],
            'deferral_detected' => false,
        ];

        $result = app(ChatLeadScoreService::class)->score($chat, $partial);

        $this->assertSame(ChatLeadScoreService::GRADE_C, $result['grade']);
        $this->assertLessThan(40, $result['score']);
    }

    public function test_full_bant_scores_high(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'last_message_at' => now(),
            'last_message_direction' => 'inbound',
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'интересует',
            'message_timestamp' => now(),
        ]);
        Message::query()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'ещё вопрос',
            'message_timestamp' => now(),
        ]);
        Message::query()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'и ещё',
            'message_timestamp' => now(),
        ]);

        $partial = [
            'budget_known' => true,
            'requirements_known' => true,
            'timeline_known' => true,
            'decision_maker_known' => true,
            'objections_open' => [],
            'deferral_detected' => false,
        ];

        $facts = ['timeline' => 'срочно нужно'];
        $result = app(ChatLeadScoreService::class)->score($chat, $partial, $facts);

        $this->assertGreaterThanOrEqual(70, $result['score']);
        $this->assertSame(ChatLeadScoreService::GRADE_A, $result['grade']);
    }

    public function test_deferral_lowers_score_in_sales_state(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'sales_state' => ['deferral_detected' => true],
        ]);

        $state = app(ChatSalesStateService::class)->compute($chat, [
            'budget' => '500 000',
            'requirements' => 'диван',
            'timeline' => 'месяц',
            'decision_maker' => 'сам',
        ]);

        $this->assertTrue($state['deferral_detected'] ?? false);
        $this->assertArrayHasKey('score', $state);
        $this->assertLessThan(70, $state['score']);
    }
}
