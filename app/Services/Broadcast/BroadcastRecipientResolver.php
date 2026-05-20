<?php

declare(strict_types=1);

namespace App\Services\Broadcast;

use App\Models\Chat;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsappSession;
use App\Services\ChatService;
use App\Support\PhoneFormatter;
use Illuminate\Database\Eloquent\Builder;

final class BroadcastRecipientResolver
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    /**
     * @return array{
     *     status: 'ready'|'skipped',
     *     phone_digits: string|null,
     *     contact_id: int|null,
     *     chat_id: int|null,
     *     contact_name: string|null,
     *     skip_reason: string|null
     * }
     */
    public function resolve(
        string $phoneRaw,
        User $actor,
        WhatsappSession $session,
    ): array {
        $digits = $this->normalizeDigits($phoneRaw);
        if ($digits === null) {
            return $this->skipped($phoneRaw, null, 'Некорректный номер телефона.');
        }

        $contact = $this->findContact($digits);
        if ($contact === null) {
            return $this->skipped($phoneRaw, $digits, 'Клиент не найден в системе.');
        }

        $chat = $this->findArchivedChat($contact, $session, $actor);
        if ($chat === null) {
            return $this->skipped(
                $phoneRaw,
                $digits,
                'Нет закрытого (архивного) чата с этим клиентом на выбранном WhatsApp-номере.',
                $contact,
            );
        }

        $name = trim((string) ($contact->name ?: $contact->push_name ?: $contact->phone_number ?: ''));

        return [
            'status' => 'ready',
            'phone_digits' => $digits,
            'contact_id' => $contact->id,
            'chat_id' => $chat->id,
            'contact_name' => $name !== '' ? $name : null,
            'skip_reason' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array{row: int, phone: string, message: string, contact_id: int, chat_id: int}>
     */
    public function rowsFromFilters(array $filters, string $messageTemplate, User $actor, WhatsappSession $session): array
    {
        $message = trim($messageTemplate);
        if ($message === '') {
            return [];
        }

        $query = $this->chatService->queryVisibleToUser($actor)
            ->where('whatsapp_session_id', $session->id)
            ->where('is_archived', true)
            ->where('is_group', false)
            ->whereNotNull('contact_id')
            ->with('contact:id,name,push_name,phone_number,whatsapp_id');

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->whereHas('contact', function (Builder $q) use ($search, $digits): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('push_name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('phone_number', 'like', "%{$digits}%")
                        ->orWhere('whatsapp_id', 'like', "%{$digits}%");
                }
            });
        }

        $companyName = isset($filters['company_name']) ? trim((string) $filters['company_name']) : '';
        if ($companyName !== '') {
            $query->whereHas('contact.companies', fn (Builder $q) => $q->where('name', 'like', "%{$companyName}%"));
        }

        $rows = [];
        $row = 0;
        foreach ($query->orderByDesc('last_message_at')->limit(500)->get() as $chat) {
            $contact = $chat->contact;
            if ($contact === null) {
                continue;
            }
            $row++;
            $phone = (string) ($contact->phone_number ?: $contact->whatsapp_id ?: '');
            $rows[] = [
                'row' => $row,
                'phone' => $phone,
                'message' => $message,
                'contact_id' => $contact->id,
                'chat_id' => $chat->id,
            ];
        }

        return $rows;
    }

    private function normalizeDigits(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if (! is_string($digits) || strlen($digits) < 10) {
            return null;
        }

        return PhoneFormatter::normalize($digits) ?? $digits;
    }

    private function findContact(string $digits): ?Contact
    {
        return Contact::query()
            ->where(function (Builder $q) use ($digits): void {
                $q->where('phone_number', $digits)
                    ->orWhere('whatsapp_id', $digits)
                    ->orWhere('whatsapp_id', "{$digits}@c.us");
            })
            ->orderByDesc('id')
            ->first();
    }

    private function findArchivedChat(Contact $contact, WhatsappSession $session, User $actor): ?Chat
    {
        $chat = Chat::query()
            ->where('contact_id', $contact->id)
            ->where('whatsapp_session_id', $session->id)
            ->where('is_group', false)
            ->where('is_archived', true)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        if ($chat === null) {
            return null;
        }

        return $actor->can('view', $chat) ? $chat : null;
    }

    /**
     * @return array{
     *     status: 'skipped',
     *     phone_digits: string|null,
     *     contact_id: int|null,
     *     chat_id: null,
     *     contact_name: string|null,
     *     skip_reason: string
     * }
     */
    private function skipped(string $phoneRaw, ?string $digits, string $reason, ?Contact $contact = null): array
    {
        return [
            'status' => 'skipped',
            'phone_digits' => $digits ?? preg_replace('/\D/', '', $phoneRaw),
            'contact_id' => $contact?->id,
            'chat_id' => null,
            'contact_name' => $contact
                ? trim((string) ($contact->name ?: $contact->push_name ?: '')) ?: null
                : null,
            'skip_reason' => $reason,
        ];
    }
}
