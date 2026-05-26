<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

final class SystemSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'company_id',
        'key',
        'value',
    ];

    public static function getValue(string $key, ?string $default = null, ?int $companyId = null): ?string
    {
        $companyId ??= app(TenantContext::class)->companyIdOrNull();

        if ($companyId === null) {
            return $default;
        }

        return static::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    public static function setValue(string $key, ?string $value, ?int $companyId = null): void
    {
        $companyId ??= app(TenantContext::class)->companyIdOrNull();

        if ($companyId === null) {
            throw new RuntimeException('Cannot save system setting without company context.');
        }

        static::query()
            ->withoutGlobalScope('tenant')
            ->updateOrCreate(
                ['company_id' => $companyId, 'key' => $key],
                ['value' => $value],
            );
    }
}
