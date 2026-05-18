<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chat;
use App\Models\Message;
use App\Models\User;
use App\Services\AI\ChatFunnelClassification;
use App\Services\Funnel\ChatFunnelStateService;
use App\Services\OutboundChatMessageDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessOutboundFunnelSignalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $messageId,
    ) {}

    public function handle(ChatFunnelStateService $funnelState, OutboundChatMessageDispatcher $dispatcher): void
    {
        $message = Message::query()
            ->with(['sentByUser', 'chat.aiResponder', 'chat.funnel.stages', 'chat.funnelStage'])
            ->whereKey($this->messageId)
            ->first();

        $chat = $message?->chat;
        if (! $message instanceof Message || ! $chat instanceof Chat) {
            return;
        }

        if (! $this->canProcess($message, $chat)) {
            return;
        }

        $targetStageId = $this->targetStageId($message, $chat);
        if ($targetStageId === null || (int) $chat->funnel_stage_id === $targetStageId) {
            return;
        }

        $funnelState->applyFromAi(
            $chat,
            new ChatFunnelClassification(
                funnelId: (int) $chat->funnel_id,
                funnelStageId: $targetStageId,
                confidence: 1.0,
                reason: 'Этап обновлён по сообщению сотрудника.',
            ),
            $message->id,
        );

        if ($this->stageNameById($chat, $targetStageId) === 'Замер проведён') {
            $this->sendEstimateAndAdvance($funnelState, $dispatcher, $message, $chat);
        }
    }

    private function canProcess(Message $message, Chat $chat): bool
    {
        if ($message->direction !== 'outbound' || $message->sent_by_user_id === null) {
            return false;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (isset($metadata['ai']['generated']) && $metadata['ai']['generated'] === true) {
            return false;
        }

        return $chat->funnel_id !== null
            && $chat->funnel_stage_id !== null
            && $chat->funnel_tracking_enabled
            && ! $chat->funnel_stage_locked;
    }

    private function targetStageId(Message $message, Chat $chat): ?int
    {
        $currentStage = trim((string) $chat->funnelStage?->name);
        $body = mb_strtolower((string) $message->body);

        if ($currentStage === 'Передано замерщику'
            && (str_contains($body, 'замер провед') || str_contains($body, 'замер состоя'))) {
            return $this->stageIdByName($chat, 'Замер проведён');
        }

        return null;
    }

    private function stageIdByName(Chat $chat, string $name): ?int
    {
        $stage = $chat->funnel?->stages->first(
            fn ($stage): bool => trim((string) $stage->name) === $name,
        );

        return $stage?->id ? (int) $stage->id : null;
    }

    private function stageNameById(Chat $chat, int $stageId): ?string
    {
        $stage = $chat->funnel?->stages->first(
            fn ($stage): bool => (int) $stage->id === $stageId,
        );

        return $stage?->name ? trim((string) $stage->name) : null;
    }

    private function sendEstimateAndAdvance(
        ChatFunnelStateService $funnelState,
        OutboundChatMessageDispatcher $dispatcher,
        Message $trigger,
        Chat $chat,
    ): void {
        $actor = $chat->aiResponder instanceof User
            ? $chat->aiResponder
            : $trigger->sentByUser;

        if (! $actor instanceof User) {
            return;
        }

        $dispatcher->sendTextMessage($actor, $chat, [
            'message' => $this->estimateReply($chat),
            'display_message' => $this->estimateReply($chat),
            'metadata' => [
                'ai' => [
                    'generated' => true,
                    'mode' => 'funnel_estimate',
                    'trigger_message_id' => $trigger->id,
                ],
            ],
        ]);

        $offerStageId = $this->stageIdByName($chat, 'Коммерческое предложение отправлено');
        if ($offerStageId === null) {
            return;
        }

        $funnelState->applyFromAi(
            $chat->fresh(['funnel.stages', 'funnelStage']) ?? $chat,
            new ChatFunnelClassification(
                funnelId: (int) $chat->funnel_id,
                funnelStageId: $offerStageId,
                confidence: 1.0,
                reason: 'AI отправил предварительный расчёт клиенту.',
            ),
            $trigger->id,
        );
    }

    private function estimateReply(Chat $chat): string
    {
        $history = mb_strtolower($chat->messages()
            ->whereNotNull('body')
            ->latest('id')
            ->limit(20)
            ->pluck('body')
            ->implode("\n"));

        $product = str_contains($history, 'кухн') ? 'кухни' : 'мебели';
        $price = str_contains($history, 'кухн') ? 'от 350 000 ₸' : 'от 180 000 ₸';
        $size = preg_match('/(\d+(?:[,.]\d+)?)\s*(?:м|метр)/u', $history, $matches)
            ? str_replace(',', '.', $matches[1]).' м'
            : null;

        $sizeText = $size !== null ? " по размеру примерно {$size}" : '';

        return "Замер провели, спасибо. Предварительный расчёт {$product}{$sizeText}: {$price}. Точную стоимость менеджер подготовит по материалам, фурнитуре и комплектации и отправит следующим сообщением.";
    }
}
