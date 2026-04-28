<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NewMessageReceived;
use App\Models\WhatsappSession;
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

    public function handle(ChatService $chatService): void
    {
        $sessionName = (string) ($this->data['session'] ?? 'default');
        $session = WhatsappSession::where('session_name', $sessionName)->first();

        if (! $session) {
            Log::warning('[whatsapp-inbound] session not found', ['session' => $sessionName]);

            return;
        }

        DB::transaction(function () use ($chatService, $session): void {
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
        });
    }

    public function viaQueue(): string
    {
        return 'whatsapp';
    }
}
