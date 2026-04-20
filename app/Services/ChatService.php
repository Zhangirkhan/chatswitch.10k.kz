<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\User;
use App\Models\WhatsappSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ChatService
{
    public function getChatsForUser(User $user, ?string $search = null): Builder
    {
        $query = Chat::with(['contact', 'whatsappSession', 'assignments.user'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('last_message_at');

        if ($user->hasRole('administrator')) {
            // sees all chats
        } elseif ($user->hasRole('manager')) {
            $departmentUserIds = User::where('department_id', $user->department_id)
                ->pluck('id');
            $query->whereHas('assignments', fn (Builder $q) => $q->whereIn('user_id', $departmentUserIds));
        } else {
            $query->whereHas('assignments', fn (Builder $q) => $q->where('user_id', $user->id));
        }

        if ($search) {
            $query->where(function (Builder $q) use ($search) {
                $q->where('chat_name', 'like', "%{$search}%")
                    ->orWhereHas('contact', fn (Builder $cq) => $cq->where('phone_number', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('push_name', 'like', "%{$search}%"));
            });
        }

        return $query;
    }

    public function findOrCreateChat(array $data, WhatsappSession $session): Chat
    {
        return Chat::firstOrCreate(
            [
                'whatsapp_chat_id' => $data['chatId'],
                'whatsapp_session_id' => $session->id,
            ],
            [
                'chat_name' => $data['chatName'] ?? $data['from'] ?? 'Unknown',
                'is_group' => $data['isGroup'] ?? false,
            ],
        );
    }

    public function findOrCreateChatForContact(Contact $contact, WhatsappSession $session): Chat
    {
        $digits = preg_replace('/\D/', '', (string) ($contact->whatsapp_id ?: $contact->phone_number));
        $whatsappChatId = str_contains((string) $contact->whatsapp_id, '@')
            ? (string) $contact->whatsapp_id
            : "{$digits}@c.us";

        $chat = Chat::firstOrCreate(
            [
                'whatsapp_chat_id' => $whatsappChatId,
                'whatsapp_session_id' => $session->id,
            ],
            [
                'chat_name' => $contact->name ?: $contact->push_name ?: $contact->phone_number,
                'contact_id' => $contact->id,
                'is_group' => false,
            ],
        );

        if (! $chat->contact_id) {
            $chat->update(['contact_id' => $contact->id]);
        }

        return $chat;
    }

    public function findOrCreateContactByPhone(string $phone, ?string $name = null): Contact
    {
        $digits = preg_replace('/\D/', '', $phone);

        return Contact::firstOrCreate(
            ['whatsapp_id' => $digits],
            [
                'phone_number' => $digits,
                'name' => $name,
                'push_name' => $name,
            ],
        );
    }

    public function findOrCreateContact(array $data): Contact
    {
        $whatsappId = $data['from'] ?? $data['senderPhone'] ?? '';

        return Contact::firstOrCreate(
            ['whatsapp_id' => $whatsappId],
            [
                'phone_number' => $data['senderPhone'] ?? $whatsappId,
                'name' => $data['senderName'] ?? null,
                'push_name' => $data['senderName'] ?? null,
            ],
        );
    }

    public function storeInboundMessage(Chat $chat, WhatsappSession $session, array $data): Message
    {
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => $data['messageId'] ?? null,
            'direction' => 'inbound',
            'type' => $data['type'] ?? 'chat',
            'body' => $data['body'] ?? '',
            'sender_phone' => $data['senderPhone'] ?? null,
            'sender_name' => $data['senderName'] ?? null,
            'is_forwarded' => $data['isForwarded'] ?? false,
            'quoted_message_id' => $data['quotedMessageId'] ?? null,
            'ack' => 'delivered',
            'message_timestamp' => isset($data['timestamp']) ? now()->setTimestamp((int) $data['timestamp']) : now(),
        ]);

        if (! empty($data['mediaUrl'])) {
            $this->storeMediaFromBase64($message, $data['mediaUrl'], $data['mediaMimetype'] ?? 'application/octet-stream', $data['mediaFilename'] ?? null);
        }

        $chat->update([
            'last_message_text' => Str::limit($data['body'] ?? '[Media]', 200),
            'last_message_at' => $message->message_timestamp,
            'unread_count' => $chat->unread_count + 1,
        ]);

        if (! $chat->contact_id) {
            $contact = $this->findOrCreateContact($data);
            $chat->update(['contact_id' => $contact->id]);
        }

        return $message;
    }

    public function storeOutboundMessage(Chat $chat, WhatsappSession $session, User $user, string $body, ?string $waMessageId = null, ?string $quotedMessageId = null): Message
    {
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => $waMessageId,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => $body,
            'sent_by_user_id' => $user->id,
            'sender_name' => $user->name,
            'quoted_message_id' => $quotedMessageId,
            'ack' => $waMessageId ? 'sent' : 'pending',
            'message_timestamp' => now(),
        ]);

        $chat->update([
            'last_message_text' => Str::limit($body, 200),
            'last_message_at' => $message->message_timestamp,
        ]);

        return $message;
    }

    public function storeOutboundMedia(Message $message, string $binary, string $mimetype, ?string $filename): MessageMedia
    {
        $ext = $this->mimeToExtension($mimetype);
        $storedFilename = $filename ?: (Str::uuid()->toString() . ".{$ext}");
        $path = 'whatsapp-media/' . date('Y/m') . '/' . Str::uuid()->toString() . ".{$ext}";

        Storage::disk('local')->put($path, $binary);

        return MessageMedia::create([
            'message_id' => $message->id,
            'mime_type' => $mimetype,
            'filename' => $storedFilename,
            'disk_path' => $path,
            'file_size' => strlen($binary),
        ]);
    }

    private function storeMediaFromBase64(Message $message, string $dataUrl, string $mimetype, ?string $filename): void
    {
        $base64 = preg_replace('/^data:[^;]+;base64,/', '', $dataUrl);
        if (! $base64) {
            return;
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            return;
        }

        $ext = $this->mimeToExtension($mimetype);
        $storedFilename = $filename ?: (Str::uuid()->toString() . ".{$ext}");
        $path = "whatsapp-media/" . date('Y/m') . "/{$storedFilename}";

        Storage::disk('local')->put($path, $decoded);

        MessageMedia::create([
            'message_id' => $message->id,
            'mime_type' => $mimetype,
            'filename' => $storedFilename,
            'disk_path' => $path,
            'file_size' => strlen($decoded),
        ]);
    }

    private function mimeToExtension(string $mimetype): string
    {
        $mimetype = strtolower(explode(';', $mimetype)[0]);
        $map = [
            'image/jpeg' => 'jpg', 'image/jpg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov',
            'audio/ogg' => 'ogg', 'audio/opus' => 'opus', 'audio/webm' => 'webm',
            'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav',
            'audio/mp4' => 'm4a', 'audio/aac' => 'aac',
            'application/pdf' => 'pdf', 'application/zip' => 'zip',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/plain' => 'txt',
        ];

        return $map[$mimetype] ?? 'bin';
    }
}
