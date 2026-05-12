<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

final class AiReplyGenerator
{
    public function __construct(
        private readonly PromptBuilder $promptBuilder,
        private readonly OpenAiChatService $openAi,
    ) {}

    /**
     * @return array{reply: string, prompt_hash: string}
     */
    public function generate(Chat $chat, User $responder, ?Message $triggerMessage, ?AiResponseLog $log = null): array
    {
        $clientQuestion = trim((string) ($triggerMessage?->body ?? ''));
        $built = $this->promptBuilder->build($chat, $responder, $clientQuestion, $chat->company_id ?? $responder->company_id);
        $reply = trim($this->openAi->chat($built['messages'], 0.35, 700));
        $reply = $this->sanitizeReply($reply);
        $reply = $this->normalizeCurrency($reply);
        $this->assertSafeReply($reply);

        $log?->forceFill([
            'prompt_hash' => $built['prompt_hash'],
            'model' => (string) config('services.openai.model', 'gpt-4o-mini'),
            'metadata' => [
                ...($log->metadata ?? []),
                'messages_count' => count($built['messages']),
            ],
        ])->save();

        return [
            'reply' => $reply,
            'prompt_hash' => $built['prompt_hash'],
        ];
    }

    private function sanitizeReply(string $reply): string
    {
        $reply = preg_replace('/^```(?:text|markdown)?\s*|\s*```$/iu', '', $reply) ?: $reply;
        $reply = trim($reply, " \t\n\r\0\x0B\"'");

        return Str::limit($reply, 4000, '...');
    }

    private function normalizeCurrency(string $reply): string
    {
        return preg_replace('/\b(?:руб(?:\.|лей|ля|ль|ли|лях|лями)?|₽)\b/iu', '₸', $reply) ?: $reply;
    }

    private function assertSafeReply(string $reply): void
    {
        if (trim($reply) === '') {
            throw new RuntimeException('AI safety check: пустой ответ.');
        }

        $lower = mb_strtolower($reply);
        $forbidden = [
            'я ai',
            'я ии',
            'я искусственный интеллект',
            'как ai',
            'как ии',
            'системная инструкция',
            'system prompt',
        ];

        foreach ($forbidden as $needle) {
            if (str_contains($lower, $needle)) {
                throw new RuntimeException('AI safety check: ответ раскрывает AI или служебный контекст.');
            }
        }
    }
}
