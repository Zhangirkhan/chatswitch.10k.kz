<?php

declare(strict_types=1);

namespace App\Services\Contact;

use App\Models\Contact;
use App\Models\ContactStakeholder;
use Illuminate\Support\Collection;

final class ContactBucketResolver
{
    public function normalizedDigits(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw);

        return is_string($digits) ? trim($digits) : '';
    }

    /**
     * @return array<int, int>
     */
    public function bucketIds(Contact $contact): array
    {
        $digits = $this->normalizedDigits((string) ($contact->phone_number ?: $contact->whatsapp_id ?: ''));
        if ($digits === '') {
            return [(int) $contact->id];
        }

        return Contact::query()
            ->where(function ($q) use ($digits): void {
                $q->where('phone_number', $digits)
                    ->orWhere('whatsapp_id', 'like', "%{$digits}%");
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return Collection<int, ContactStakeholder>
     */
    public function stakeholderGraph(Contact $account): Collection
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('contact_stakeholders')) {
            return collect();
        }

        return ContactStakeholder::query()
            ->with('stakeholderContact:id,name,phone_number')
            ->where('account_contact_id', $account->id)
            ->get();
    }
}
