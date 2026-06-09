<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use App\Support\PhoneFormatter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class TenantLoginService
{
    /**
     * @return array{type: 'email'|'phone', value: string|null}
     */
    public function resolveIdentifier(string $rawLogin): array
    {
        $trimmed = trim($rawLogin);

        if (str_contains($trimmed, '@')) {
            return [
                'type' => 'email',
                'value' => Str::lower($trimmed),
            ];
        }

        return [
            'type' => 'phone',
            'value' => PhoneFormatter::normalize($trimmed),
        ];
    }

    public function findUserByLogin(int $companyId, string $rawLogin): ?User
    {
        $identifier = $this->resolveIdentifier($rawLogin);

        if ($identifier['type'] === 'email') {
            if (! is_string($identifier['value']) || $identifier['value'] === '') {
                return null;
            }

            return User::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $companyId)
                ->whereRaw('LOWER(email) = ?', [$identifier['value']])
                ->first();
        }

        $phone = $identifier['value'];
        if (! is_string($phone) || $phone === '') {
            return null;
        }

        return User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->where(function ($query) use ($phone): void {
                $query->where('phone', $phone)
                    ->orWhereJsonContains('phones', $phone);
            })
            ->first();
    }

    public function verifyPassword(User $user, string $password): bool
    {
        return Hash::check($password, $user->getAuthPassword());
    }

    public function throttleKey(string $rawLogin, string $ip): string
    {
        $identifier = $this->resolveIdentifier($rawLogin);
        $value = $identifier['value'] ?? Str::lower(trim($rawLogin));

        return Str::transliterate((string) $value.'|'.$ip);
    }
}
