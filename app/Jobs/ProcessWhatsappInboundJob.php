<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NewMessageReceived;
use App\Models\FunnelAiScenario;
use App\Models\Message;
use App\Models\SystemSetting;
use App\Models\WhatsappSession;
use App\Services\AI\ChatDepartmentRoutingService;
use App\Services\AI\ChatOffHoursReplyService;
use App\Services\ChatService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProcessWhatsappInboundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60, 120];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data) {}

    public function handle(
        ChatService $chatService,
        ChatDepartmentRoutingService $departmentRouting,
        ChatOffHoursReplyService $offHoursReply,
    ): void
    {
        $sessionName = (string) ($this->data['session'] ?? 'default');
        $session = WhatsappSession::where('session_name', $sessionName)->first();

        if (! $session) {
            Log::warning('[whatsapp-inbound] session not found', ['session' => $sessionName]);

            return;
        }

        $message = DB::transaction(function () use ($chatService, $session): Message {
            $chat = $chatService->findOrCreateChat($this->data, $session);
            $message = $chatService->storeInboundMessage($chat, $session, $this->data);
            $message->load([
                'media',
                'sentByUser',
                'whatsappSession',
                'quotedMessage:id,whatsapp_message_id,direction,type,body,sender_name,sender_phone,sent_by_user_id',
                'quotedMessage.sentByUser:id,name',
                'quotedMessage.media:id,message_id,mime_type,filename',
            ]);

            broadcast(new NewMessageReceived($message, $chat->id));

            return $message;
        });

        $message->loadMissing('chat');

        $resolvedDepartment = null;
        if ($message->chat !== null && $message->direction === 'inbound') {
            $resolvedDepartment = $departmentRouting->resolveAndAssignDepartment($message->chat, $message);
            $message->chat->refresh();
        }

        if ($message->chat !== null
            && $message->direction === 'inbound'
            && $offHoursReply->tryReply($message->chat, $message, $resolvedDepartment)) {
            return;
        }

        $shouldAnalyzeFunnel = $message->chat !== null
            && SystemSetting::getValue('module_funnels', 'on') === 'on'
            && ! $message->chat->is_group
            && $message->direction === 'inbound'
            && $message->chat->funnel_tracking_enabled
            && ! $message->chat->funnel_stage_locked;

        $orchestratorEnabled = $message->chat !== null
            && $message->chat->funnel_id !== null
            && FunnelAiScenario::query()
                ->where('funnel_id', $message->chat->funnel_id)
                ->where('enabled', true)
                ->exists();

        if ($orchestratorEnabled) {
            $delaySeconds = max(1, (int) config('funnel.orchestrator.debounce_seconds', 3));
            RunAiFunnelOrchestratorJob::dispatch($message->chat_id, $message->id)
                ->delay(now()->addSeconds($delaySeconds));

            return;
        }

        if ($shouldAnalyzeFunnel) {
            $delaySeconds = max(1, (int) config('funnel.ai.debounce_seconds', 5));
            AnalyzeChatFunnelJob::dispatch($message->chat_id, $message->id)
                ->delay(now()->addSeconds($delaySeconds));
        }

        if ($message->chat?->ai_enabled === true && ! $shouldAnalyzeFunnel) {
            GenerateAiReplyJob::dispatch($message->chat_id, $message->id);
        }
    }

    public function viaQueue(): string
    {
        return 'whatsapp';
    }
}
