<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NewMessageReceived;
use App\Models\Message;
use App\Models\WhatsappSession;
use App\Services\AI\AutomatedPeerReplyGuard;
use App\Services\AI\ChatDepartmentRoutingService;
use App\Services\AI\ChatOffHoursReplyService;
use App\Services\AI\InboundAiDispatchService;
use App\Services\ChatService;
use App\Support\VoiceInboundHelper;
use App\Support\WhatsappMessageType;
use App\Support\WhatsappSessionResolver;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProcessWhatsappInboundJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60, 120];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data) {}

    public function uniqueId(): string
    {
        $session = (string) ($this->data['session'] ?? 'default');
        $messageId = (string) ($this->data['messageId'] ?? '');

        return $session.':'.$messageId;
    }

    public function handle(
        ChatService $chatService,
        ChatDepartmentRoutingService $departmentRouting,
        ChatOffHoursReplyService $offHoursReply,
        AutomatedPeerReplyGuard $automatedPeerGuard,
        TenantContext $tenantContext,
        InboundAiDispatchService $aiDispatch,
    ): void {
        $sessionName = (string) ($this->data['session'] ?? 'default');
        $companyId = isset($this->data['companyId']) ? (int) $this->data['companyId'] : null;
        $session = WhatsappSessionResolver::resolveByName($sessionName, $companyId);

        if (! $session) {
            Log::warning('[whatsapp-inbound] session not found', ['session' => $sessionName]);

            return;
        }

        $type = (string) ($this->data['type'] ?? 'chat');
        if (WhatsappMessageType::shouldIgnoreInbound($type)) {
            Log::debug('[whatsapp-inbound] ignored service message', [
                'session' => $sessionName,
                'type' => $type,
                'messageId' => $this->data['messageId'] ?? null,
            ]);

            return;
        }

        $company = $session->tenantCompany;
        if ($company !== null) {
            $tenantContext->setCompany($company);
        }

        $chatId = 0;

        $isDuplicate = false;

        $message = DB::transaction(function () use ($chatService, $session, &$chatId, &$isDuplicate): ?Message {
            $chat = $chatService->findOrCreateChat($this->data, $session);
            $chatId = (int) $chat->id;

            $beforeId = isset($this->data['messageId'])
                ? Message::query()
                    ->where('whatsapp_session_id', $session->id)
                    ->where('whatsapp_message_id', (string) $this->data['messageId'])
                    ->value('id')
                : null;

            $message = $chatService->storeInboundMessage($chat, $session, $this->data);
            if ($message === null) {
                Log::info('[whatsapp-inbound] skipped message before chat clear cutoff', [
                    'chat_id' => $chatId,
                    'message_id' => $this->data['messageId'] ?? null,
                    'cleared_at' => $chat->messages_cleared_at?->toIso8601String(),
                ]);

                return null;
            }

            $isDuplicate = $beforeId !== null && (int) $beforeId === (int) $message->id;
            $message->load([
                'media',
                'sentByUser',
                'whatsappSession',
                'quotedMessage:id,whatsapp_message_id,direction,type,body,sender_name,sender_phone,sent_by_user_id',
                'quotedMessage.sentByUser:id,name',
                'quotedMessage.media:id,message_id,mime_type,filename',
            ]);

            return $message;
        });

        if ($message === null) {
            return;
        }

        if ($isDuplicate) {
            Log::info('[whatsapp-inbound] duplicate webhook skipped side effects', [
                'chat_id' => $chatId,
                'message_id' => $message->id,
            ]);

            return;
        }

        try {
            broadcast(new NewMessageReceived($message, $chatId));
        } catch (\Throwable $e) {
            Log::warning('[whatsapp-inbound] broadcast failed, message kept in DB', [
                'chat_id' => $chatId,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        $message->loadMissing('chat');

        if ($message->chat !== null
            && $message->direction === 'inbound'
            && $automatedPeerGuard->shouldSuppress($message->chat, $message)) {
            Log::info('[whatsapp-inbound] AI suppressed for automated peer', [
                'chat_id' => $message->chat->id,
                'message_id' => $message->id,
                'reason' => $automatedPeerGuard->reason($message->chat, $message),
            ]);

            return;
        }

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

        if (VoiceInboundHelper::needsTranscriptionBeforeAi($message)) {
            return;
        }

        $aiDispatch->dispatchForInboundMessage($message);
    }

    public function viaQueue(): string
    {
        return 'whatsapp';
    }
}
