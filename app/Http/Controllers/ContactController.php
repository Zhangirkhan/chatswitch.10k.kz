<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Models\Contact;
use App\Support\PhoneFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ContactController extends Controller
{
    public function settingsIndex(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));

        $query = Contact::query()->orderByRaw('COALESCE(name, push_name, phone_number) asc');

        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('push_name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhere('whatsapp_id', 'like', "%{$search}%");
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('phone_number', 'like', "%{$digits}%")
                        ->orWhere('whatsapp_id', 'like', "%{$digits}%");
                }
            });
        }

        $contacts = $query
            ->with([
                'chats' => fn ($q) => $q
                    ->where('is_group', false)
                    ->with('whatsappSession:id,display_name,phone_number')
                    ->orderByDesc('last_message_at')
                    ->orderByDesc('id'),
            ])
            ->limit(500)
            ->get([
                'id',
                'whatsapp_id',
                'phone_number',
                'name',
                'push_name',
                'profile_picture_url',
            ]);

        $clients = $contacts
            ->groupBy(function (Contact $c) {
                $digits = $this->normalizedDigits((string) ($c->phone_number ?: $c->whatsapp_id ?: ''));

                return $digits !== '' ? "phone:{$digits}" : "contact:{$c->id}";
            })
            ->map(function ($bucket, string $groupKey) {
                /** @var Contact $primary */
                $primary = $bucket->first();
                $allChats = $bucket
                    ->flatMap(fn (Contact $c) => $c->chats)
                    ->sortByDesc(fn (Chat $chat) => (string) ($chat->last_message_at ?? $chat->updated_at ?? ''));

                $latestChat = $allChats->first();
                $savedName = $bucket
                    ->map(fn (Contact $c) => trim((string) ($c->name ?? '')))
                    ->first(fn (string $name) => $name !== '');
                $pushName = $bucket
                    ->map(fn (Contact $c) => trim((string) ($c->push_name ?? '')))
                    ->first(fn (string $name) => $name !== '');

                $channels = $allChats
                    ->map(function (Chat $chat) {
                        $session = $chat->whatsappSession;

                        return [
                            'chat_id' => $chat->id,
                            'session_id' => $chat->whatsapp_session_id,
                            'session_label' => trim((string) ($session?->display_name ?? '')) ?: trim((string) ($session?->phone_number ?? '')) ?: 'Без подписи номера',
                            'session_phone' => $session?->phone_number,
                            'chat_name' => $chat->chat_name,
                            'last_message_at' => $chat->last_message_at?->toISOString(),
                        ];
                    })
                    // Show only one “channel” per WA session number (even if there are multiple chats
                    // for the same client inside the same WA session due to duplicated WA ids).
                    ->groupBy(fn (array $row) => (string) ($row['session_id'] ?? ''))
                    ->map(fn ($rows) => $rows->first())
                    ->values();

                $phoneDigits = $this->normalizedDigits((string) ($primary->phone_number ?? ''));
                if ($phoneDigits === '') {
                    $phoneDigits = str_replace('phone:', '', $groupKey);
                }

                return [
                    'id' => $primary->id,
                    'whatsapp_id' => $primary->whatsapp_id,
                    'phone_number' => $phoneDigits !== '' ? $phoneDigits : $primary->phone_number,
                    'name' => $savedName !== null ? $savedName : null,
                    'push_name' => $pushName !== null ? $pushName : null,
                    'profile_picture_url' => $primary->profile_picture_url,
                    // channels[] already grouped by WA session number; use the same unique basis for count.
                    'chats_count' => $channels->count(),
                    'last_chat_name' => $latestChat?->chat_name,
                    'last_chat_at' => $latestChat?->last_message_at?->toISOString(),
                    'channels' => $channels,
                ];
            })
            ->sortByDesc(fn (array $client) => (string) ($client['last_chat_at'] ?? ''))
            ->values();

        return Inertia::render('Settings/Clients', [
            'search' => $search,
            'clients' => $clients,
        ]);
    }

    private function normalizedDigits(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);

        return is_string($digits) ? trim($digits) : '';
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search', ''));

        $query = Contact::query()->orderByRaw('COALESCE(name, push_name, phone_number) asc');

        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search);
            $query->where(function ($q) use ($search, $digits) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('push_name', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
                if (is_string($digits) && $digits !== '') {
                    $q->orWhere('whatsapp_id', 'like', "%{$digits}%");
                }
            });
        }

        return Inertia::render('Contacts/Index', [
            'search' => $search,
            'contacts' => $query->limit(500)->get([
                'id',
                'whatsapp_id',
                'phone_number',
                'name',
                'push_name',
                'profile_picture_url',
            ]),
        ]);
    }

    public function update(Request $request, Contact $contact): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $name = isset($data['name']) ? trim((string) $data['name']) : null;
        $contact->name = $name !== '' ? $name : null;
        $contact->saveQuietly();

        // Keep chat UI consistent:
        // in lists/headers we prefer `chat.chat_name` over `contact.name`.
        //
        // In “Settings → Clients” we group a single real person across multiple WA ids
        // (e.g. one Contact row with @lid and another with @c.us, but same phone digits).
        // So when operator edits the saved client name, update chat_name for all duplicated
        // Contact rows that belong to the same digit bucket.
        $digits = $this->normalizedDigits((string) ($contact->phone_number ?: $contact->whatsapp_id ?: ''));
        $contactIds = $digits !== ''
            ? Contact::query()
                ->where(function ($q) use ($digits) {
                    $q->where('phone_number', $digits)
                        ->orWhere('whatsapp_id', 'like', "%{$digits}%");
                })
                ->pluck('id')
                ->all()
            : [$contact->id];

        $newChatName = $contact->name ?: $contact->push_name ?: $contact->phone_number;
        Chat::query()
            ->whereIn('contact_id', $contactIds)
            ->where('is_group', false)
            ->update(['chat_name' => $newChatName]);

        return response()->json(['success' => true, 'contact' => $contact]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $phone = PhoneFormatter::normalize((string) $data['phone']);
        if (! $phone) {
            return response()->json(['success' => false, 'error' => 'Некорректный номер.'], 422);
        }

        $name = isset($data['name']) ? trim((string) $data['name']) : null;
        $name = ($name !== '') ? $name : null;

        $contact = Contact::query()->where('phone_number', $phone)->first();
        if (! $contact) {
            $contact = Contact::create([
                'phone_number' => $phone,
                'whatsapp_id' => $phone,
                'name' => $name,
                'push_name' => null,
                'profile_picture_url' => null,
                'is_business' => false,
            ]);
        } else {
            if ($name !== null) {
                $contact->name = $name;
            }
            $contact->saveQuietly();
        }

        return response()->json(['success' => true, 'contact' => $contact]);
    }
}
