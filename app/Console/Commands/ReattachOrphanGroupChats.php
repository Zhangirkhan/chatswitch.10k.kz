<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\Message;
use App\Services\ChatService;
use Illuminate\Console\Command;

/**
 * После удаления WA-сессии у групп мог остаться whatsapp_session_id = NULL.
 * Эта команда привязывает такие группы к первой сессии в статусе connected.
 */
final class ReattachOrphanGroupChats extends Command
{
    protected $signature = 'chats:reattach-orphan-groups {--dry-run : Показать счётчики без изменений}';

    protected $description = 'Перепривязать групповые чаты с NULL whatsapp_session_id к подключённой WA-сессии';

    public function handle(ChatService $chatService): int
    {
        $replacement = $chatService->findReplacementWhatsappSession(null);
        if ($replacement === null) {
            $this->error('Нет WA-сессии в статусе «connected».');

            return self::FAILURE;
        }

        $label = $replacement->display_name
            ?? $replacement->wa_name
            ?? $replacement->phone_number
            ?? $replacement->session_name;

        $this->info("Целевая сессия: «{$label}» (id={$replacement->id})");

        $query = Chat::query()->whereNull('whatsapp_session_id')->where('is_group', true);
        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Нет групп без привязанной сессии.');

            return self::SUCCESS;
        }

        $this->info("Найдено групп без сессии: {$total}");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $reattached = 0;
        $skipped = 0;

        $query->orderBy('id')->chunkById(100, function ($chats) use ($replacement, $chatService, $label, &$reattached, &$skipped): void {
            foreach ($chats as $chat) {
                $conflict = Chat::query()
                    ->where('whatsapp_chat_id', $chat->whatsapp_chat_id)
                    ->where('whatsapp_session_id', $replacement->id)
                    ->whereKeyNot($chat->id)
                    ->exists();

                if ($conflict) {
                    $skipped++;

                    continue;
                }

                $chat->update(['whatsapp_session_id' => $replacement->id]);
                Message::create([
                    'chat_id' => $chat->id,
                    'whatsapp_session_id' => $replacement->id,
                    'direction' => 'system',
                    'type' => 'chat',
                    'body' => 'ℹ️ Группа снова привязана к «'.$label.'» (сессия ранее была сброшена).',
                    'message_timestamp' => now(),
                ]);
                $chatService->refreshChatLastMessageSnapshot($chat);
                $reattached++;
            }
        });

        $this->info("Перепривязано: {$reattached}, пропущено (дубликат JID на целевой сессии): {$skipped}");

        return self::SUCCESS;
    }
}
