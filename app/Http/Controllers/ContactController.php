<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Support\PhoneFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ContactController extends Controller
{
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

