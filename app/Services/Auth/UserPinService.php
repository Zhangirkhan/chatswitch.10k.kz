<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class UserPinService
{
    public const MIN_LENGTH = 4;

    public const MAX_LENGTH = 6;

    public function normalize(?string $pin): ?string
    {
        if ($pin === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $pin);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        return $digits;
    }

    public function isValidFormat(?string $pin): bool
    {
        if ($pin === null) {
            return false;
        }

        $length = strlen($pin);

        return $length >= self::MIN_LENGTH
            && $length <= self::MAX_LENGTH
            && ctype_digit($pin);
    }

    /**
     * @throws ValidationException
     */
    public function setPin(User $user, ?string $rawPin): void
    {
        $pin = $this->normalize($rawPin);

        if ($pin === null) {
            $user->forceFill(['pin_hash' => null])->save();

            return;
        }

        if (! $this->isValidFormat($pin)) {
            throw ValidationException::withMessages([
                'pin' => 'PIN должен состоять из 4–6 цифр.',
            ]);
        }

        $companyId = (int) $user->company_id;
        if ($companyId <= 0) {
            throw ValidationException::withMessages([
                'pin' => 'PIN доступен только для сотрудников компании.',
            ]);
        }

        $this->assertUniqueInCompany($pin, $companyId, $user->exists ? (int) $user->id : null);

        $user->forceFill(['pin_hash' => $pin])->save();
    }

    public function findActiveUserByPin(int $companyId, string $rawPin): ?User
    {
        $user = $this->findUserByPin($companyId, $rawPin);

        if ($user === null || ! $user->is_active) {
            return null;
        }

        return $user;
    }

    public function findUserByPin(int $companyId, string $rawPin): ?User
    {
        $pin = $this->normalize($rawPin);
        if (! $this->isValidFormat($pin)) {
            return null;
        }

        $candidates = User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->where('is_super_admin', false)
            ->whereNotNull('pin_hash')
            ->get(['id', 'pin_hash', 'company_id', 'is_active', 'is_super_admin']);

        foreach ($candidates as $user) {
            $hash = $user->getRawOriginal('pin_hash');
            if (is_string($hash) && Hash::check($pin, $hash)) {
                return $user->fresh();
            }
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    private function assertUniqueInCompany(string $pin, int $companyId, ?int $exceptUserId): void
    {
        $query = User::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->whereNotNull('pin_hash');

        if ($exceptUserId !== null) {
            $query->where('id', '!=', $exceptUserId);
        }

        /** @var iterable<int, User> $others */
        $others = $query->get(['id', 'pin_hash']);

        foreach ($others as $other) {
            $hash = $other->getRawOriginal('pin_hash');
            if (is_string($hash) && Hash::check($pin, $hash)) {
                throw ValidationException::withMessages([
                    'pin' => 'Этот PIN уже используется другим сотрудником.',
                ]);
            }
        }
    }
}
