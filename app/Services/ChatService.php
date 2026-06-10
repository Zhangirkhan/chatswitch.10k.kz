<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\NewMessageReceived;
use App\Models\AiFollowUpProposal;
use App\Models\AiOrchestratorRun;
use App\Models\AiResponseLog;
use App\Models\Chat;
use App\Models\ChatAssignment;
use App\Models\Contact;
use App\Models\Department;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\AI\ChatAttentionService;
use App\Services\Funnel\ConsultationFollowUpProposalService;
use App\Services\Funnel\FunnelStageFollowUpService;
use App\Support\MediaType;
use App\Support\OutboundSenderDisplayName;
use App\Support\PhoneFormatter;
use App\Support\WhatsappMessageType;
use App\Support\TranscribeAudioJobDispatcher;
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
        bool $archivedScope = false,
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

        $query->withOperatorVisibleActivity();

        if ($filter === ChatAttentionService::FILTER_ATTENTION) {
            app(ChatAttentionService::class)->applyAttentionScope($query);

            return $query
                ->orderByDesc('is_pinned')
                ->orderByRaw('COALESCE(last_message_at, created_at) DESC');
        }

        $this->applyInboxFilter($query, $filter, $archivedScope);

        return $query
            ->orderByDesc('is_pinned')
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC');
    }

    /**
     * @param  Builder<Chat>  $query
     */
    private function applyInboxFilter(Builder $query, ?string $filter, bool $archivedScope = false): void
    {
        if ($filter === 'closed') {
            $query->where('is_lead_closed', true);

            return;
        }

        // В архиве показываем и закрытые лиды — иначе бейдж и список расходятся.
        if (! $archivedScope) {
            $query->where('is_lead_closed', false);
        }

        if ($filter === 'favorites') {
            $query->where('is_favorite', true);

            return;
        }

        if ($filter === 'auto_reply') {
            $query->where('is_group', false)
                ->where('ai_enabled', true)
                ->where('ai_mode', 'auto');
        }
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
                ->loadMissing([
                    'lastOrchestratorRun:id,confidence,status,reason,completed_at',
                    'aiFollowUpProposals' => fn ($q) => $q
                        ->select('id', 'chat_id', 'status')
                        ->where('status', \App\Models\AiFollowUpProposal::STATUS_NEEDS_MANAGER),
                ]);
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
                'chat_name' => $this->resolveInboundChatDisplayName($data),
                'is_group' => $isGroup,
                'contact_id' => $contactId,
                'ai_enabled' => false,
                'last_message_at' => now(),
            ],
        );

        // Чат был создан раньше (например, из web-интерфейса), когда contact ещё не было —
        // закрываем этот пробел, чтобы UI видел единую клиентскую базу.
        if ($contactId !== null && $chat->contact_id === null) {
            $chat->update(['contact_id' => $contactId]);
        }

        $this->inheritContactClearCutoff($chat, $contactId);

        return $chat;
    }

    private function inheritContactClearCutoff(Chat $chat, ?int $contactId): void
    {
        if ($contactId === null || $chat->messages_cleared_at !== null) {
            return;
        }

        $contactClearedAt = Contact::query()->whereKey($contactId)->value('messages_cleared_at');
        if ($contactClearedAt === null) {
            return;
        }

        $chat->forceFill([
            'messages_cleared_at' => $contactClearedAt,
            'last_message_text' => null,
            'last_message_at' => null,
            'last_message_direction' => null,
            'last_message_is_ai' => false,
            'unread_count' => 0,
        ])->save();
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

            $this->inheritContactClearCutoff($existing, $contact->id);

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
                'ai_enabled' => false,
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

        $this->inheritContactClearCutoff($chat, $contact->id);

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
        if ($digits === '' || ! PhoneFormatter::isPlausibleE164($digits)) {
            throw new \InvalidArgumentException('Invalid phone number for contact creation.');
        }

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
        $senderAuthorJid = isset($data['senderAuthorJid']) ? trim((string) $data['senderAuthorJid']) : '';
        $from = isset($data['from']) ? trim((string) $data['from']) : '';
        $chatId = isset($data['chatId']) ? trim((string) $data['chatId']) : '';
        $senderPhone = isset($data['senderPhone']) ? trim((string) $data['senderPhone']) : '';
        $senderName = isset($data['senderName']) ? trim((string) $data['senderName']) : null;
        $senderName = ($senderName !== '') ? $senderName : null;

        $isLidSender = str_ends_with(strtolower($senderAuthorJid), '@lid')
            || str_ends_with(strtolower($from), '@lid')
            || str_ends_with(strtolower($chatId), '@lid');

        if ($senderPhone !== '' && ! $isLidSender) {
            $digits = preg_replace('/\D/', '', $senderPhone);

            if ($digits !== '' && PhoneFormatter::isPlausibleE164($digits)) {
                return $this->findOrCreateContactByPhone($digits, $senderName);
            }
        }

        $whatsappId = $senderAuthorJid !== ''
            ? $senderAuthorJid
            : ($from !== '' ? $from : ($chatId !== '' ? $chatId : $senderPhone));

        if ($whatsappId === '') {
            throw new \InvalidArgumentException('Inbound contact payload is missing sender identifiers.');
        }

        if (str_ends_with(strtolower($whatsappId), '@c.us')) {
            $digits = preg_replace('/\D/', '', explode('@', $whatsappId)[0] ?? '');
            if ($digits !== '' && PhoneFormatter::isPlausibleE164($digits)) {
                return $this->findOrCreateContactByPhone($digits, $senderName);
            }
        }

        $existing = Contact::query()
            ->where('whatsapp_id', $whatsappId)
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Contact::create([
            'whatsapp_id' => $whatsappId,
            'phone_number' => '',
            'name' => $senderName,
            'push_name' => $senderName,
        ]);
    }

    public function storeInboundMessage(Chat $chat, WhatsappSession $session, array $data): ?Message
    {
        if ($this->shouldSkipInboundAfterClear($chat, $data)) {
            return null;
        }

        $type = (string) ($data['type'] ?? 'chat');
        if (WhatsappMessageType::shouldIgnoreInbound($type)) {
            throw new \InvalidArgumentException('Ignored WhatsApp service message type: '.$type);
        }

        $metadata = null;
        $isGroup = (bool) ($chat->is_group ?? ($data['isGroup'] ?? false));

        $senderPhoneRaw = isset($data['senderPhone']) ? (string) $data['senderPhone'] : null;
        $senderAuthorJid = isset($data['senderAuthorJid']) ? trim((string) $data['senderAuthorJid']) : '';
        $from = isset($data['from']) ? trim((string) $data['from']) : '';
        $chatId = isset($data['chatId']) ? trim((string) $data['chatId']) : '';
        $isLidSender = str_ends_with(strtolower($senderAuthorJid), '@lid')
            || str_ends_with(strtolower($from), '@lid')
            || str_ends_with(strtolower($chatId), '@lid');
        if ($isLidSender) {
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

        $waMessageId = isset($data['messageId']) ? trim((string) $data['messageId']) : '';
        if ($waMessageId !== '') {
            $existing = Message::query()
                ->where('whatsapp_session_id', $session->id)
                ->where('whatsapp_message_id', $waMessageId)
                ->first();

            if ($existing !== null) {
                return $existing;
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

            TranscribeAudioJobDispatcher::dispatchIfNeeded($message);
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

        $this->applyLastMessageSnapshot($chat, $message, $preview);

        $this->reopenChatAfterInbound($chat);
        app(\App\Services\Chat\ChatLeadClosureService::class)->reopenAfterInboundIfNeeded($chat);

        // Atomic increment — избегаем race condition при параллельных webhook'ах.
        $chat->increment('unread_count');

        if (! $chat->contact_id) {
            $contact = $this->findOrCreateContact($data);
            $chat->update(['contact_id' => $contact->id]);
        }

        app(FunnelStageFollowUpService::class)->cancelPendingForChat($chat);
        app(\App\Services\Funnel\ConsultationFollowUpProposalService::class)->dismissPendingForChat($chat);

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
        $chat->loadMissing('company');

        $message = Message::create([
            'chat_id' => $chat->id,
            'whatsapp_session_id' => $session->id,
            'whatsapp_message_id' => $waMessageId,
            'direction' => 'outbound',
            'type' => 'chat',
            'body' => $body,
            'metadata' => $metadata,
            'sent_by_user_id' => $user->id,
            'sender_name' => OutboundSenderDisplayName::resolve($user, $chat, $metadata),
            'quoted_message_id' => $quotedMessageId,
            'ack' => $waMessageId ? 'sent' : 'pending',
            'message_timestamp' => now(),
        ]);

        $this->applyLastMessageSnapshot($chat, $message, Str::limit($body, 200));

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
     * Удаляет все сообщения чата и сбрасывает превью в списке.
     * Старые сообщения из WhatsApp (sync/webhook) после этого не импортируются.
     */
    public function clearChatMessages(Chat $chat): void
    {
        Message::query()->where('chat_id', $chat->id)->delete();
        $this->resetChatAiArtifacts($chat);
        $chat->forceFill([
            'last_message_text' => null,
            'last_message_at' => null,
            'last_message_direction' => null,
            'last_message_is_ai' => false,
            'unread_count' => 0,
            'pinned_message_id' => null,
            'messages_cleared_at' => now(),
        ])->save();
    }

    private function resetChatAiArtifacts(Chat $chat): void
    {
        AiResponseLog::query()->where('chat_id', $chat->id)->delete();
        AiOrchestratorRun::query()->where('chat_id', $chat->id)->delete();
        AiFollowUpProposal::query()->where('chat_id', $chat->id)->delete();

        app(ConsultationFollowUpProposalService::class)->dismissPendingForChat($chat);

        $chat->forceFill([
            'ai_orchestrator_status' => null,
            'ai_orchestrator_last_run_id' => null,
            'ai_orchestrator_last_action_at' => null,
            'ai_orchestrator_last_summary' => null,
            'funnel_ai_last_analyzed_at' => null,
            'funnel_ai_last_message_id' => null,
            'funnel_ai_last_reason' => null,
        ])->save();
    }

    /**
     * Не даём whatsapp-service (syncMissedInbound) вернуть историю после «Очистить клиента».
     */
    public function shouldSkipInboundAfterClear(Chat $chat, array $data): bool
    {
        $clearedAt = $this->resolveInboundClearCutoff($chat);
        if ($clearedAt === null) {
            return false;
        }

        $timestamp = isset($data['timestamp']) ? (int) $data['timestamp'] : null;
        $messageAt = $timestamp !== null && $timestamp > 0
            ? now()->setTimestamp($timestamp)
            : now();

        return $messageAt->lt($clearedAt);
    }

    /**
     * После «Очистить клиента» новые входящие принимаем, но cutoff на контакте
     * остаётся — он блокирует только resync старых сообщений из WhatsApp.
     */
    private function reopenChatAfterInbound(Chat $chat): void
    {
        if ($chat->messages_cleared_at === null) {
            return;
        }

        $chat->forceFill(['messages_cleared_at' => null])->save();
    }

    public function resolveInboundClearCutoff(Chat $chat): ?\Illuminate\Support\Carbon
    {
        $cutoffs = array_filter([
            $chat->messages_cleared_at,
            $chat->relationLoaded('contact')
                ? $chat->contact?->messages_cleared_at
                : ($chat->contact_id !== null
                    ? Contact::query()->whereKey($chat->contact_id)->value('messages_cleared_at')
                    : null),
        ]);

        if ($cutoffs === []) {
            return null;
        }

        return collect($cutoffs)->max();
    }

    /**
     * Пересчитывает превью последнего сообщения в чате (после удаления сообщения и т.п.).
     */
    public function refreshChatLastMessageSnapshot(Chat $chat): void
    {
        $last = Message::query()
            ->where('chat_id', $chat->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->tap(fn (Builder $query) => WhatsappMessageType::applyOperatorVisibleScope($query))
            ->orderByDesc('message_timestamp')
            ->orderByDesc('id')
            ->first();

        if ($last === null) {
            $chat->update([
                'last_message_text' => null,
                'last_message_at' => null,
                'last_message_direction' => null,
                'last_message_is_ai' => false,
            ]);

            return;
        }

        $preview = trim((string) ($last->body ?? ''));
        if ($preview === '') {
            $preview = $last->type !== 'chat'
                ? MediaType::previewText($last->type, null)
                : '';
        }

        $this->applyLastMessageSnapshot($chat, $last, $preview);
    }

    private function applyLastMessageSnapshot(Chat $chat, Message $message, string $preview): void
    {
        $chat->forceFill([
            'last_message_text' => Str::limit($preview, 200),
            'last_message_at' => $message->message_timestamp,
            'last_message_direction' => $message->direction,
            'last_message_is_ai' => $message->direction === 'outbound'
                && data_get($message->metadata, 'ai.generated') === true,
        ])->save();
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

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveInboundChatDisplayName(array $data): string
    {
        $chatId = strtolower(trim((string) ($data['chatId'] ?? '')));
        $isLidPeer = ! (($data['isGroup'] ?? false)) && str_ends_with($chatId, '@lid');

        $senderName = trim((string) ($data['senderName'] ?? ''));
        $chatName = trim((string) ($data['chatName'] ?? ''));
        $from = trim((string) ($data['from'] ?? ''));

        if ($isLidPeer) {
            foreach ([$senderName, $chatName] as $candidate) {
                if ($candidate === '' || str_contains($candidate, '@')) {
                    continue;
                }

                $normalized = PhoneFormatter::normalize($candidate);
                if ($normalized !== null && PhoneFormatter::isPlausibleE164($normalized)) {
                    continue;
                }

                return $candidate;
            }

            return 'Контакт WhatsApp';
        }

        if ($chatName !== '') {
            return $chatName;
        }

        return $from !== '' ? $from : 'Unknown';
    }

    /**
     * Удаляет служебные WA-сообщения и чаты без реальной переписки.
     *
     * @return array{ignored_messages: int, deleted_chats: int, fixed_contacts: int}
     */
    public function pruneGhostWhatsappChats(): array
    {
        $ignoredMessages = Message::query()
            ->whereIn('type', [
                'e2e_notification',
                'protocol',
                'gp2',
                'notification',
                'notification_template',
                'broadcast_notification',
                'call_log',
                'ciphertext',
                'debug',
                'hsm',
            ])
            ->delete();

        $fixedContacts = 0;
        Contact::query()
            ->where(function (Builder $query): void {
                $query->where('whatsapp_id', 'like', '%@lid')
                    ->orWhereNotNull('phone_number');
            })
            ->orderBy('id')
            ->chunkById(200, function ($contacts) use (&$fixedContacts): void {
                foreach ($contacts as $contact) {
                    $shouldClearPhone = str_ends_with(strtolower((string) $contact->whatsapp_id), '@lid');
                    if (! $shouldClearPhone && $contact->phone_number !== null) {
                        $phone = PhoneFormatter::normalize($contact->phone_number);
                        $shouldClearPhone = $phone !== null && ! PhoneFormatter::isPlausibleE164($phone);
                    }

                    if ($shouldClearPhone && $contact->phone_number !== '') {
                        $contact->update(['phone_number' => '']);
                        $fixedContacts++;
                    }
                }
            });

        Chat::query()
            ->where('whatsapp_chat_id', 'like', '%@lid')
            ->orderBy('id')
            ->chunkById(100, function ($chats): void {
                foreach ($chats as $chat) {
                    $name = trim((string) $chat->chat_name);
                    $normalized = PhoneFormatter::normalize($name);
                    if ($name === '' || ($normalized !== null && PhoneFormatter::isPlausibleE164($normalized))) {
                        $chat->update(['chat_name' => 'Контакт WhatsApp']);
                    }
                }
            });

        $deletedChats = 0;
        Chat::query()
            ->orderBy('id')
            ->chunkById(100, function ($chats) use (&$deletedChats): void {
                foreach ($chats as $chat) {
                    $this->refreshChatLastMessageSnapshot($chat);

                    if ($chat->messages_cleared_at !== null) {
                        continue;
                    }

                    if ($chat->contact_id !== null
                        && Contact::query()->whereKey($chat->contact_id)->whereNotNull('messages_cleared_at')->exists()) {
                        continue;
                    }

                    if ($this->chatHasRealConversation($chat)) {
                        continue;
                    }

                    Message::query()->where('chat_id', $chat->id)->delete();
                    $chat->delete();
                    $deletedChats++;
                }
            });

        return [
            'ignored_messages' => $ignoredMessages,
            'deleted_chats' => $deletedChats,
            'fixed_contacts' => $fixedContacts,
        ];
    }

    public function chatHasRealConversation(Chat $chat): bool
    {
        if ($chat->is_group) {
            return Message::query()
                ->where('chat_id', $chat->id)
                ->tap(fn (Builder $query) => WhatsappMessageType::applyOperatorVisibleScope($query))
                ->exists();
        }

        $hasInbound = Message::query()
            ->where('chat_id', $chat->id)
            ->where('direction', 'inbound')
            ->tap(fn (Builder $query) => WhatsappMessageType::applyOperatorVisibleScope($query))
            ->exists();

        if ($hasInbound) {
            return true;
        }

        return Message::query()
            ->where('chat_id', $chat->id)
            ->humanOutbound()
            ->exists();
    }
}
