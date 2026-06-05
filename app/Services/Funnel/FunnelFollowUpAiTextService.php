<?php

declare(strict_types=1);

namespace App\Services\Funnel;

use App\Models\Chat;
use App\Models\CompanyToneProfile;
use App\Models\FunnelStageAiRule;
use App\Models\Message;
use App\Services\AI\OpenAiChatService;
use App\Services\Promotion\CompanyPromotionCatalog;
use Illuminate\Support\Str;

final class FunnelFollowUpAiTextService
{
    public function __construct(
        private readonly OpenAiChatService $openAi,
        private readonly CompanyPromotionCatalog $promotionCatalog,
        private readonly FunnelStageSequenceService $stageSequence,
    ) {}

    public function generate(FunnelStageAiRule $rule, Chat $chat): string
    {
        $name = trim((string) ($chat->chat_name ?? ''));
        if ($name === '') {
            $name = 'клиент';
        }

        $stageName = (string) ($rule->stage?->name ?? 'этап');
        $goal = trim((string) ($rule->goal ?? ''));
        $nextStage = $this->stageSequence->nextStageForRule($rule);
        $nextStageName = (string) ($nextStage?->name ?? 'следующий этап');
        $nextGoal = trim((string) ($this->stageSequence->nextStageRule($rule)?->goal ?? ''));
        $toneHint = $this->toneHint((int) $rule->company_id);
        $promoBlock = $this->promotionCatalog->formatPromptBlock(
            $this->promotionCatalog->promptItemsForRule($rule),
            '',
        );

        $history = Message::query()
            ->where('chat_id', $chat->id)
            ->whereNotNull('body')
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->limit(8)
            ->get(['direction', 'body'])
            ->reverse()
            ->map(fn (Message $m): string => ($m->direction === 'inbound' ? 'Клиент' : 'Мы').': '.Str::limit(trim((string) $m->body), 280, '…'))
            ->implode("\n");

        $prompt = <<<PROMPT
Напиши одно короткое follow-up сообщение клиенту в WhatsApp на русском языке.
Клиент: {$name}
Текущий этап воронки: {$stageName}
Цель текущего этапа: {$goal}
Следующий этап воронки: {$nextStageName}
Цель следующего этапа: {$nextGoal}
{$toneHint}
{$promoBlock}

Последние сообщения:
{$history}

Требования:
- 1–3 предложения, без markdown
- мягко подведи клиента к следующему этапу «{$nextStageName}»
- напомни, что нужно для перехода, без давления
- не выдумывай цены и сроки
- если есть активные акции — уместно упомяни одну из них
- только текст сообщения, без кавычек
PROMPT;

        try {
            $text = trim($this->openAi->chat([
                ['role' => 'system', 'content' => 'Ты пишешь короткие follow-up в стиле менеджера поддержки.'],
                ['role' => 'user', 'content' => $prompt],
            ], 0.35, 220, new \App\Services\AI\AiUsageOptions('auto_follow_up', (int) $rule->company_id)));
        } catch (\Throwable) {
            return FunnelStageFollowUpService::DEFAULT_MESSAGE;
        }

        if ($text === '') {
            return FunnelStageFollowUpService::DEFAULT_MESSAGE;
        }

        return Str::limit($text, 4000, '');
    }

    private function toneHint(int $companyId): string
    {
        $profile = CompanyToneProfile::query()->where('company_id', $companyId)->first();
        if ($profile === null) {
            return 'Стиль: нейтральный, вежливый.';
        }

        $summary = $profile->use_manual_override && trim((string) $profile->manual_summary) !== ''
            ? trim((string) $profile->manual_summary)
            : trim((string) $profile->summary);

        if ($summary === '') {
            return 'Стиль: нейтральный, вежливый.';
        }

        return 'Стиль компании: '.$summary;
    }
}
