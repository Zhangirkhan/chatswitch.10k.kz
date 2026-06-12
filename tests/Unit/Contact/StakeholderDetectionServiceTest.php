<?php

declare(strict_types=1);

namespace Tests\Unit\Contact;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\SalesPlaybook;
use App\Services\AI\ChatSalesStateService;
use App\Services\AI\NextBestActionEngine;
use App\Services\Contact\StakeholderDetectionService;
use App\Support\AiFeatureFlags;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StakeholderDetectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_decision_maker_from_inbound_message(): void
    {
        $company = $this->createTenantCompany(['name' => 'Stake Co', 'slug' => 'stake-co']);
        AiFeatureFlags::enable(AiFeatureFlags::STAKEHOLDERS, $company->id);

        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create(['company_id' => $company->id, 'contact_id' => $contact->id]);
        $message = Message::factory()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'Я спрошу у директора и вернусь с ответом',
        ]);

        app(StakeholderDetectionService::class)->detectFromMessage($chat, $message);

        $this->assertDatabaseHas('contact_stakeholders', [
            'account_contact_id' => $contact->id,
            'role' => 'decision_maker',
            'source' => 'ai_extraction',
        ]);
    }

    public function test_nba_blocks_present_offer_without_decision_maker_on_b2b_playbook(): void
    {
        $company = $this->createTenantCompany(['name' => 'NBA Co', 'slug' => 'nba-co']);
        AiFeatureFlags::enable(AiFeatureFlags::STAKEHOLDERS, $company->id);
        AiFeatureFlags::enable(AiFeatureFlags::SALES_STATE, $company->id);

        SalesPlaybook::query()->create([
            'company_id' => $company->id,
            'slug' => 'b2b_equipment',
            'name' => 'B2B Equipment',
            'qualification_fields' => ['budget', 'requirements', 'decision_maker'],
            'is_active' => true,
        ]);

        $contact = Contact::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'sales_state' => [
                'qualified' => true,
                'budget_known' => true,
                'requirements_known' => true,
                'timeline_known' => true,
                'next_action' => ChatSalesStateService::NA_PRESENT_OFFER,
                'decision_maker_known' => false,
            ],
            'sales_state_updated_at' => now(),
        ]);

        $nba = app(NextBestActionEngine::class)->compute($chat);

        $this->assertSame(ChatSalesStateService::NA_QUALIFY, $nba['next_best_action']);
    }
}
