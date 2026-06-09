<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\Message;
use App\Services\AI\AiReplyGenerator;
use App\Services\AI\AiResponderResolver;
use App\Services\AI\AutomatedPeerReplyGuard;
use App\Services\AI\ChatDepartmentRoutingService;
use App\Services\AI\ChatConflictService;
use App\Services\AI\ChatIdleAiReplyService;
use App\Services\AI\ChatOffHoursReplyService;
use App\Services\AI\WhatsappAiTypingService;
use App\Services\OutboundChatMessageDispatcher;
use App\Support\VoiceInboundHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class GenerateAiReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public readonly int $chatId,
        public readonly int $triggerMessageId,
        public readonly ?int $tenantCompanyId = null,
    ) {}

    public function handle(
        AiReplyGenerator $generator,
        OutboundChatMessageDispatcher $dispatcher,
        WhatsappAiTypingService $typing,
        AiResponderResolver $responderResolver,
        ChatDepartmentRoutingService $departmentRouting,
        ChatOffHoursReplyService $offHoursReply,
        AutomatedPeerReplyGuard $automatedPeerGuard,
        ChatIdleAiReplyService $idleAiReply,
        ChatConflictService $conflictService,
    ): void {
        $chat = Chat::query()
            ->with(['aiResponder', 'assignments.user', 'departments', 'funnel.aiScenario'])
            ->whereKey($this->chatId)
            ->first();
        $trigger = Message::query()->whereKey($this->triggerMessageId)->first();

        if ($chat === null || $trigger === null || ! $chat->ai_enabled) {
            return;
        }

        if ($this->conflictService->isAiPausedForConflict($chat)) {
            return;
        }

        if (! $idleAiReply->canExecuteReply($chat, $trigger)) {
            Log::info('[ai-reply] skipped manager replied or already handled', [
                'chat_id' => $chat->id,
                'trigger_message_id' => $trigger->id,
            ]);

            return;
        }

        if (VoiceInboundHelper::isVoiceWithoutContent($trigger)) {
            Log::info('[ai-reply] skipped voice awaiting transcript', [
                'chat_id' => $chat->id,
                'trigger_message_id' => $trigger->id,
            ]);

            return;
        }

        if ($automatedPeerGuard->shouldSuppress($chat, $trigger)) {
            Log::info('[ai-reply] skipped automated peer', [
                'chat_id' => $chat->id,
                'trigger_message_id' => $trigger->id,
                'reason' => $automatedPeerGuard->reason($chat, $trigger),
            ]);

            return;
        }

        $department = $departmentRouting->resolveAndAssignDepartment($chat, $trigger);
        $chat->refresh();

        if ($offHoursReply->tryReply($chat, $trigger, $department)) {
            return;
        }

        $latestInboundId = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->latest('message_timestamp')
            ->latest('id')
            ->value('id');

        if ((int) $latestInboundId !== (int) $trigger->id) {
            return;
        }

        $responder = $responderResolver->forChat($chat, $chat->funnel?->aiScenario);
        if ($responder === null) {
            return;
        }

        $mode = $chat->ai_mode === 'draft' ? 'draft' : 'auto';
        $log = AiResponseLog::firstOrCreate(
            ['trigger_message_id' => $trigger->id, 'mode' => $mode],
            [
                'company_id' => $chat->company_id ?? $responder->company_id,
                'chat_id' => $chat->id,
                'user_id' => $responder->id,
                'status' => 'pending',
            ],
        );

        if ($log->message_id !== null || in_array($log->status, ['sent', 'drafted', 'generating'], true)) {
            return;
        }

        $claimed = AiResponseLog::query()
            ->whereKey($log->id)
            ->whereNull('message_id')
            ->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'generating', 'error' => null]);

        if ($claimed !== 1) {
            return;
        }

        $log->refresh();

        try {
            $generated = $typing->whileGenerating($chat, fn (): array => $generator->generate($chat, $responder, $trigger, $log));
            if ($mode === 'draft') {
                $log->forceFill([
                    'status' => 'drafted',
                    'prompt_hash' => $generated['prompt_hash'],
                    'metadata' => [
                        ...($log->metadata ?? []),
                        ...($generated['metadata'] ?? []),
                        'draft_reply' => $generated['reply'],
                    ],
                    'error' => null,
                ])->save();

                return;
            }

            $message = $dispatcher->sendTextMessage($responder, $chat, [
                'message' => $generated['reply'],
                'display_message' => $generated['reply'],
                'metadata' => [
                    'ai' => [
                        'generated' => true,
                        'mode' => 'auto',
                        'trigger_message_id' => $trigger->id,
                        'reply_as_company' => $responderResolver->replyAsCompany($chat),
                    ],
                    ...($generated['metadata'] ?? []),
                ],
            ])->message;

            $log->forceFill([
                'message_id' => $message->id,
                'status' => 'sent',
                'prompt_hash' => $generated['prompt_hash'],
                'error' => null,
            ])->save();
        } catch (\Throwable $e) {
            $isBlocked = str_contains($e->getMessage(), 'AI safety check');
            $log->forceFill([
                'status' => $isBlocked ? 'blocked' : 'failed',
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            Log::warning('[ai-reply] failed to generate reply', [
                'chat_id' => $chat->id,
                'trigger_message_id' => $trigger->id,
                'error' => $e->getMessage(),
            ]);

            if (! $isBlocked) {
                throw $e;
            }
        }
    }
}
