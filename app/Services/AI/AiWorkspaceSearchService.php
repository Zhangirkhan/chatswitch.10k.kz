<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\MessageMedia;
use App\Models\User;
use App\Services\ChatService;
use App\Support\TenantCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

final class AiWorkspaceSearchService
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function searchContacts(User $user, array $filters): array
    {
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 25)));
        $text = $this->nullableString($filters['text'] ?? $filters['search'] ?? null);
        $companyName = $this->nullableString($filters['company_name'] ?? null);
        $phoneContains = $this->nullableString($filters['phone_contains'] ?? null);
        $hasUnread = filter_var($filters['has_unread_chat'] ?? false, FILTER_VALIDATE_BOOL);

        $visibleChats = $this->chatService->queryVisibleToUser($user)
            ->where('is_group', false);

        $query = Contact::query()
            ->whereHas('chats', fn (Builder $q) => (clone $visibleChats)->whereColumn('chats.contact_id', 'contacts.id'))
            ->with([
                'companies:id,name',
                'chats' => fn ($q) => (clone $visibleChats)
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id')
                    ->limit(3),
            ]);

        if ($text !== null) {
            $digits = preg_replace('/\D/', '', $text);
            $query->where(function (Builder $q) use ($text, $digits): void {
                $q->where('name', 'like', "%{$text}%")
                    ->orWhere('push_name', 'like', "%{$text}%")
                    ->orWhere('phone_number', 'like', "%{$text}%")
                    ->orWhere('whatsapp_id', 'like', "%{$text}%")
                    ->orWhereHas('companies', fn (Builder $cq) => $cq->where('name', 'like', "%{$text}%"));
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('phone_number', 'like', "%{$digits}%")
                        ->orWhere('whatsapp_id', 'like', "%{$digits}%");
                }
            });
        }

        if ($companyName !== null) {
            $query->whereHas('companies', fn (Builder $q) => $q
                ->where('companies.id', TenantCompany::id())
                ->where('name', 'like', "%{$companyName}%"));
        }

        if ($phoneContains !== null) {
            $digits = preg_replace('/\D/', '', $phoneContains) ?: $phoneContains;
            $query->where(function (Builder $q) use ($digits): void {
                $q->where('phone_number', 'like', "%{$digits}%")
                    ->orWhere('whatsapp_id', 'like', "%{$digits}%");
            });
        }

        if ($hasUnread) {
            $query->whereHas('chats', fn (Builder $q) => (clone $visibleChats)
                ->where('unread_count', '>', 0)
                ->whereColumn('chats.contact_id', 'contacts.id'));
        }

        return $query
            ->orderByRaw('COALESCE(name, push_name, phone_number) asc')
            ->limit($limit)
            ->get(['id', 'name', 'push_name', 'phone_number', 'whatsapp_id', 'profile_picture_url'])
            ->map(function (Contact $contact): array {
                /** @var Chat|null $latestChat */
                $latestChat = $contact->chats->first();

                return [
                    'id' => $contact->id,
                    'name' => trim((string) ($contact->name ?: $contact->push_name ?: $contact->phone_number ?: 'Без имени')),
                    'phone_number' => $contact->phone_number,
                    'whatsapp_id' => $contact->whatsapp_id,
                    'profile_picture_url' => $contact->profile_picture_url,
                    'companies' => $contact->companies->pluck('name')->values()->all(),
                    'chat_id' => $latestChat?->id,
                    'last_message_at' => $latestChat?->last_message_at?->toIso8601String(),
                    'unread_count' => (int) $contact->chats->sum('unread_count'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function searchMedia(User $user, array $filters): array
    {
        $limit = min(60, max(1, (int) ($filters['limit'] ?? 30)));
        $filename = $this->nullableString($filters['filename_contains'] ?? $filters['filename'] ?? null);
        $textQuery = $this->nullableString($filters['text_query'] ?? $filters['query'] ?? null);
        $contactText = $this->nullableString($filters['contact_text'] ?? null);
        $mimeCategory = $this->nullableString($filters['mime_category'] ?? 'any');
        $dateFrom = $this->parseDate($filters['date_from'] ?? null);
        $dateTo = $this->parseDate($filters['date_to'] ?? null, endOfDay: true);

        $visibleChatIds = $this->chatService->queryVisibleToUser($user)->select('id');

        $query = MessageMedia::query()
            ->whereHas('message', fn (Builder $m) => $m->whereIn('chat_id', $visibleChatIds))
            ->with([
                'message:id,chat_id,message_timestamp,created_at',
                'message.chat:id,contact_id,chat_name,is_group',
                'message.chat.contact:id,name,push_name,phone_number',
            ]);

        if ($filename !== null) {
            $query->where('filename', 'like', "%{$filename}%");
        }

        if ($textQuery !== null && $filename === null) {
            $query->where(function (Builder $q) use ($textQuery): void {
                $q->where('filename', 'like', "%{$textQuery}%")
                    ->orWhereHas('message', fn (Builder $m) => $m->where('body', 'like', "%{$textQuery}%"));
            });
        }

        if ($contactText !== null) {
            $digits = preg_replace('/\D/', '', $contactText);
            $query->whereHas('message.chat.contact', function (Builder $q) use ($contactText, $digits): void {
                $q->where('name', 'like', "%{$contactText}%")
                    ->orWhere('push_name', 'like', "%{$contactText}%");
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('phone_number', 'like', "%{$digits}%");
                }
            });
        }

        $this->applyMimeCategory($query, $mimeCategory);

        if ($dateFrom !== null || $dateTo !== null) {
            $query->whereHas('message', function (Builder $m) use ($dateFrom, $dateTo): void {
                if ($dateFrom !== null) {
                    $m->whereRaw('COALESCE(message_timestamp, created_at) >= ?', [$dateFrom]);
                }
                if ($dateTo !== null) {
                    $m->whereRaw('COALESCE(message_timestamp, created_at) <= ?', [$dateTo]);
                }
            });
        }

        return $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (MessageMedia $media): array {
                $message = $media->message;
                $chat = $message?->chat;
                $contact = $chat?->contact;
                $contactName = $contact
                    ? trim((string) ($contact->name ?: $contact->push_name ?: $contact->phone_number ?: ''))
                    : null;

                return [
                    'id' => $media->id,
                    'filename' => $media->filename,
                    'mime_type' => $media->mime_type,
                    'file_size' => $media->file_size,
                    'url' => route('media.show', $media->id),
                    'download_url' => route('media.show', ['media' => $media->id, 'download' => 1]),
                    'chat_id' => $chat?->id,
                    'chat_name' => $chat?->chat_name ?? ($chat?->is_group ? 'Группа' : $contactName),
                    'contact_name' => $contactName,
                    'message_at' => optional($message?->message_timestamp ?: $message?->created_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Builder<MessageMedia>  $query
     */
    private function applyMimeCategory(Builder $query, ?string $category): void
    {
        if ($category === null || $category === '' || $category === 'any') {
            return;
        }

        $query->where(function (Builder $q) use ($category): void {
            match ($category) {
                'image' => $q->where('mime_type', 'like', 'image/%'),
                'video' => $q->where('mime_type', 'like', 'video/%'),
                'audio' => $q->where(function (Builder $inner): void {
                    $inner->where('mime_type', 'like', 'audio/%')
                        ->orWhere('mime_type', 'like', '%ogg%');
                }),
                'document' => $q->where(function (Builder $inner): void {
                    $inner->where('mime_type', 'like', 'application/%')
                        ->orWhere('mime_type', 'like', 'text/%');
                }),
                default => null,
            };
        });
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function parseDate(mixed $value, bool $endOfDay = false): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $endOfDay ? $date->endOfDay() : $date->startOfDay();
    }
}
