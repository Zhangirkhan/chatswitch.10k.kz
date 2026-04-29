<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\NewMessageReceived;
use App\Jobs\SendOutboundMessageJob;
use App\Models\ScheduledMessage;
use App\Services\ChatService;
use App\Support\OperatorSignature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class SendScheduledMessages extends Command
{
    protected $signature = 'scheduled-messages:send {--limit=50}';

    protected $description = 'Send due scheduled chat messages.';

    public function handle(ChatService $chatService): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));

        $ids = ScheduledMessage::query()
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->pluck('id')
            ->all();

        $sent = 0;
        foreach ($ids as $id) {
            if ($this->sendOne((int) $id, $chatService)) {
                $sent++;
            }
        }

        $this->info("Scheduled messages processed: {$sent}");

        return self::SUCCESS;
    }

    private function sendOne(int $id, ChatService $chatService): bool
    {
        $scheduled = ScheduledMessage::query()->whereKey($id)->first();
        if ($scheduled === null || $scheduled->status !== ScheduledMessage::STATUS_PENDING) {
            return false;
        }

        $claimed = ScheduledMessage::query()
            ->whereKey($scheduled->id)
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->update(['status' => ScheduledMessage::STATUS_SENDING, 'error' => null]);

        if ($claimed !== 1) {
            return false;
        }

        try {
            $scheduled->refresh();
            $scheduled->load(['chat.whatsappSession', 'whatsappSession', 'user']);

            $chat = $scheduled->chat;
            $session = $scheduled->whatsappSession ?: $chat?->whatsappSession;
            $user = $scheduled->user;

            if ($chat === null || $session === null || $user === null) {
                throw new \RuntimeException('Не найден чат, WhatsApp-сессия или пользователь.');
            }

            $signedText = OperatorSignature::prepend($user, (string) $scheduled->body);
            $signedDisplayText = OperatorSignature::prepend(
                $user,
                (string) ($scheduled->display_body ?: $scheduled->body),
            );

            $message = DB::transaction(function () use ($chatService, $chat, $session, $user, $signedDisplayText, $scheduled) {
                $message = $chatService->storeOutboundMessage($chat, $session, $user, $signedDisplayText);

                $scheduled->forceFill([
                    'status' => ScheduledMessage::STATUS_SENT,
                    'sent_message_id' => $message->id,
                    'error' => null,
                ])->save();

                return $message;
            });

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
                [
                    'body' => $signedText,
                    'quoted_message_id' => null,
                    'mentions' => [],
                ],
            );

            return true;
        } catch (\Throwable $e) {
            ScheduledMessage::query()
                ->whereKey($id)
                ->update([
                    'status' => ScheduledMessage::STATUS_FAILED,
                    'error' => mb_substr($e->getMessage(), 0, 2000),
                ]);

            $this->warn("Scheduled message {$id} failed: {$e->getMessage()}");

            return false;
        }
    }
}

