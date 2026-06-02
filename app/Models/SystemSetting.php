<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class SystemSetting extends Model
{
    use BelongsToTenant;

    private const CACHE_TTL = 300;

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

        $value = Cache::remember(
            self::cacheKey($companyId, $key),
            self::CACHE_TTL,
            static fn (): ?string => static::query()
                ->withoutGlobalScope('tenant')
                ->where('company_id', $companyId)
                ->where('key', $key)
                ->value('value'),
        );

        return $value ?? $default;
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

        Cache::forget(self::cacheKey($companyId, $key));
    }

    private static function cacheKey(int $companyId, string $key): string
    {
        return "system_setting:{$companyId}:{$key}";
    }
}
