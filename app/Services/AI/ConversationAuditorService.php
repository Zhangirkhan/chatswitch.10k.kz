<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\ConversationAudit;
use App\Models\Message;
use App\Support\AiFeatureFlags;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ConversationAuditorService
{
    public function __construct(
        private readonly ChatSalesStateService $salesStateService,
        private readonly OpenAiChatService $openAi,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function audit(Chat $chat, ?int $triggerMessageId = null): ?array
    {
        if (! AiFeatureFlags::enabled(AiFeatureFlags::SALES_STATE, (int) $chat->company_id)
            || ! Schema::hasTable('conversation_audits')
        ) {
            return null;
        }

        $state = $this->salesStateService->freshState($chat);
        $messages = Message::query()
            ->where('chat_id', $chat->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->orderByDesc('message_timestamp')
            ->limit(12)
            ->get(['direction', 'body', 'message_timestamp'])
            ->reverse()
            ->values();

        $transcript = $messages->map(static function (Message $m): string {
            $role = $m->direction === 'inbound' ? 'Клиент' : 'Менеджер/AI';

            return "{$role}: ".mb_substr(trim((string) $m->body), 0, 300);
        })->implode("\n");

        $system = <<<'PROMPT'
Ты — аудитор качества AI-продаж. Оцени последний фрагмент диалога.
Верни ТОЛЬКО JSON без markdown:
{
  "sales_score": 0-100,
  "conversation_quality": "good|fair|poor",
  "missed_questions": ["..."],
  "missed_opportunities": ["..."],
  "qualification_quality": "complete|partial|weak",
  "risk_level": "low|medium|high"
}
PROMPT;

        $user = "Sales state:\n".json_encode($state, JSON_UNESCAPED_UNICODE)
            ."\n\nДиалог:\n".$transcript;

        try {
            $response = $this->openAi->chatJson(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                new AiUsageOptions('conversation_audit', (int) $chat->company_id),
            );
        } catch (Throwable $e) {
            Log::warning('[conversation-audit] failed', [
                'chat_id' => $chat->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! is_array($response)) {
            return null;
        }

        $audit = ConversationAudit::query()->create([
            'company_id' => (int) $chat->company_id,
            'chat_id' => (int) $chat->id,
            'trigger_message_id' => $triggerMessageId,
            'sales_score' => isset($response['sales_score']) ? (int) $response['sales_score'] : null,
            'conversation_quality' => isset($response['conversation_quality']) ? (string) $response['conversation_quality'] : null,
            'missed_questions' => is_array($response['missed_questions'] ?? null) ? $response['missed_questions'] : [],
            'missed_opportunities' => is_array($response['missed_opportunities'] ?? null) ? $response['missed_opportunities'] : [],
            'qualification_quality' => isset($response['qualification_quality']) ? (string) $response['qualification_quality'] : null,
            'risk_level' => isset($response['risk_level']) ? (string) $response['risk_level'] : null,
            'raw_response' => $response,
            'model' => config('services.openai.model', 'gpt-4o-mini'),
        ]);

        return [
            'id' => $audit->id,
            'sales_score' => $audit->sales_score,
            'conversation_quality' => $audit->conversation_quality,
            'missed_questions' => $audit->missed_questions,
            'missed_opportunities' => $audit->missed_opportunities,
            'qualification_quality' => $audit->qualification_quality,
            'risk_level' => $audit->risk_level,
        ];
    }
}
