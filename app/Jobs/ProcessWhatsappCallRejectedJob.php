<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\NewMessageReceived;
use App\Models\Chat;
use App\Models\User;
use App\Support\WhatsappSessionResolver;
use App\Services\ChatService;
use App\Support\OperatorSignature;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

final class ProcessWhatsappCallRejectedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [2, 10, 30];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public readonly array $data) {}

    public function viaQueue(): string
    {
        return 'whatsapp';
    }

    public function handle(ChatService $chatService, TenantContext $tenantContext): void
    {
        $sessionName = trim((string) ($this->data['session'] ?? ''));
        $peerJid = trim((string) ($this->data['peerJid'] ?? ''));
        if ($sessionName === '' || $peerJid === '') {
            return;
        }

        $fromMe = filter_var($this->data['fromMe'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($fromMe) {
            return;
        }

        $companyId = isset($this->data['companyId']) ? (int) $this->data['companyId'] : null;
        $session = WhatsappSessionResolver::resolveByName($sessionName, $companyId);
        if ($session === null) {
            Log::warning('[wa-call-reject] session not found', ['session' => $sessionName]);

            return;
        }

        $company = $session->tenantCompany;
        if ($company !== null) {
            $tenantContext->setCompany($company);
        }

        $chat = Chat::query()
            ->where('whatsapp_session_id', $session->id)
            ->where('whatsapp_chat_id', $peerJid)
            ->first();

        if ($chat === null) {
            Log::info('[wa-call-reject] chat not found for peer', [
                'session' => $sessionName,
                'peerJid' => $peerJid,
            ]);

            return;
        }

        $rateKey = 'wa-call-auto-reply:'.$chat->id;
        if (RateLimiter::tooManyAttempts($rateKey, 1)) {
            return;
        }
        RateLimiter::hit($rateKey, 90);

        $email = (string) config('accel.system_user_email', 'system@chatswitch.internal');
        $systemUser = User::query()->where('email', $email)->first();
        if ($systemUser === null) {
            Log::error('[wa-call-reject] system user missing', ['email' => $email]);

            return;
        }

        $chat->loadMissing('whatsappSession');
        $waSession = $chat->whatsappSession;
        if ($waSession === null) {
            return;
        }

        $plain = 'Голосовые и видеозвонки сейчас недоступны. Напишите, пожалуйста, текстом в этом чате или отправьте голосовое сообщение — мы ответим здесь.';
        $body = OperatorSignature::prepend($systemUser, $plain);

        $message = $chatService->storeOutboundMessage(
            $chat,
            $waSession,
            $systemUser,
            $body,
            null,
            null,
        );

        $message->load([
            'media',
            'sentByUser',
            'whatsappSession',
            'reactions.user:id,name',
            'quotedMessage:id,whatsapp_message_id,direction,type,body,sender_name,sender_phone,sent_by_user_id',
            'quotedMessage.sentByUser:id,name',
            'quotedMessage.media:id,message_id,mime_type,filename',
        ]);

        broadcast(new NewMessageReceived($message, $chat->id));

        SendOutboundMessageJob::dispatch(
            $message->id,
            'text',
            ['body' => $body, 'quoted_message_id' => null],
        );
    }
}
