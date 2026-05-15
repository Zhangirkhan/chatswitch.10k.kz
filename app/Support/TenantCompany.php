<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;

/**
 * Один инстанс приложения = одна компания (id 1). Разделение тенантов — на уровне поддомена/деплоя.
 */
final class TenantCompany
{
    public const ID = 1;

    public static function id(): int
    {
        self::ensureExists();

        return self::ID;
    }

    public static function ensureExists(): Company
    {
        return Company::query()->updateOrCreate(
            ['id' => self::ID],
            ['name' => 'Компания'],
        );
    }
}
