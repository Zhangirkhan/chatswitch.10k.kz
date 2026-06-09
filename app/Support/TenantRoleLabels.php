<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;
use App\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Пользовательские подписи ролей компании (administrator / manager / employee).
 * Права остаются на системных ключах Spatie Permission.
 */
final class TenantRoleLabels
{
    public const SETTING_KEY = 'role_labels';

    /** @var list<string> */
    public const ROLE_KEYS = ['administrator', 'manager', 'employee'];

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return [
            'administrator' => 'Администратор',
            'manager' => 'Руководитель',
            'employee' => 'Сотрудник',
        ];
    }

    public static function isConfigured(?int $companyId = null): bool
    {
        $raw = SystemSetting::getValue(self::SETTING_KEY, null, self::resolveCompanyId($companyId));

        return is_string($raw) && trim($raw) !== '';
    }

    /**
     * @return array<string, string>
     */
    public static function all(?int $companyId = null): array
    {
        $companyId = self::resolveCompanyId($companyId);
        $defaults = self::defaults();
        $raw = SystemSetting::getValue(self::SETTING_KEY, null, $companyId);

        if (! is_string($raw) || trim($raw) === '') {
            return $defaults;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $defaults;
        }

        $labels = [];
        foreach (self::ROLE_KEYS as $role) {
            $value = trim((string) ($decoded[$role] ?? ''));
            $labels[$role] = $value !== '' ? $value : $defaults[$role];
        }

        return $labels;
    }

    public static function label(string $role, ?int $companyId = null): string
    {
        $labels = self::all($companyId);

        return $labels[$role] ?? $labels['employee'] ?? $role;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function saveFromInput(array $input, ?int $companyId = null): void
    {
        $companyId = self::resolveCompanyId($companyId);
        $labels = [];

        foreach (self::ROLE_KEYS as $role) {
            $value = trim((string) ($input[$role] ?? ''));
            if ($value === '') {
                throw new InvalidArgumentException("Role label for {$role} is required.");
            }
            if (mb_strlen($value) > 64) {
                throw new InvalidArgumentException("Role label for {$role} is too long.");
            }
            $labels[$role] = $value;
        }

        SystemSetting::setValue(
            self::SETTING_KEY,
            json_encode($labels, JSON_UNESCAPED_UNICODE),
            $companyId,
        );
    }

    private static function resolveCompanyId(?int $companyId): int
    {
        $companyId ??= app(TenantContext::class)->companyIdOrNull();

        if ($companyId === null) {
            $companyId = TenantCompany::id();
        }

        return $companyId;
    }
}
