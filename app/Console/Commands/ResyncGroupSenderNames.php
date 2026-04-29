<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Services\ChatService;
use App\Services\WhatsappService;
use App\Support\PhoneFormatter;
use Illuminate\Console\Command;

final class ResyncGroupSenderNames extends Command
{
    protected $signature = 'groups:resync-sender-names
        {chatId? : ID чата-группы для пересинхронизации}
        {--all : Пройтись по всем группам}
        {--limit=5 : Сколько последних сообщений подтянуть из WhatsApp на группу}
        {--dry-run : Ничего не менять, только показать статистику}';

    protected $description = 'Обновляет sender_name в истории групп на сохранённые в контактах имена.';

    public function __construct(
        private readonly ChatService $chatService,
        private readonly WhatsappService $whatsappService,
    )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $all = (bool) $this->option('all');
        $chatId = $this->argument('chatId');

        if (! $all && ! $chatId) {
            $this->error('Укажите chatId или --all');
            return self::INVALID;
        }

        $limit = (int) $this->option('limit');
        if ($limit <= 0) $limit = 500;

        $query = Chat::query()
            ->where('is_group', true)
            ->with('whatsappSession');
        if (! $all) {
            $query->where('id', (int) $chatId);
        }

        $totalScanned = 0;
        $totalUpdated = 0;
        $countChats = 0;

        foreach ($query->cursor() as $chat) {
            $countChats++;

            // 1) Pull fresh sender info from WhatsApp for last N messages (best-effort).
            $pulled = 0;
            $pulledUpdated = 0;
            $fetched = 0;
            $sessionName = $chat->whatsappSession?->session_name;

            if ($sessionName && $chat->whatsapp_chat_id) {
                $resp = $this->whatsappService->getChatMessages($sessionName, $chat->whatsapp_chat_id, $limit);
                if (($resp['success'] ?? false) === true && is_array($resp['messages'] ?? null)) {
                    $fetched = count($resp['messages']);
                    foreach ($resp['messages'] as $row) {
                        if (! is_array($row)) continue;
                        $waId = isset($row['id']) && is_string($row['id']) ? $row['id'] : null;
                        if (! $waId) continue;

                        $senderNumber = isset($row['senderNumber']) && is_string($row['senderNumber']) ? $row['senderNumber'] : null;
                        $senderPhone = PhoneFormatter::normalize($senderNumber);
                        $senderName = null;
                        if (isset($row['senderName']) && is_string($row['senderName']) && trim($row['senderName']) !== '') {
                            $senderName = trim($row['senderName']);
                        } elseif (isset($row['senderPushname']) && is_string($row['senderPushname']) && trim($row['senderPushname']) !== '') {
                            $senderName = trim($row['senderPushname']);
                        }

                        // Upsert contact snapshot from WhatsApp (so later we can resolve saved names).
                        if ($senderPhone) {
                            $waSenderId = isset($row['senderId']) && is_string($row['senderId']) ? trim($row['senderId']) : null;
                            $waName = isset($row['senderName']) && is_string($row['senderName']) && trim($row['senderName']) !== '' ? trim($row['senderName']) : null;
                            $waPush = isset($row['senderPushname']) && is_string($row['senderPushname']) && trim($row['senderPushname']) !== '' ? trim($row['senderPushname']) : null;

                            $contact = Contact::query()->where('phone_number', $senderPhone)->first();
                            if (! $contact) {
                                if (! $dry) {
                                    Contact::create([
                                        'phone_number' => $senderPhone,
                                        'whatsapp_id' => $waSenderId,
                                        'name' => $waName,
                                        'push_name' => $waPush,
                                        'profile_picture_url' => null,
                                        'is_business' => false,
                                    ]);
                                }
                            } else {
                                $dirtyC = false;
                                if ($waSenderId && (string) $contact->whatsapp_id !== (string) $waSenderId) {
                                    $contact->whatsapp_id = $waSenderId;
                                    $dirtyC = true;
                                }
                                if ($waName && (string) $contact->name !== (string) $waName) {
                                    $contact->name = $waName;
                                    $dirtyC = true;
                                }
                                if ($waPush && (string) $contact->push_name !== (string) $waPush) {
                                    $contact->push_name = $waPush;
                                    $dirtyC = true;
                                }
                                if ($dirtyC && ! $dry) {
                                    $contact->saveQuietly();
                                }
                            }
                        }

                        // Only makes sense for inbound group messages.
                        /** @var Message|null $msg */
                        $msg = Message::query()
                            ->where('chat_id', $chat->id)
                            ->where('whatsapp_message_id', $waId)
                            ->where('direction', 'inbound')
                            ->first();
                        if (! $msg) continue;

                        $pulled++;

                        $normalizedName = $senderName;
                        if (is_string($normalizedName)) {
                            $normalizedName = preg_replace('/^\s*~\s+/u', '', $normalizedName) ?? $normalizedName;
                            $normalizedName = trim($normalizedName);
                            if ($normalizedName === '') $normalizedName = null;
                        }

                        $dirty = false;
                        if ($senderPhone && (string) $msg->sender_phone !== (string) $senderPhone) {
                            $msg->sender_phone = $senderPhone;
                            $dirty = true;
                        }
                        if ($normalizedName !== null && (string) $msg->sender_name !== (string) $normalizedName) {
                            $msg->sender_name = $normalizedName;
                            $dirty = true;
                        }

                        if ($dirty) {
                            $pulledUpdated++;
                            if (! $dry) {
                                $msg->saveQuietly();
                            }
                        }
                    }
                }
            }

            // 2) Ensure sender_name reflects saved contact names (if contact was saved/renamed after receipt).
            $stats = $this->chatService->resyncGroupSenderNames($chat, $dry);
            $totalScanned += $stats['scanned'];
            $totalUpdated += $stats['updated'];
            $this->line("Chat #{$chat->id}: fetched={$fetched} pulled={$pulled} pulled_updated={$pulledUpdated} scanned={$stats['scanned']} updated={$stats['updated']}");
        }

        $this->info("Done. chats={$countChats} scanned={$totalScanned} updated={$totalUpdated}".($dry ? ' (dry-run)' : ''));
        return self::SUCCESS;
    }
}

