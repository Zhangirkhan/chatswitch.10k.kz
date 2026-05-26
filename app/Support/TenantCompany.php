<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use App\Tenancy\TenantContext;

/**
 * Обратная совместимость: делегирует в {@see TenantContext}.
 */
final class TenantCompany
{
    public const ID = 1;

    public static function id(): int
    {
        $context = app(TenantContext::class);

        if ($context->isResolved()) {
            return $context->companyId();
        }

        return (int) (Company::query()
            ->withoutGlobalScope('tenant')
            ->where('slug', config('tenancy.fallback_slug', 'demo'))
            ->value('id') ?? self::ID);
    }

    public static function ensureExists(): Company
    {
        $slug = (string) config('tenancy.fallback_slug', 'demo');

        $company = Company::query()
            ->withoutGlobalScope('tenant')
            ->where('slug', $slug)
            ->first();

        if ($company !== null) {
            return $company;
        }

        return Company::query()
            ->withoutGlobalScope('tenant')
            ->updateOrCreate(
                ['id' => self::ID],
                [
                    'name' => 'Компания',
                    'slug' => $slug,
                    'is_active' => true,
                    'subscription_status' => 'active',
                ],
            );
    }
}
