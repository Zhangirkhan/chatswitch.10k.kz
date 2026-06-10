<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Models\AiOrchestratorRun;
use App\Models\CalendarEvent;
use App\Models\Chat;
use App\Models\Company;
use App\Models\Funnel;
use App\Models\FunnelAiScenario;
use App\Models\FunnelStage;
use App\Models\Message;
use App\Models\Product;
use App\Models\WhatsappSession;
use App\Services\AI\AiFunnelOrchestratorPlan;
use App\Services\AI\AiFunnelOrchestratorService;
use App\Support\FunnelStageType;
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

    public function test_reschedule_skips_manager_confirmation_and_updates_reply(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        CalendarEvent::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'user_id' => 1,
            'title' => 'Замер',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(3),
            'source' => CalendarEvent::SOURCE_AI_AUTO,
        ]);

        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'извиняюсь, можно на 17:20 сегодня? перезаписаться',
            'message_timestamp' => now(),
        ]);

        $startsAt = now()->setTime(17, 20)->toIso8601String();
        $plan = new AiFunnelOrchestratorPlan(
            customerReply: 'Записываю вас на замер сегодня в 18:00.',
            targetFunnelStageId: null,
            appointment: [
                'service_name' => 'Замер',
                'starts_at' => $startsAt,
                'duration_minutes' => 60,
            ],
            assigneeUserId: null,
            managerNote: 'Нужен менеджер',
            task: ['title' => 'Подтвердить', 'body' => 'test'],
            requiresManagerAttention: true,
            confidence: 0.9,
            reason: 'test',
        );

        $scenario = new FunnelAiScenario([
            'manager_confirmation_required' => true,
            'booking_horizon_days' => 30,
        ]);

        $service = app(AiFunnelOrchestratorService::class);
        $normalize = new ReflectionMethod(AiFunnelOrchestratorService::class, 'normalizeReschedule');
        $normalize->setAccessible(true);
        $normalized = $normalize->invoke($service, $chat, $trigger, $plan);

        $this->assertFalse($normalized->requiresManagerAttention);
        $this->assertNull($normalized->managerNote);

        $applyReply = new ReflectionMethod(AiFunnelOrchestratorService::class, 'applyAppointmentCustomerReply');
        $applyReply->setAccessible(true);
        $withReply = $applyReply->invoke($service, $chat, $trigger, $normalized);

        $this->assertStringContainsString('Переношу', (string) $withReply->customerReply);
        $this->assertStringContainsString('17:20', (string) $withReply->customerReply);

        $policy = new ReflectionMethod(AiFunnelOrchestratorService::class, 'applyAutomationPolicy');
        $policy->setAccessible(true);
        $afterPolicy = $policy->invoke($service, $chat, $trigger, $scenario, null, $withReply);

        $this->assertNotNull($afterPolicy->appointment);
        $this->assertStringContainsString('Переношу', (string) $afterPolicy->customerReply);
    }

    public function test_reschedule_reply_explains_when_requested_slot_is_unavailable(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        CalendarEvent::query()->create([
            'company_id' => $company->id,
            'chat_id' => $chat->id,
            'user_id' => 1,
            'title' => 'Замер',
            'starts_at' => now()->addHours(2),
            'ends_at' => now()->addHours(3),
            'source' => CalendarEvent::SOURCE_AI_AUTO,
        ]);

        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'можно на 17:20 сегодня? перезаписаться',
            'message_timestamp' => now(),
        ]);

        $plan = new AiFunnelOrchestratorPlan(
            customerReply: 'Переношу на 18:00.',
            targetFunnelStageId: null,
            appointment: [
                'service_name' => 'Замер',
                'starts_at' => now()->setTime(18, 0)->toIso8601String(),
                'duration_minutes' => 60,
            ],
            assigneeUserId: null,
            managerNote: null,
            task: null,
            requiresManagerAttention: false,
            confidence: 0.9,
            reason: 'test',
        );

        $service = app(AiFunnelOrchestratorService::class);
        $method = new ReflectionMethod(AiFunnelOrchestratorService::class, 'applyAppointmentCustomerReply');
        $method->setAccessible(true);
        $result = $method->invoke($service, $chat, $trigger, $plan);

        $reply = (string) $result->customerReply;
        $this->assertStringContainsString('17:20', $reply);
        $this->assertStringContainsString('18:00', $reply);
        $this->assertStringContainsString('занят', mb_strtolower($reply));
        $this->assertStringContainsString('Переношу', $reply);
    }

    public function test_booking_reply_explains_when_requested_slot_is_unavailable(): void
    {
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $session->company_id,
            'whatsapp_session_id' => $session->id,
        ]);

        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'можно на 17:20 сегодня?',
            'message_timestamp' => now(),
        ]);

        $plan = new AiFunnelOrchestratorPlan(
            customerReply: 'Записываю вас на замер сегодня в 18:00.',
            targetFunnelStageId: null,
            appointment: [
                'service_name' => 'Замер',
                'starts_at' => now()->setTime(18, 0)->toIso8601String(),
                'duration_minutes' => 60,
            ],
            assigneeUserId: null,
            managerNote: null,
            task: null,
            requiresManagerAttention: false,
            confidence: 0.9,
            reason: 'test',
        );

        $service = app(AiFunnelOrchestratorService::class);
        $method = new ReflectionMethod(AiFunnelOrchestratorService::class, 'applyAppointmentCustomerReply');
        $method->setAccessible(true);
        $result = $method->invoke($service, $chat, $trigger, $plan);

        $reply = (string) $result->customerReply;
        $this->assertStringContainsString('17:20', $reply);
        $this->assertStringContainsString('18:00', $reply);
        $this->assertTrue(
            str_contains(mb_strtolower($reply), 'занят') || str_contains(mb_strtolower($reply), 'свободного окна нет'),
        );
        $this->assertStringContainsString('ближайш', mb_strtolower($reply));
    }

    public function test_kazakh_time_question_after_catalog_inquiry_does_not_get_catalog_reply(): void
    {
        $company = Company::query()->findOrFail(TenantCompany::id());
        $session = WhatsappSession::factory()->create();
        $chat = Chat::factory()->create([
            'company_id' => $company->id,
            'whatsapp_session_id' => $session->id,
        ]);

        Product::query()->create([
            'company_id' => $company->id,
            'name' => 'Индивидуальный заказ',
            'price' => 100000,
            'is_active' => true,
            'include_in_prompt' => true,
            'sort_order' => 0,
        ]);

        Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Здравствуйте какие услуги есть',
            'message_timestamp' => now()->subMinute(),
        ]);

        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Қанша уақыт',
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

        $service = app(AiFunnelOrchestratorService::class);

        $shouldOfferCatalog = new ReflectionMethod(AiFunnelOrchestratorService::class, 'shouldOfferCatalog');
        $shouldOfferCatalog->setAccessible(true);
        $this->assertFalse($shouldOfferCatalog->invoke($service, $chat, $trigger));

        $finalize = new ReflectionMethod(AiFunnelOrchestratorService::class, 'finalizeCustomerFacingPlan');
        $finalize->setAccessible(true);

        $finalPlan = $finalize->invoke($service, $chat, $trigger, $blocked);

        $this->assertStringNotContainsString('в каталоге', mb_strtolower((string) $finalPlan->customerReply));
        $this->assertStringContainsString('мерзім', mb_strtolower((string) $finalPlan->customerReply));
        $this->assertFalse($finalPlan->requiresManagerAttention);
    }

    public function test_normalize_payment_stage_bypass_advances_to_production_without_requisites_task(): void
    {
        config(['funnel.payment_stages_required' => false]);

        $company = Company::query()->findOrFail(TenantCompany::id());
        $funnel = Funnel::query()->create([
            'company_id' => $company->id,
            'name' => 'Payment bypass guardrail funnel',
            'is_active' => true,
            'position' => 1,
        ]);

        $paymentStage = FunnelStage::query()->create([
            'funnel_id' => $funnel->id,
            'name' => 'Ожидание предоплаты',
            'stage_type' => FunnelStageType::PAYMENT,
            'position' => 4,
            'is_active' => true,
        ]);
        $productionStage = FunnelStage::query()->create([
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
            'funnel_stage_id' => $paymentStage->id,
        ]);
        $chat->load(['funnel.stages', 'funnelStage']);

        $trigger = Message::query()->create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'direction' => 'inbound',
            'type' => 'text',
            'body' => 'Хорошо, согласен',
            'message_timestamp' => now(),
        ]);

        $plan = new AiFunnelOrchestratorPlan(
            customerReply: 'Нужны реквизиты для оплаты?',
            targetFunnelStageId: null,
            appointment: null,
            assigneeUserId: null,
            managerNote: null,
            task: [
                'title' => 'Отправить реквизиты клиенту',
                'body' => 'Клиент ждёт реквизиты.',
            ],
            requiresManagerAttention: false,
            confidence: 0.8,
            reason: 'test',
        );

        $normalized = $this->invokeNormalizePaymentStage($chat, $trigger, $plan);

        $this->assertSame((int) $productionStage->id, $normalized->targetFunnelStageId);
        $this->assertNull($normalized->task);
        $this->assertStringContainsString('работу', mb_strtolower((string) $normalized->customerReply));
    }

    private function invokeNormalizePaymentStage(Chat $chat, Message $trigger, AiFunnelOrchestratorPlan $plan): AiFunnelOrchestratorPlan
    {
        $method = new ReflectionMethod(AiFunnelOrchestratorService::class, 'normalizePaymentStage');
        $method->setAccessible(true);

        return $method->invoke(app(AiFunnelOrchestratorService::class), $chat, $trigger, $plan);
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
