<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NewMessageReceived;
use App\Models\Chat;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Support\MediaType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ChatService
{
    public function getChatsForUser(User $user, ?string $search = null): Builder
    {
        // Закреплённые — сверху; затем по времени последней активности.
        // COALESCE нужен, чтобы только что созданные чаты (без сообщений)
        // сортировались по created_at и попадали в самый верх списка.
        $query = Chat::with([
            'contact',
            'whatsappSession',
            'assignments.user',
            // Нужно фронту для превью последнего сообщения (иконка + «Фото»/«Видео»/
            // «Голосовое (0:12)»). Media подтягиваем, чтобы показать имя файла для
            // документов. Поля ограничены — нам важен только тип/подпись/медиа.
            'latestMessage:id,chat_id,type,body,direction,metadata,message_timestamp',
            'latestMessage.media:id,message_id,mime_type,filename',
        ])
            ->orderByDesc('is_pinned')
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC');

        if ($user->hasRole('administrator')) {
            // sees all chats
        } elseif ($user->hasRole('manager')) {
            // Руководитель отдела видит всё, что относится к его отделу:
            //  • чаты, назначенные любому сотруднику его отдела (супервизит своих);
            //  • ИЛИ чаты, где его отдел прикреплён pill'ом — безусловно,
            //    даже если ответственный из другого отдела (супервизит чаты отдела).
            $departmentUserIds = User::where('department_id', $user->department_id)->pluck('id');
            $query->where(function (Builder $q) use ($departmentUserIds, $user): void {
                $q->whereHas('assignments', fn (Builder $aq) => $aq->whereIn('user_id', $departmentUserIds));
                if ($user->department_id !== null) {
                    $q->orWhereHas('departments', fn (Builder $dq) => $dq->where('departments.id', $user->department_id));
                }
            });
        } else {
            // Рядовой сотрудник видит:
            //  • чаты, где он лично назначен — он за них отвечает;
            //  • ИЛИ чаты без назначенных, где прикреплён его отдел — «общий пул», который любой из отдела может взять.
            $query->where(function (Builder $q) use ($user): void {
                $q->whereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id));
                if ($user->department_id !== null) {
                    $q->orWhere(function (Builder $dq) use ($user): void {
                        $dq->whereDoesntHave('assignments')
                            ->whereHas('departments', fn (Builder $ddq) => $ddq->where('departments.id', $user->department_id));
                    });
                }
            });
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

    /**
     * Находит/создаёт чат для входящего сообщения и сразу привязывает его
     * к единому контакту (по whatsapp_id). Это гарантирует, что один клиент,
     * написавший на разные WA-сессии, будет одним Contact, но несколькими Chat.
     */
    public function findOrCreateChat(array $data, WhatsappSession $session): Chat
    {
        $isGroup = (bool) ($data['isGroup'] ?? false);
        $contactId = null;

        // Для группы contact не имеет смысла — там много участников.
        // Для 1:1 чата сразу же находим/создаём Contact и привязываем.
        if (! $isGroup) {
            $contactId = $this->findOrCreateContact($data)->id;
        }

        $chat = Chat::firstOrCreate(
            [
                'whatsapp_chat_id' => $data['chatId'],
                'whatsapp_session_id' => $session->id,
            ],
            [
                'chat_name' => $data['chatName'] ?? $data['from'] ?? 'Unknown',
                'is_group' => $isGroup,
                'contact_id' => $contactId,
                'last_message_at' => now(),
            ],
        );

        // Чат был создан раньше (например, из web-интерфейса), когда contact ещё не было —
        // закрываем этот пробел, чтобы UI видел единую клиентскую базу.
        if ($contactId !== null && $chat->contact_id === null) {
            $chat->update(['contact_id' => $contactId]);
        }

        return $chat;
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
                'last_message_at' => now(),
            ],
        );

        if (! $chat->contact_id) {
            $chat->update(['contact_id' => $contact->id]);
        }

        // Только что возобновили существующий пустой чат — подтягиваем его наверх.
        if ($chat->wasRecentlyCreated === false && $chat->last_message_at === null) {
            $chat->update(['last_message_at' => now()]);
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
        $type = (string) ($data['type'] ?? 'chat');
        $metadata = null;

        // Голосовые/аудио — whatsapp-service прокидывает длительность в секундах,
        // чтобы в превью чата показать «Голосовое сообщение (0:12)».
        if (isset($data['mediaDuration'])) {
            $duration = (int) $data['mediaDuration'];
            if ($duration >= 0) {
                $metadata = ['media' => ['duration' => $duration]];
            }
        }

        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => $data['messageId'] ?? null,
            'direction' => 'inbound',
            'type' => $type,
            'body' => $data['body'] ?? '',
            'metadata' => $metadata,
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

        // Для превью чата: если есть caption — используем его; иначе локализованная
        // плашка типа «📷 Фото». Фоллбек «[Media]» был нелокализован и попадал
        // в список, когда downloadMedia не срабатывал — теперь всегда даём
        // осмысленный русский текст (а фронт поверх может нарисовать иконку).
        $caption = trim((string) ($data['body'] ?? ''));
        if ($caption !== '') {
            $preview = $caption;
        } elseif ($type !== 'chat') {
            $preview = MediaType::previewText($type, null);
        } else {
            $preview = '';
        }

        $chat->forceFill([
            'last_message_text' => Str::limit($preview, 200),
            'last_message_at' => $message->message_timestamp,
            'last_message_direction' => 'inbound',
        ])->save();

        // Atomic increment — избегаем race condition при параллельных webhook'ах.
        $chat->increment('unread_count');

        if (! $chat->contact_id) {
            $contact = $this->findOrCreateContact($data);
            $chat->update(['contact_id' => $contact->id]);
        }

        return $message;
    }

    /**
     * Создаёт системное сообщение в чате и рассылает его операторам через Echo.
     *
     * ВАЖНО: такие сообщения существуют только у нас в базе и НЕ отправляются
     * через whatsapp-сервис — клиент их никогда не увидит.
     */
    public function logSystemMessage(Chat $chat, string $body): Message
    {
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => null,
            'direction' => 'system',
            'type' => 'chat',
            'body' => $body,
            'ack' => 'read',
            'message_timestamp' => now(),
        ]);

        $message->load(['media', 'sentByUser', 'whatsappSession', 'reactions.user:id,name']);
        broadcast(new NewMessageReceived($message, $chat->id));

        return $message;
    }

    /**
     * Формирует и логирует системное сообщение об изменении набора отделов.
     *
     * @param  list<int>  $oldIds
     * @param  list<int>  $newIds
     */
    public function logDepartmentChange(Chat $chat, User $actor, array $oldIds, array $newIds): ?Message
    {
        $addedIds = array_values(array_diff($newIds, $oldIds));
        $removedIds = array_values(array_diff($oldIds, $newIds));

        if ($addedIds === [] && $removedIds === []) {
            return null;
        }

        $names = Department::whereIn('id', array_unique(array_merge($addedIds, $removedIds)))
            ->pluck('name', 'id');

        $parts = [];
        if ($addedIds !== []) {
            $added = collect($addedIds)->map(fn (int $id) => '«'.($names[$id] ?? "#{$id}").'»')->implode(', ');
            $parts[] = 'добавлен'.(count($addedIds) > 1 ? 'ы' : '').' '.$added;
        }
        if ($removedIds !== []) {
            $removed = collect($removedIds)->map(fn (int $id) => '«'.($names[$id] ?? "#{$id}").'»')->implode(', ');
            $parts[] = 'убран'.(count($removedIds) > 1 ? 'ы' : '').' '.$removed;
        }

        $body = 'Отделы чата обновлены: '.implode('; ', $parts).". Изменил: {$actor->name}.";

        return $this->logSystemMessage($chat, $body);
    }

    /**
     * Логирует изменение набора ответственных за чат.
     *
     * @param  list<int>  $oldIds
     * @param  list<int>  $newIds
     */
    public function logAssignmentChange(Chat $chat, User $actor, array $oldIds, array $newIds): ?Message
    {
        $addedIds = array_values(array_diff($newIds, $oldIds));
        $removedIds = array_values(array_diff($oldIds, $newIds));

        if ($addedIds === [] && $removedIds === []) {
            return null;
        }

        $names = User::whereIn('id', array_unique(array_merge($addedIds, $removedIds)))
            ->pluck('name', 'id');

        $parts = [];
        if ($addedIds !== []) {
            $added = collect($addedIds)->map(fn (int $id) => '«'.($names[$id] ?? "#{$id}").'»')->implode(', ');
            $parts[] = 'назначен'.(count($addedIds) > 1 ? 'ы' : '').' '.$added;
        }
        if ($removedIds !== []) {
            $removed = collect($removedIds)->map(fn (int $id) => '«'.($names[$id] ?? "#{$id}").'»')->implode(', ');
            $parts[] = 'снят'.(count($removedIds) > 1 ? 'ы' : '').' '.$removed;
        }

        $body = 'Ответственные за чат обновлены: '.implode('; ', $parts).". Изменил: {$actor->name}.";

        return $this->logSystemMessage($chat, $body);
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
            'last_message_direction' => 'outbound',
        ]);

        return $message;
    }

    /**
     * Пересчитывает превью последнего сообщения в чате (после удаления сообщения и т.п.).
     */
    public function refreshChatLastMessageSnapshot(Chat $chat): void
    {
        $last = Message::query()
            ->where('chat_id', $chat->id)
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->first();

        if ($last === null) {
            $chat->update([
                'last_message_text' => null,
                'last_message_at' => null,
                'last_message_direction' => null,
            ]);

            return;
        }

        $preview = trim((string) ($last->body ?? ''));
        if ($preview === '') {
            $preview = $last->type !== 'chat'
                ? MediaType::previewText($last->type, null)
                : '';
        }

        $chat->update([
            'last_message_text' => Str::limit($preview, 200),
            'last_message_at' => $last->message_timestamp,
            'last_message_direction' => $last->direction,
        ]);
    }

    public function storeOutboundMedia(Message $message, string $binary, string $mimetype, ?string $filename): MessageMedia
    {
        $ext = $this->mimeToExtension($mimetype);
        $storedFilename = $filename ?: (Str::uuid()->toString().".{$ext}");
        $path = 'whatsapp-media/'.date('Y/m').'/'.Str::uuid()->toString().".{$ext}";

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
        $storedFilename = $filename ?: (Str::uuid()->toString().".{$ext}");
        $path = 'whatsapp-media/'.date('Y/m')."/{$storedFilename}";

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
