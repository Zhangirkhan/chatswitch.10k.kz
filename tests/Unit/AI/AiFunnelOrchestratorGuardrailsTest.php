<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\AiOrchestratorRun;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Message;
use App\Models\Product;
use App\Models\WhatsappSession;
use App\Services\AI\AiFunnelOrchestratorPlan;
use App\Services\AI\AiFunnelOrchestratorService;
use App\Support\TenantCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

final class AiFunnelOrchestratorGuardrailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_inquiry_replaces_repeated_clarifying_question_with_product_list(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Product::query()->create([
            'company_id' => $company->id,
            'name' => 'Кухня «Модерн»',
            'price' => 450000,
            'is_active' => true,
            'include_in_prompt' => true,
            'sort_order' => 0,
        ]);

        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'а что есть',
            'message_timestamp' => now(),
        ]);

        $plan = new AiFunnelOrchestratorPlan(
            customerReply: 'Подскажите, какое изделие или категория вас интересует?',
            targetFunnelStageId: null,
            appointment: null,
            assigneeUserId: null,
            managerNote: null,
            task: null,
            requiresManagerAttention: true,
            confidence: 0.55,
            reason: 'test',
        );

        $normalized = $this->invokeNormalizeCatalogInquiry($chat, $trigger, $plan);

        $this->assertStringContainsString('Кухня', (string) $normalized->customerReply);
        $this->assertFalse($normalized->requiresManagerAttention);
        $this->assertNull($normalized->managerNote);
    }

    public function test_clarifying_and_catalog_signatures_are_not_treated_as_same_topic(): void
    {
        $method = new ReflectionMethod(AiFunnelOrchestratorService::class, 'sameMissingDataQuestion');
        $method->setAccessible(true);

        $service = app(AiFunnelOrchestratorService::class);

        $clarify = 'именно хотите купить уточните изделие категорию';
        $catalog = 'что есть каталог наличии варианты';

        $this->assertFalse($method->invoke($service, $clarify, $catalog));
        $this->assertFalse($method->invoke($service, $catalog, $clarify));
    }

    public function test_low_confidence_catalog_inquiry_returns_product_list(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Product::query()->create([
            'company_id' => $company->id,
            'name' => 'Шкаф-купе',
            'price' => 120000,
            'is_active' => true,
            'include_in_prompt' => true,
            'sort_order' => 0,
        ]);

        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'здравствуйте какие товары в наличии есть?',
            'message_timestamp' => now(),
        ]);

        $blocked = new AiFunnelOrchestratorPlan(
            customerReply: null,
            targetFunnelStageId: null,
            appointment: null,
            assigneeUserId: null,
            managerNote: 'stop',
            task: ['title' => 'x', 'body' => 'y'],
            requiresManagerAttention: true,
            confidence: 0.55,
            reason: 'AI остановлен: обнаружен повтор похожего уточняющего вопроса.',
        );

        $method = new ReflectionMethod(AiFunnelOrchestratorService::class, 'lowConfidencePlan');
        $method->setAccessible(true);

        $plan = $method->invoke(
            app(AiFunnelOrchestratorService::class),
            $chat,
            $trigger,
            null,
            $blocked,
            0.7,
        );

        $this->assertStringContainsString('Шкаф', (string) $plan->customerReply);
        $this->assertFalse($plan->requiresManagerAttention);
    }

    public function test_catalog_inquiry_run_can_retry_after_repeat_stop(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        $method = new ReflectionMethod(AiFunnelOrchestratorService::class, 'shouldRetryCatalogInquiryRun');
        $method->setAccessible(true);

        $run = new AiOrchestratorRun([
            'status' => AiOrchestratorRun::STATUS_NEEDS_MANAGER,
            'reason' => 'AI остановлен: обнаружен повтор похожего уточняющего вопроса.',
        ]);
        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'а что есть',
            'message_timestamp' => now(),
        ]);

        $this->assertTrue($method->invoke(app(AiFunnelOrchestratorService::class), $chat, $run, $trigger));
    }

    public function test_echo_greeting_gets_catalog_not_stub(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Product::query()->create([
            'company_id' => $company->id,
            'name' => 'Кухня',
            'price' => 300000,
            'is_active' => true,
            'include_in_prompt' => true,
            'sort_order' => 0,
        ]);

        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'здравствуйте!',
            'message_timestamp' => now(),
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'а что есть',
            'message_timestamp' => now()->subMinute(),
        ]);

        $echoPlan = new AiFunnelOrchestratorPlan(
            customerReply: 'здравствуйте',
            targetFunnelStageId: null,
            appointment: null,
            assigneeUserId: null,
            managerNote: null,
            task: null,
            requiresManagerAttention: false,
            confidence: 0.8,
            reason: 'test',
        );

        $method = new ReflectionMethod(AiFunnelOrchestratorService::class, 'normalizeEchoReply');
        $method->setAccessible(true);

        $plan = $method->invoke(app(AiFunnelOrchestratorService::class), $chat, $trigger, $echoPlan);

        $this->assertStringContainsString('Кухня', (string) $plan->customerReply);
        $this->assertStringNotContainsString('уточню информацию и вернусь', mb_strtolower((string) $plan->customerReply));
    }

    public function test_repeated_clarifying_question_returns_catalog_when_products_exist(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Product::query()->create([
            'company_id' => $company->id,
            'name' => 'Шкаф',
            'is_active' => true,
            'include_in_prompt' => true,
            'sort_order' => 0,
        ]);

        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'а что есть',
            'message_timestamp' => now(),
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Что именно хотите купить? Уточните изделие.',
            'message_timestamp' => now()->subMinute(),
            'metadata' => ['ai' => ['generated' => true]],
        ]);

        $repeatPlan = new AiFunnelOrchestratorPlan(
            customerReply: 'Подскажите, какое изделие вас интересует?',
            targetFunnelStageId: null,
            appointment: null,
            assigneeUserId: null,
            managerNote: null,
            task: null,
            requiresManagerAttention: false,
            confidence: 0.85,
            reason: 'test',
        );

        $method = new ReflectionMethod(AiFunnelOrchestratorService::class, 'normalizeRepeatedQuestion');
        $method->setAccessible(true);

        $plan = $method->invoke(app(AiFunnelOrchestratorService::class), $chat, $trigger, $repeatPlan);

        $this->assertStringContainsString('Шкаф', (string) $plan->customerReply);
        $this->assertFalse($plan->requiresManagerAttention);
    }

    public function test_first_reply_echoes_client_greeting(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'здравствуйте хочу что то купить',
            'message_timestamp' => now(),
        ]);

        $plan = new AiFunnelOrchestratorPlan(
            customerReply: 'Что именно хотите купить?',
            targetFunnelStageId: null,
            appointment: null,
            assigneeUserId: null,
            managerNote: null,
            task: null,
            requiresManagerAttention: false,
            confidence: 0.9,
            reason: 'test',
        );

        $normalized = $this->invokeNormalizeClientGreeting($chat, $trigger, $plan);

        $this->assertStringStartsWith('Здравствуйте!', (string) $normalized->customerReply);
    }

    private function invokeNormalizeCatalogInquiry(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        $method = new ReflectionMethod(AiFunnelOrchestratorService::class, 'normalizeCatalogInquiry');
        $method->setAccessible(true);

        return $method->invoke(app(AiFunnelOrchestratorService::class), $chat, $trigger, $plan);
    }

    private function invokeNormalizeClientGreeting(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        $method = new ReflectionMethod(AiFunnelOrchestratorService::class, 'normalizeClientGreeting');
        $method->setAccessible(true);

        return $method->invoke(app(AiFunnelOrchestratorService::class), $chat, $trigger, $plan);
    }
}
