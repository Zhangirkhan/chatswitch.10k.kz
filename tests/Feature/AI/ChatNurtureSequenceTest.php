<?php

declare(strict_types=1);

namespace Tests\Feature\AI;

use App\Models\Chat;
use App\Models\ChatNurtureSequence;
use App\Models\Company;
use App\Models\Message;
use App\Models\ScheduledMessage;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\ChatNurtureSequenceService;
use App\Services\AI\ChatSalesStateService;
use App\Support\AiFeatureFlags;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class ChatNurtureSequenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('employee');
        TenantCompany::ensureExists();
    }

    public function test_deferral_starts_three_scheduled_messages(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        AiFeatureFlags::enable(AiFeatureFlags::NURTURE_FOLLOW_UP, $company->id);

        $operator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $session = WhatsappSession::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'ai_responder_user_id' => $operator->id,
        ]);

        $message = Message::query()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'подумаем',
            'message_timestamp' => now(),
        ]);

        $sequence = app(ChatNurtureSequenceService::class)->startFromDeferral($chat, $message);

        $this->assertInstanceOf(ChatNurtureSequence::class, $sequence);
        $this->assertSame(3, ScheduledMessage::query()
            ->where('chat_id', $chat->id)
            ->where('purpose', ScheduledMessage::PURPOSE_NURTURE_FOLLOW_UP)
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->count());
    }

    public function test_re_engagement_cancels_pending_nurture(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        AiFeatureFlags::enable(AiFeatureFlags::NURTURE_FOLLOW_UP, $company->id);

        $operator = User::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $session = WhatsappSession::factory()->create(['company_id' => $company->id]);
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
            'ai_enabled' => true,
            'ai_responder_user_id' => $operator->id,
            'sales_state' => [
                'deferral_detected' => true,
                'next_action' => 'follow_up',
            ],
        ]);

        $deferMsg = Message::query()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'позже',
            'message_timestamp' => now(),
        ]);
        app(ChatNurtureSequenceService::class)->startFromDeferral($chat, $deferMsg);

        $reengage = Message::query()->create([
            'chat_id' => $chat->id,
            'direction' => 'inbound',
            'body' => 'а сколько стоит диван?',
            'message_timestamp' => now(),
        ]);

        app(ChatSalesStateService::class)->clearDeferralFromMessage($chat->fresh(), $reengage);

        $this->assertSame(0, ScheduledMessage::query()
            ->where('chat_id', $chat->id)
            ->where('purpose', ScheduledMessage::PURPOSE_NURTURE_FOLLOW_UP)
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->count());

        $this->assertSame(
            ChatNurtureSequence::STATUS_CANCELLED,
            ChatNurtureSequence::query()->where('chat_id', $chat->id)->value('status'),
        );
    }
}
