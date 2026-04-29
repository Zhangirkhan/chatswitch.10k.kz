<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MessageReaction;
use App\Services\WhatsappService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SyncMessageReactionToWhatsappJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;

    /** @var list<int> */
    public array $backoff = [3, 5, 10, 20, 40, 60, 120, 180];

    public function __construct(public readonly int $reactionId) {}

    public function viaQueue(): string
    {
        return 'whatsapp';
    }

    public function handle(WhatsappService $whatsapp): void
    {
        /** @var MessageReaction|null $reaction */
        $reaction = MessageReaction::query()
            ->with(['message.whatsappSession', 'message.chat'])
            ->whereKey($this->reactionId)
            ->first();

        if ($reaction === null) {
            return;
        }

        // External (incoming) reactions are handled via webhook; do not sync them outward.
        if ($reaction->external_id !== null && $reaction->external_id !== '') {
            return;
        }

        $message = $reaction->message;
        if ($message === null) {
            return;
        }

        $sessionName = (string) ($message->whatsappSession?->session_name ?? '');
        $waMessageId = (string) ($message->whatsapp_message_id ?? '');
        $chatWhatsappId = (string) ($message->chat?->whatsapp_chat_id ?? '');
        $waMessageIdForReaction = $this->normalizeWhatsappMessageIdForReaction($waMessageId, $chatWhatsappId);

        // Message not yet synced to WhatsApp — retry later.
        if ($sessionName === '' || $waMessageId === '') {
            $reaction->forceFill([
                'pending_whatsapp_sync' => true,
                'whatsapp_sync_error' => null,
            ])->save();

            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 10);
            return;
        }

        $result = $whatsapp->reactToMessage($sessionName, $waMessageIdForReaction, (string) $reaction->emoji);
        if (($result['success'] ?? false) !== true) {
            $reaction->forceFill([
                'pending_whatsapp_sync' => true,
                'whatsapp_sync_error' => (string) ($result['error'] ?? 'Failed to sync reaction to WhatsApp.'),
            ])->save();

            throw new \RuntimeException((string) ($result['error'] ?? 'Failed to sync reaction to WhatsApp.'));
        }

        $reaction->forceFill([
            'pending_whatsapp_sync' => false,
            'whatsapp_synced_at' => now(),
            'whatsapp_sync_error' => null,
        ])->save();
    }

    private function normalizeWhatsappMessageIdForReaction(string $messageId, string $chatWhatsappId): string
    {
        $messageId = trim($messageId);
        $chatWhatsappId = trim($chatWhatsappId);
        if ($messageId === '' || $chatWhatsappId === '') {
            return $messageId;
        }

        $parts = explode('_', $messageId);
        if (count($parts) < 3) {
            return $messageId;
        }

        $remote = $parts[1] ?? '';
        if (! str_contains($remote, '@lid')) {
            return $messageId;
        }

        $parts[1] = $chatWhatsappId;

        return implode('_', $parts);
    }
}

