<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NewMessageReceived;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\ChatAttentionService;
use App\Services\Funnel\FunnelStageFollowUpService;
use App\Support\MediaType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ChatService
{
    private function normalizePhoneDigits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $value);

        return is_string($digits) && $digits !== '' ? $digits : null;
    }

    /**
     * Отсекаем внутренние id WhatsApp (@lid), ошибочно сохранённые как «телефон».
     * NANP (+1) — ровно 11 цифр; RU/KZ (+7) — 10–12 цифр после нормализации.
     */
    private function senderDigitsLookLikeReachablePhone(?string $digits): bool
    {
        if ($digits === null || $digits === '' || ! ctype_digit($digits)) {
            return false;
        }
        $len = strlen($digits);
        if ($len < 10 || $len > 15) {
            return false;
        }
        if (str_starts_with($digits, '1')) {
            return $len === 11;
        }
        if (str_starts_with($digits, '7')) {
            return $len >= 10 && $len <= 12;
        }

        return true;
    }

    private function findExistingContactByPhoneOrWaId(?string $raw): ?Contact
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        $digits = $this->normalizePhoneDigits($raw);
        $waVariants = array_values(array_unique(array_filter([
            $raw,
            $digits,
            $digits ? "{$digits}@c.us" : null,
        ])));

        return Contact::query()
            ->where(function (Builder $q) use ($digits, $waVariants): void {
                if ($digits) {
                    $q->where('phone_number', $digits);
                }
                $q->orWhereIn('whatsapp_id', $waVariants);
            })
            ->first();
    }

    /**
     * Backfill sender names in group message history.
     *
     * For inbound messages in group chats we prefer the name as saved in our contacts.
     * This method updates existing messages so UI shows your saved contact names.
     *
     * @return array{scanned:int, updated:int}
     */
    public function resyncGroupSenderNames(Chat $chat, bool $dryRun = false): array
    {
        if (! $chat->is_group) {
            return ['scanned' => 0, 'updated' => 0];
        }

        $scanned = 0;
        $updated = 0;

        Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->whereNotNull('sender_phone')
            ->orderBy('id')
            ->cursor()
            ->each(function (Message $m) use (&$scanned, &$updated, $dryRun): void {
                $scanned++;
                $contact = $this->findExistingContactByPhoneOrWaId($m->sender_phone);
                $name = $contact?->name ? trim((string) $contact->name) : null;
                if (! $name) {
                    return;
                }
                if ((string) $m->sender_name === $name) {
                    return;
                }
                $updated++;
                if (! $dryRun) {
                    $m->sender_name = $name;
                    $m->saveQuietly();
                }
            });

        return ['scanned' => $scanned, 'updated' => $updated];
    }

    public function getChatsForUser(
        User $user,
        ?string $search = null,
        string $listOwnership = 'all',
        ?string $filter = null,
    ): Builder {
        // Закреплённые — сверху; затем по времени последней активности.
        // COALESCE нужен, чтобы только что созданные чаты (без сообщений)
        // сортировались по created_at и попадали в самый верх списка.
        $query = Chat::with([
            'contact',
            'whatsappSession:id,session_name,display_name,display_color,phone_number,status',
            'assignments.user',
            // Нужно фронту для превью последнего сообщения (иконка + «Фото»/«Видео»/
            // «Голосовое (0:12)»). Media подтягиваем, чтобы показать имя файла для
            // документов. latestMessage без урезанного select: иначе latestOfMany + paginate()
            // даёт MySQL 1052 Column 'chat_id' in SELECT is ambiguous.
            'latestMessage',
            'latestMessage.media:id,message_id,mime_type,filename',
        ]);

        $query = $this->applyChatVisibilityForUser($query, $user, $search, $listOwnership);

        if ($filter === ChatAttentionService::FILTER_ATTENTION) {
            app(ChatAttentionService::class)->applyAttentionScope($query);

            return $query;
        }

        return $query
            ->orderByDesc('is_pinned')
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC');
    }

    /**
     * Чаты, доступные пользователю (без фильтра списка и сортировки).
     *
     * @return Builder<Chat>
     */
    public function queryVisibleToUser(User $user): Builder
    {
        return $this->applyChatVisibilityForUser(Chat::query(), $user, null, 'all');
    }

    /**
     * @param  Builder<Chat>  $query
     * @return Builder<Chat>
     */
    private function applyChatVisibilityForUser(
        Builder $query,
        User $user,
        ?string $search,
        string $listOwnership,
    ): Builder {
        if ($listOwnership === 'mine' && ($user->hasRole('administrator') || $user->hasRole('manager'))) {
            $query->whereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id));
        }

        if ($user->hasRole('administrator')) {
            // sees all chats
        } elseif ($user->hasRole('manager')) {
            // Множественное членство: учитываем все отделы руководителя.
            //  • чаты, назначенные любому сотруднику любого из его отделов;
            //  • ИЛИ чаты, где прикреплён ХОТЬ ОДИН его отдел — независимо от назначения.
            $userDeptIds = $user->departmentIds();
            $departmentUserIds = $userDeptIds === []
                ? collect()
                : User::query()
                    ->whereHas('departments', static fn (Builder $q) => $q->whereIn('departments.id', $userDeptIds))
                    ->pluck('id');

            $query->where(function (Builder $q) use ($departmentUserIds, $userDeptIds): void {
                $q->whereHas('assignments', fn (Builder $aq) => $aq->whereIn('user_id', $departmentUserIds));
                if ($userDeptIds !== []) {
                    $q->orWhereHas('departments', fn (Builder $dq) => $dq->whereIn('departments.id', $userDeptIds));
                }
            });
        } else {
            // Рядовой сотрудник видит:
            //  • чаты, где он лично назначен;
            //  • ИЛИ чаты без назначенных, где прикреплён ЛЮБОЙ из его отделов — «общий пул».
            $userDeptIds = $user->departmentIds();
            $query->where(function (Builder $q) use ($user, $userDeptIds): void {
                $q->whereHas('assignments', fn (Builder $aq) => $aq->where('user_id', $user->id));
                if ($userDeptIds !== []) {
                    $q->orWhere(function (Builder $dq) use ($userDeptIds): void {
                        $dq->whereDoesntHave('assignments')
                            ->whereHas('departments', fn (Builder $ddq) => $ddq->whereIn('departments.id', $userDeptIds));
                    });
                }
            });
        }

        $search = is_string($search) ? trim($search) : null;

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
     * @param  list<Chat>  $chats
     */
    public function enrichAttentionMeta(array $chats): void
    {
        $chatModels = collect($chats)
            ->filter(fn ($chat): bool => $chat instanceof Chat)
            ->values()
            ->all();

        if ($chatModels !== []) {
            (new Collection($chatModels))
                ->loadMissing('lastOrchestratorRun:id,confidence,status,reason,completed_at');
        }

        $service = app(ChatAttentionService::class);
        foreach ($chats as $chat) {
            if (! $chat instanceof Chat) {
                continue;
            }
            $meta = $service->describe($chat);
            $chat->setAttribute('attention_reason', $meta['reason']);
            $chat->setAttribute('attention_severity', $meta['severity']);
        }
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
        $phoneDigits = $this->normalizePhoneDigits($contact->phone_number);

        // Build whatsapp_chat_id candidates: both @c.us and @lid numeric variants.
        $candidates = array_values(array_unique(array_filter([
            $contact->whatsapp_id,
            $contact->phone_number,
            $digits,
            $phoneDigits,
            $digits ? "{$digits}@c.us" : null,
            $phoneDigits ? "{$phoneDigits}@c.us" : null,
        ])));

        $existing = Chat::query()
            ->where('whatsapp_session_id', $session->id)
            ->where('is_group', false)
            ->where(function (Builder $q) use ($contact, $candidates): void {
                $q->where('contact_id', $contact->id);
                if ($candidates !== []) {
                    $q->orWhereIn('whatsapp_chat_id', $candidates);
                }
            })
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        // Fallback: find any chat on this session whose contact shares the same phone number.
        // This handles the @lid ↔ @c.us mismatch: the @lid whatsapp_chat_id numeric part
        // has nothing to do with the phone number, so the candidates list above won't cover it.
        if ($existing === null && $phoneDigits !== null) {
            $existing = Chat::query()
                ->where('whatsapp_session_id', $session->id)
                ->where('is_group', false)
                ->whereHas('contact', fn (Builder $q) => $q->where('phone_number', $phoneDigits))
                ->orderByDesc('last_message_at')
                ->orderByDesc('id')
                ->first();
        }

        if ($existing !== null) {
            if (! $existing->contact_id) {
                $existing->update(['contact_id' => $contact->id]);
            }

            return $existing;
        }

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

    /**
     * Чат для пересылки: если с контактом уже есть диалог на этой WA-сессии — используем его
     * (правильный whatsapp_chat_id, в т.ч. @lid). Иначе — {@see findOrCreateChatForContact()}.
     */
    public function findForwardTargetChatForContact(Contact $contact, WhatsappSession $session): Chat
    {
        $existing = Chat::query()
            ->where('contact_id', $contact->id)
            ->where('whatsapp_session_id', $session->id)
            ->where('is_group', false)
            ->whereNotNull('whatsapp_chat_id')
            ->where('whatsapp_chat_id', '!=', '')
            ->orderByDesc('id')
            ->first();

        return $existing ?? $this->findOrCreateChatForContact($contact, $session);
    }

    public function findOrCreateContactByPhone(string $phone, ?string $name = null): Contact
    {
        $digits = preg_replace('/\D/', '', $phone);

        // 1. Prefer existing contact by phone_number (covers @lid contacts whose phone was extracted)
        $existing = Contact::where('phone_number', $digits)
            ->orderByDesc('id')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        // 2. Match by whatsapp_id variants (plain digits or @c.us)
        $existing = Contact::where(function (Builder $q) use ($digits): void {
            $q->where('whatsapp_id', $digits)
                ->orWhere('whatsapp_id', "{$digits}@c.us");
        })->orderByDesc('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        // 3. Create a new contact
        return Contact::create([
            'whatsapp_id' => $digits,
            'phone_number' => $digits,
            'name' => $name,
            'push_name' => $name,
        ]);
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
        $isGroup = (bool) ($chat->is_group ?? ($data['isGroup'] ?? false));

        $senderPhoneRaw = isset($data['senderPhone']) ? (string) $data['senderPhone'] : null;
        $senderAuthorJid = isset($data['senderAuthorJid']) ? trim((string) $data['senderAuthorJid']) : '';
        if ($senderAuthorJid !== '' && str_ends_with(strtolower($senderAuthorJid), '@lid')) {
            $senderPhoneRaw = null;
        }
        $senderNameRaw = isset($data['senderName']) ? (string) $data['senderName'] : null;

        // For group messages we prefer the name as saved in our contacts.
        // If not saved, we keep WhatsApp push name. UI will render "~ {name} · {phone}".
        $senderContact = $isGroup ? $this->findExistingContactByPhoneOrWaId($senderPhoneRaw) : null;
        $resolvedSenderName = null;
        if ($senderContact && $senderContact->name) {
            $resolvedSenderName = $senderContact->name;
        } elseif (is_string($senderNameRaw) && trim($senderNameRaw) !== '') {
            $resolvedSenderName = trim($senderNameRaw);
        }

        $normalizedSenderPhone = $this->normalizePhoneDigits($senderPhoneRaw);
        if ($normalizedSenderPhone !== null && ! $this->senderDigitsLookLikeReachablePhone($normalizedSenderPhone)) {
            $normalizedSenderPhone = null;
        }

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
            'sender_phone' => $normalizedSenderPhone,
            'sender_name' => $resolvedSenderName,
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

        app(FunnelStageFollowUpService::class)->cancelPendingForChat($chat);

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

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function storeOutboundMessage(Chat $chat, WhatsappSession $session, User $user, string $body, ?string $waMessageId = null, ?string $quotedMessageId = null, ?array $metadata = null): Message
    {
        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => $waMessageId,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => $body,
            'metadata' => $metadata,
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

        $this->releaseAdministratorIfSoleAssigneeOnOutbound($chat, $user);
        $this->attachAdministratorWhenJoiningStaffedChat($chat, $user);

        return $message;
    }

    /**
     * Если в чате уже есть закреплённые сотрудники, исходящее сообщение администратора
     * добавляет его в chat_assignments — имя появляется в плашке ответственных в списке чатов.
     */
    private function attachAdministratorWhenJoiningStaffedChat(Chat $chat, User $user): void
    {
        if (! $user->hasRole('administrator')) {
            return;
        }

        if (! $chat->assignments()->exists()) {
            return;
        }

        if ($chat->assignments()->where('user_id', $user->id)->exists()) {
            return;
        }

        $oldIds = $chat->assignments()->pluck('user_id')->all();

        ChatAssignment::firstOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $user->id],
            ['assigned_by' => $user->id],
        );

        $newIds = $chat->assignments()->pluck('user_id')->all();
        $this->logAssignmentChange($chat, $user, $oldIds, $newIds);
    }

    /**
     * Ответ администратора не должен оставлять его единственным «ответственным» за чат:
     * если в chat_assignments только он — запись снимается (супервизия без фиктивного захвата).
     * При нескольких ответственных или если админ не назначен — не меняем.
     */
    private function releaseAdministratorIfSoleAssigneeOnOutbound(Chat $chat, User $user): void
    {
        if (! $user->hasRole('administrator')) {
            return;
        }

        $assignments = $chat->assignments()->get(['id', 'user_id']);
        if ($assignments->count() !== 1) {
            return;
        }

        $row = $assignments->first();
        if ($row !== null && (int) $row->user_id === (int) $user->id) {
            ChatAssignment::query()->whereKey($row->id)->delete();
            $this->logAssignmentChange($chat, $user, [(int) $row->user_id], []);
        }
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

    /**
     * Подбирает другую подключённую сессию при удалении одной из них.
     * Сначала — сессия, с которой пересекаются те же пользователи (pivot), иначе любая connected.
     */
    public function findReplacementWhatsappSession(?WhatsappSession $removing): ?WhatsappSession
    {
        if ($removing !== null) {
            $userIds = $removing->users()->pluck('users.id')->all();
            if ($userIds !== []) {
                $preferred = WhatsappSession::query()
                    ->where('status', 'connected')
                    ->whereKeyNot($removing->id)
                    ->whereHas('users', fn (Builder $uq) => $uq->whereIn('users.id', $userIds))
                    ->orderBy('id')
                    ->first();
                if ($preferred !== null) {
                    return $preferred;
                }
            }

            return WhatsappSession::query()
                ->where('status', 'connected')
                ->whereKeyNot($removing->id)
                ->orderBy('id')
                ->first();
        }

        return WhatsappSession::query()
            ->where('status', 'connected')
            ->orderBy('id')
            ->first();
    }

    /**
     * Перед удалением WA-сессии: группы остаются рабочими, если есть куда их перенести.
     *
     * @return list<int>
     */
    public function migrateGroupChatsToReplacementSession(WhatsappSession $removing, ?WhatsappSession $replacement): array
    {
        if ($replacement === null) {
            return [];
        }

        $reattachedIds = [];

        $chats = Chat::query()
            ->where('whatsapp_session_id', $removing->id)
            ->where('is_group', true)
            ->get();

        foreach ($chats as $chat) {
            $conflict = Chat::query()
                ->where('whatsapp_chat_id', $chat->whatsapp_chat_id)
                ->where('whatsapp_session_id', $replacement->id)
                ->whereKeyNot($chat->id)
                ->exists();

            if ($conflict) {
                continue;
            }

            $chat->update(['whatsapp_session_id' => $replacement->id]);
            $reattachedIds[] = (int) $chat->id;
        }

        return $reattachedIds;
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
