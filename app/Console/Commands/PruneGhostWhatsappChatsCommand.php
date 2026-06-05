<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChatService;
use Illuminate\Console\Command;

/**
 * Удаляет служебные WA-сообщения (e2e_notification и т.п.) и пустые «призрачные» чаты.
 */
final class PruneGhostWhatsappChatsCommand extends Command
{
    protected $signature = 'chats:prune-ghost-whatsapp';

    protected $description = 'Remove WhatsApp service messages and empty ghost chats (@lid / e2e_notification)';

    public function handle(ChatService $chatService): int
    {
        $result = $chatService->pruneGhostWhatsappChats();

        $this->info(sprintf(
            'Removed %d service messages, deleted %d ghost chats, fixed %d contacts.',
            $result['ignored_messages'],
            $result['deleted_chats'],
            $result['fixed_contacts'],
        ));

        return self::SUCCESS;
    }
}
