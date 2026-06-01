<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageMedia;
use App\Models\User;
use App\Support\ChatUrl;
use App\Support\PhoneFormatter;
use Illuminate\Support\Facades\DB;

final class ContactCardAssembler
{
    public function __construct(
        private readonly ContactCardCrmService $crm,
        private readonly ContactBucketResolver $buckets,
    ) {}

    /**
     * @return array{
     *     identity: array<string, mixed>,
     *     activity: array<string, mixed>,
     *     channels: list<array<string, mixed>>,
     *     crm: array<string, mixed>
     * }
     */
    public function build(User $user, Contact $contact, ?int $preferredChatId = null): array
    {
        $contactIds = $this->buckets->bucketIds($contact);

        $chats = Chat::query()
            ->with([
                'whatsappSession:id,session_name,display_name,phone_number,status',
                'funnel:id,name,color',
                'funnelStage:id,name,color,position,funnel_id',
                'assignments.user:id,name',
            ])
            ->whereIn('contact_id', $contactIds)
            ->where('is_group', false)
            ->orderByDesc('last_message_at')
            ->get([
                'id',
                'contact_id',
                'whatsapp_session_id',
                'chat_name',
                'last_message_text',
                'last_message_at',
                'last_message_direction',
                'is_archived',
                'unread_count',
                'funnel_id',
                'funnel_stage_id',
                'ai_enabled',
                'ai_mode',
                'ai_orchestrator_status',
                'ai_orchestrator_last_summary',
                'funnel_ai_last_reason',
            ])
            ->filter(fn (Chat $chat): bool => $user->can('view', $chat))
            ->values();

        $chatIds = $chats->pluck('id')->map(fn ($id) => (int) $id)->all();
        $latestChat = $chats->first();

        $messagesBase = Message::query()->whereIn('chat_id', $chatIds);
        $firstMessage = (clone $messagesBase)
            ->orderByRaw('COALESCE(message_timestamp, created_at)')
            ->orderBy('id')
            ->first(['id', 'body', 'direction', 'sender_name', 'message_timestamp', 'created_at']);
        $lastInbound = (clone $messagesBase)
            ->where('direction', 'inbound')
            ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
            ->orderByDesc('id')
            ->first(['id', 'body', 'direction', 'sender_name', 'message_timestamp', 'created_at']);
        $lastOutbound = (clone $messagesBase)
            ->where('direction', 'outbound')
            ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
            ->orderByDesc('id')
            ->first(['id', 'body', 'direction', 'sender_name', 'message_timestamp', 'created_at']);

        $messageCounts = [
            'total' => $chatIds === [] ? 0 : (clone $messagesBase)->count(),
            'inbound' => $chatIds === [] ? 0 : (clone $messagesBase)->where('direction', 'inbound')->count(),
            'outbound' => $chatIds === [] ? 0 : (clone $messagesBase)->where('direction', 'outbound')->count(),
        ];

        $mediaCounts = ['media' => 0, 'documents' => 0, 'links' => 0];
        if ($chatIds !== []) {
            $mediaCounts['media'] = MessageMedia::query()
                ->whereHas('message', fn ($q) => $q->whereIn('chat_id', $chatIds))
                ->where(function ($q): void {
                    $q->where('mime_type', 'like', 'image/%')
                        ->orWhere('mime_type', 'like', 'video/%');
                })
                ->count();
            $mediaCounts['documents'] = MessageMedia::query()
                ->whereHas('message', fn ($q) => $q->whereIn('chat_id', $chatIds))
                ->where(function ($q): void {
                    $q->where('mime_type', 'not like', 'image/%')
                        ->where('mime_type', 'not like', 'video/%');
                })
                ->count();
            $linksQuery = clone $messagesBase;
            if (DB::connection()->getDriverName() === 'sqlite') {
                $linksQuery->where(function ($q): void {
                    $q->where('body', 'like', '%http://%')
                        ->orWhere('body', 'like', '%https://%')
                        ->orWhere('body', 'like', '%www.%');
                });
            } else {
                $linksQuery->where('body', 'regexp', 'https?://|www\\.');
            }
            $mediaCounts['links'] = $linksQuery->count();
        }

        $contacts = Contact::query()
            ->whereIn('id', $contactIds)
            ->get(['id', 'whatsapp_id', 'phone_number', 'name', 'push_name', 'profile_picture_url', 'is_business']);

        $possibleNames = $contacts
            ->flatMap(fn (Contact $c) => [$c->name, $c->push_name])
            ->merge($chats->pluck('chat_name'))
            ->merge(
                Message::query()
                    ->whereIn('chat_id', $chatIds)
                    ->whereNotNull('sender_name')
                    ->distinct()
                    ->limit(10)
                    ->pluck('sender_name'),
            )
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $phoneIdentity = PhoneFormatter::resolveContactIdentity($contacts);

        return [
            'identity' => [
                'contact_id' => $contact->id,
                'display_name' => $this->displayNameForContact($contact, $latestChat, $phoneIdentity['phone_display']),
                'saved_name' => $contacts->map(fn (Contact $c) => trim((string) $c->name))->first(fn (string $v) => $v !== '') ?: null,
                'push_name' => $contacts->map(fn (Contact $c) => trim((string) $c->push_name))->first(fn (string $v) => $v !== '') ?: null,
                'phone_number' => $phoneIdentity['phone_number'],
                'phone_display' => $phoneIdentity['phone_display'],
                'lead_id' => $phoneIdentity['lead_id'],
                'whatsapp_ids' => $contacts->pluck('whatsapp_id')->filter()->unique()->values()->all(),
                'profile_picture_url' => $contacts->pluck('profile_picture_url')->filter()->first(),
                'is_business' => $contacts->contains(fn (Contact $c) => (bool) $c->is_business),
                'possible_names' => $possibleNames,
            ],
            'activity' => [
                'chats_count' => $chats->count(),
                'channels_count' => $chats->pluck('whatsapp_session_id')->filter()->unique()->count(),
                'first_message_at' => $this->messageIso($firstMessage),
                'last_message_at' => $latestChat?->last_message_at?->toIso8601String(),
                'last_client_message' => $this->messagePreview($lastInbound),
                'last_operator_message' => $this->messagePreview($lastOutbound),
                'messages' => $messageCounts,
                'attachments' => $mediaCounts,
            ],
            'channels' => $chats->map(function (Chat $chat): array {
                $session = $chat->whatsappSession;

                return [
                    'chat_id' => $chat->id,
                    'session_id' => $chat->whatsapp_session_id,
                    'session_label' => trim((string) ($session?->display_name ?? '')) ?: trim((string) ($session?->phone_number ?? '')) ?: 'Без подписи номера',
                    'session_phone' => $session?->phone_number,
                    'session_phone_display' => PhoneFormatter::formatInternational(PhoneFormatter::normalize($session?->phone_number)),
                    'session_status' => $session?->status,
                    'chat_name' => $chat->chat_name,
                    'last_message_text' => $chat->last_message_text,
                    'last_message_at' => $chat->last_message_at?->toIso8601String(),
                    'unread_count' => $chat->unread_count,
                    'is_archived' => (bool) $chat->is_archived,
                    'open_url' => ChatUrl::show($chat),
                ];
            })->all(),
            'crm' => $this->crm->build($chats, $contactIds, $preferredChatId),
        ];
    }

    /**
     * @param  array<int, int>  $chatIds
     * @return list<array{direction: string, body: string|null, at: string|null}>
     */
    public function recentMessageSnippets(array $chatIds, int $limitPerDirection = 5): array
    {
        if ($chatIds === []) {
            return [];
        }

        $snippets = [];

        foreach (['inbound', 'outbound'] as $direction) {
            $rows = Message::query()
                ->whereIn('chat_id', $chatIds)
                ->where('direction', $direction)
                ->whereNotNull('body')
                ->where('body', '!=', '')
                ->orderByRaw('COALESCE(message_timestamp, created_at) DESC')
                ->orderByDesc('id')
                ->limit($limitPerDirection)
                ->get(['body', 'direction', 'message_timestamp', 'created_at']);

            foreach ($rows as $row) {
                $snippets[] = [
                    'direction' => $direction,
                    'body' => mb_substr(trim((string) $row->body), 0, 400),
                    'at' => ($row->message_timestamp ?: $row->created_at)?->toIso8601String(),
                ];
            }
        }

        return $snippets;
    }

    private function displayNameForContact(Contact $contact, ?Chat $latestChat, ?string $phoneDisplay = null): string
    {
        $name = trim((string) ($contact->name ?? ''))
            ?: trim((string) ($contact->push_name ?? ''))
            ?: trim((string) ($latestChat?->chat_name ?? ''))
            ?: trim((string) ($phoneDisplay ?? ''))
            ?: trim((string) (PhoneFormatter::formatInternational(PhoneFormatter::normalize($contact->phone_number)) ?? ''));

        return $name !== '' ? $name : 'Без имени';
    }

    private function messageIso(?Message $message): ?string
    {
        if ($message === null) {
            return null;
        }

        return ($message->message_timestamp ?: $message->created_at)?->toIso8601String();
    }

    /**
     * @return array{id: int, body: ?string, sender_name: ?string, at: ?string}|null
     */
    private function messagePreview(?Message $message): ?array
    {
        if ($message === null) {
            return null;
        }

        return [
            'id' => $message->id,
            'body' => $message->body,
            'sender_name' => $message->sender_name,
            'at' => $this->messageIso($message),
        ];
    }
}
