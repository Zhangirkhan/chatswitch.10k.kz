<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\AiFollowUpProposal;
use App\Models\Chat;
use App\Models\CompanyToneProfile;
use App\Models\FunnelStageAiRule;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\KnowledgeContextRepository;
use App\Services\AI\OpenAiChatService;
use App\Services\OutboundChatMessageDispatcher;
use App\Services\Promotion\CompanyPromotionCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

final class ConsultationFollowUpProposalService
{
    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly KnowledgeContextRepository $knowledge,
        private readonly ConsultationFollowUpEligibilityService $eligibility,
        private readonly OutboundChatMessageDispatcher $outboundDispatcher,
        private readonly CompanyPromotionCatalog $promotionCatalog,
    ) {}

    public function scheduleDue(int $limit = 40): int
    {
        $dispatched = 0;
        $rules = FunnelStageAiRule::query()
            ->where('follow_up_strategy', FunnelStageAiRule::FOLLOW_UP_STRATEGY_MANAGER_PROPOSALS)
            ->with(['stage:id,funnel_id,name', 'funnel:id'])
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if ($dispatched >= $limit || $rule->stage === null) {
                break;
            }

            $delayHours = max(1, (int) $rule->follow_up_delay_hours);
            $threshold = now()->subHours($delayHours);
            $remaining = $limit - $dispatched;

            $this->eligibility
                ->eligibleChatsQuery($rule, $threshold)
                ->orderBy('last_message_at')
                ->limit($remaining)
                ->pluck('id')
                ->each(function (int $chatId) use ($rule, &$dispatched): void {
                    if ($this->proposeForChatId($chatId, $rule) !== null) {
                        $dispatched++;
                    }
                });
        }

        if ($dispatched > 0) {
            Log::info('[follow-up-proposal] scheduled', ['count' => $dispatched]);
        }

        return $dispatched;
    }

    public function proposeForChat(Chat $chat, ?User $requestedBy = null): ?AiFollowUpProposal
    {
        $chat->loadMissing(['funnelStage.aiRule', 'funnel']);

        $rule = $chat->funnelStage?->aiRule;
        if (! $rule instanceof FunnelStageAiRule) {
            return null;
        }

        if ((string) ($rule->follow_up_strategy ?? '') !== FunnelStageAiRule::FOLLOW_UP_STRATEGY_MANAGER_PROPOSALS) {
            return null;
        }

        return $this->proposeForChatId((int) $chat->id, $rule, $requestedBy);
    }

    public function proposeForChatId(int $chatId, FunnelStageAiRule $rule, ?User $requestedBy = null): ?AiFollowUpProposal
    {
        $rule->loadMissing('stage');

        $chat = Chat::query()
            ->with(['whatsappSession', 'funnelStage', 'funnel'])
            ->whereKey($chatId)
            ->first();

        if ($chat === null || $chat->is_group || ! $chat->funnel_tracking_enabled) {
            return null;
        }

        $existing = AiFollowUpProposal::query()
            ->where('chat_id', $chat->id)
            ->where('funnel_stage_id', $rule->funnel_stage_id)
            ->where('status', AiFollowUpProposal::STATUS_NEEDS_MANAGER)
            ->first();

        if ($existing instanceof AiFollowUpProposal) {
            return $existing;
        }

        $trigger = $this->resolveTriggerMessage($chat, $rule);
        if ($trigger === null) {
            return null;
        }

        $record = AiFollowUpProposal::query()->create([
            'company_id' => $chat->company_id ?? $rule->company_id,
            'chat_id' => $chat->id,
            'funnel_id' => $chat->funnel_id,
            'funnel_stage_id' => $rule->funnel_stage_id,
            'trigger_message_id' => $trigger->id,
            'status' => AiFollowUpProposal::STATUS_PENDING,
            'created_by_user_id' => $requestedBy?->id,
        ]);

        try {
            $parsed = $this->generateProposalPayload($rule, $chat);
            $record->forceFill([
                'status' => AiFollowUpProposal::STATUS_NEEDS_MANAGER,
                'proposals' => $parsed['proposals'],
                'recommended_id' => $parsed['recommended_id'],
                'manager_note' => $parsed['manager_note'],
                'context_summary' => $parsed['context_summary'],
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            Log::warning('[follow-up-proposal] generation failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);
            $record->forceFill([
                'status' => AiFollowUpProposal::STATUS_FAILED,
                'error' => Str::limit($e->getMessage(), 500, ''),
            ])->save();

            return null;
        }

        return $record->fresh();
    }

    public function dismiss(AiFollowUpProposal $proposal): void
    {
        if ($proposal->status !== AiFollowUpProposal::STATUS_NEEDS_MANAGER) {
            return;
        }

        $proposal->forceFill([
            'status' => AiFollowUpProposal::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ])->save();
    }

    public function dismissPendingForChat(Chat $chat, ?int $stageId = null): int
    {
        $query = AiFollowUpProposal::query()
            ->where('chat_id', $chat->id)
            ->where('status', AiFollowUpProposal::STATUS_NEEDS_MANAGER);

        if ($stageId !== null) {
            $query->where('funnel_stage_id', $stageId);
        }

        return $query->update([
            'status' => AiFollowUpProposal::STATUS_DISMISSED,
            'dismissed_at' => now(),
        ]);
    }

    /**
     * @return array{message: \App\Models\Message, proposal: AiFollowUpProposal}
     */
    public function sendVariant(
        AiFollowUpProposal $proposal,
        User $sender,
        string $variantId,
        ?string $bodyOverride = null,
        ?Carbon $scheduleAt = null,
    ): array {
        if ($proposal->status !== AiFollowUpProposal::STATUS_NEEDS_MANAGER) {
            throw new \InvalidArgumentException('Предложение дожима уже обработано.');
        }

        $chat = Chat::query()->whereKey($proposal->chat_id)->first();
        if ($chat === null) {
            throw new \InvalidArgumentException('Чат не найден.');
        }

        $body = trim($bodyOverride ?? '');
        if ($body === '') {
            $body = $this->resolveVariantBody($proposal, $variantId);
        }

        if ($body === '') {
            throw new \InvalidArgumentException('Текст сообщения пустой.');
        }

        if ($scheduleAt !== null && $scheduleAt->greaterThan(now()->addSeconds(20))) {
            $session = $chat->whatsappSession;
            if ($session === null) {
                throw new \InvalidArgumentException('У чата нет WhatsApp-сессии.');
            }

            \App\Models\ScheduledMessage::query()->create([
                'chat_id' => $chat->id,
                'whatsapp_session_id' => $session->id,
                'user_id' => $sender->id,
                'body' => $body,
                'display_body' => $body,
                'scheduled_at' => $scheduleAt,
                'status' => \App\Models\ScheduledMessage::STATUS_PENDING,
                'error' => null,
            ]);

            $proposal->forceFill([
                'status' => AiFollowUpProposal::STATUS_SENT,
                'selected_variant_id' => $variantId,
                'sent_at' => now(),
            ])->save();

            return [
                'message' => new Message,
                'proposal' => $proposal->fresh() ?? $proposal,
            ];
        }

        $result = $this->outboundDispatcher->sendTextMessage($sender, $chat, [
            'message' => $body,
            'display_message' => $body,
        ]);

        $proposal->forceFill([
            'status' => AiFollowUpProposal::STATUS_SENT,
            'selected_variant_id' => $variantId,
            'sent_message_id' => $result->message->id,
            'sent_at' => now(),
        ])->save();

        return [
            'message' => $result->message,
            'proposal' => $proposal->fresh() ?? $proposal,
        ];
    }

    private function resolveVariantBody(AiFollowUpProposal $proposal, string $variantId): string
    {
        $proposals = is_array($proposal->proposals) ? $proposal->proposals : [];
        foreach ($proposals as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ((string) ($item['id'] ?? '') === $variantId) {
                return trim((string) ($item['body'] ?? ''));
            }
        }

        return '';
    }

    private function resolveTriggerMessage(Chat $chat, FunnelStageAiRule $rule): ?Message
    {
        $silenceAfter = (string) ($rule->follow_up_silence_after ?? FunnelStageAiRule::FOLLOW_UP_SILENCE_OUTBOUND);
        $direction = $silenceAfter === FunnelStageAiRule::FOLLOW_UP_SILENCE_INBOUND ? 'inbound' : 'outbound';

        return Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', $direction)
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array{
     *     proposals: list<array{id: string, label: string, body: string, uses_promo: bool, promo_ref: string|null}>,
     *     recommended_id: string,
     *     manager_note: string,
     *     context_summary: string
     * }
     */
    private function generateProposalPayload(FunnelStageAiRule $rule, Chat $chat): array
    {
        $name = trim((string) ($chat->chat_name ?? ''));
        if ($name === '') {
            $name = 'клиент';
        }

        $stageName = (string) ($rule->stage?->name ?? 'этап');
        $goal = trim((string) ($rule->goal ?? ''));
        $promoBlock = $this->promotionCatalog->formatPromptBlock(
            $this->promotionCatalog->promptItemsForRule($rule),
            'Активные акции отключены или не заданы — варианты без промо.',
        );
        $hasPromo = $promoBlock !== 'Активные акции отключены или не заданы — варианты без промо.';

        $promoInstruction = $hasPromo
            ? "Разрешённые акции:\n{$promoBlock}\n\nЕсли акции уместны — включи хотя бы один вариант с uses_promo=true и корректным promo_ref."
            : $promoBlock;

        $toneHint = $this->toneHint((int) ($rule->company_id ?? $chat->company_id ?? 0));
        $knowledge = $this->knowledgeSummary((int) ($rule->company_id ?? $chat->company_id ?? 0));

        $history = Message::query()
            ->where('chat_id', $chat->id)
            ->whereNotNull('body')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit(12)
            ->get(['direction', 'body'])
            ->reverse()
            ->map(fn (Message $m): string => ($m->direction === 'inbound' ? 'Клиент' : 'Мы').': '.Str::limit(trim((string) $m->body), 300, '…'))
            ->implode("\n");

        $prompt = <<<PROMPT
Ты помогаешь менеджеру с дожимом клиента после консультации/КП в WhatsApp.

Клиент: {$name}
Этап воронки: {$stageName}
Цель этапа: {$goal}
{$toneHint}
{$knowledge}

{$promoInstruction}

Последние сообщения:
{$history}

Верни ТОЛЬКО JSON без markdown:
{
  "proposals": [
    {"id": "soft", "label": "краткое название", "body": "текст сообщения клиенту", "uses_promo": false, "promo_ref": null},
    {"id": "value", "label": "...", "body": "...", "uses_promo": false, "promo_ref": null},
    {"id": "promo", "label": "...", "body": "...", "uses_promo": true, "promo_ref": "id из разрешённых или null"}
  ],
  "recommended_id": "soft",
  "manager_note": "1-3 предложения для менеджера: почему молчит, на что опереться",
  "context_summary": "1 предложение контекста"
}

Требования:
- Ровно 2–3 варианта в proposals
- body: 1–4 предложения, без markdown, на языке клиента из переписки (русский или казахский)
- Не выдумывай цены, сроки и скидки вне разрешённых акций
- uses_promo=true только если promo_ref соответствует id из списка акций
- Если есть активные акции — обязательно включи хотя бы один вариант с uses_promo=true
PROMPT;

        $raw = trim($this->openAi->chat([
            ['role' => 'system', 'content' => 'Ты готовишь варианты follow-up для менеджера. Отвечай только JSON.'],
            ['role' => 'user', 'content' => $prompt],
        ], 0.4, 1200, new \App\Services\AI\AiUsageOptions('follow_up_proposal', (int) ($rule->company_id ?? $chat->company_id))));

        return $this->parseAiJson($raw, $name, $hasPromo);
    }

    /**
     * @return array{
     *     proposals: list<array{id: string, label: string, body: string, uses_promo: bool, promo_ref: string|null}>,
     *     recommended_id: string,
     *     manager_note: string,
     *     context_summary: string
     * }
     */
    private function parseAiJson(string $raw, string $clientName, bool $hasPromo): array
    {
        $json = $raw;
        if (preg_match('/\{[\s\S]*\}/', $raw, $match)) {
            $json = $match[0];
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->fallbackPayload($clientName, $hasPromo);
        }

        $proposals = [];
        foreach ((array) ($data['proposals'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $body = trim((string) ($item['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            $proposals[] = [
                'id' => (string) ($item['id'] ?? 'variant_'.(count($proposals) + 1)),
                'label' => (string) ($item['label'] ?? 'Вариант'),
                'body' => Str::limit($body, 4000, ''),
                'uses_promo' => (bool) ($item['uses_promo'] ?? false),
                'promo_ref' => isset($item['promo_ref']) ? (string) $item['promo_ref'] : null,
            ];
        }

        if ($proposals === []) {
            return $this->fallbackPayload($clientName, $hasPromo);
        }

        $recommended = (string) ($data['recommended_id'] ?? $proposals[0]['id']);
        $ids = array_column($proposals, 'id');
        if (! in_array($recommended, $ids, true)) {
            $recommended = $proposals[0]['id'];
        }

        return [
            'proposals' => array_slice($proposals, 0, 3),
            'recommended_id' => $recommended,
            'manager_note' => Str::limit(trim((string) ($data['manager_note'] ?? '')), 2000, ''),
            'context_summary' => Str::limit(trim((string) ($data['context_summary'] ?? '')), 500, ''),
        ];
    }

    /**
     * @return array{
     *     proposals: list<array{id: string, label: string, body: string, uses_promo: bool, promo_ref: string|null}>,
     *     recommended_id: string,
     *     manager_note: string,
     *     context_summary: string
     * }
     */
    private function fallbackPayload(string $clientName, bool $hasPromo): array
    {
        $proposals = [
            [
                'id' => 'soft',
                'label' => 'Мягкое напоминание',
                'body' => "Здравствуйте, {$clientName}! Вы ещё рассматриваете наше предложение? Если остались вопросы — напишите, будем рады помочь.",
                'uses_promo' => false,
                'promo_ref' => null,
            ],
            [
                'id' => 'value',
                'label' => 'С уточнением',
                'body' => "Добрый день, {$clientName}! Подскажите, пожалуйста, остались ли вопросы по предложению — можем уточнить детали.",
                'uses_promo' => false,
                'promo_ref' => null,
            ],
        ];

        if ($hasPromo) {
            $proposals[] = [
                'id' => 'promo',
                'label' => 'Со скидкой',
                'body' => "Здравствуйте, {$clientName}! Есть специальное предложение по вашему запросу — напишите, расскажем условия.",
                'uses_promo' => true,
                'promo_ref' => null,
            ];
        }

        return [
            'proposals' => $proposals,
            'recommended_id' => 'soft',
            'manager_note' => 'Клиент не ответил после вашего сообщения. Выберите подходящий вариант или отредактируйте текст.',
            'context_summary' => 'Автоматический черновик (AI недоступен).',
        ];
    }

    private function toneHint(int $companyId): string
    {
        if ($companyId <= 0) {
            return 'Стиль: нейтральный, вежливый.';
        }

        $profile = CompanyToneProfile::query()->where('company_id', $companyId)->first();
        if ($profile === null) {
            return 'Стиль: нейтральный, вежливый.';
        }

        $summary = $profile->use_manual_override && trim((string) $profile->manual_summary) !== ''
            ? trim((string) $profile->manual_summary)
            : trim((string) $profile->summary);

        return $summary !== '' ? 'Стиль компании: '.$summary : 'Стиль: нейтральный, вежливый.';
    }

    private function knowledgeSummary(int $companyId): string
    {
        if ($companyId <= 0) {
            return '';
        }

        $ctx = $this->knowledge->forPrompt($companyId);
        $rules = array_slice($ctx['rules'], 0, 8);
        if ($rules === []) {
            return '';
        }

        $lines = array_map(
            static fn ($rule): string => '- '.Str::limit(trim((string) $rule->title.': '.(string) $rule->content), 200, '…'),
            $rules,
        );

        return "Правила из базы знаний:\n".implode("\n", $lines);
    }
}
