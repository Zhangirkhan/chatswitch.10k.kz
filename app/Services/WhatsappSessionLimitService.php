<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\WhatsappSession;
use App\Tenancy\TenantContext;

final class WhatsappSessionLimitService
{
    public function globalMax(): int
    {
        return max(1, (int) config('whatsapp.max_sessions_global', 12));
    }

    public function perTenantMax(?int $companyId = null): int
    {
        $ceiling = max(1, (int) config('whatsapp.max_sessions_per_tenant', $this->globalMax()));

        if ($companyId !== null) {
            $custom = SystemSetting::getValue('max_sessions', null, $companyId);
            if ($custom !== null && is_numeric($custom)) {
                return max(1, min((int) $custom, $ceiling));
            }
        }

        return $ceiling;
    }

    public function globalCount(): int
    {
        return WhatsappSession::query()->withoutGlobalScope('tenant')->count();
    }

    public function tenantCount(?int $companyId = null): int
    {
        $companyId ??= app(TenantContext::class)->companyIdOrNull();

        if ($companyId === null) {
            return 0;
        }

        return WhatsappSession::query()
            ->withoutGlobalScope('tenant')
            ->where('company_id', $companyId)
            ->count();
    }

    public function remainingForTenant(?int $companyId = null): int
    {
        $companyId ??= app(TenantContext::class)->companyIdOrNull();

        if ($companyId === null) {
            return 0;
        }

        return max(0, min(
            $this->perTenantMax($companyId) - $this->tenantCount($companyId),
            $this->globalMax() - $this->globalCount(),
        ));
    }

    public function canCreate(?int $companyId = null): bool
    {
        return $this->denyReason($companyId) === null;
    }

    public function denyReason(?int $companyId = null): ?string
    {
        $companyId ??= app(TenantContext::class)->companyIdOrNull();

        if ($companyId === null) {
            return 'Контекст компании не определён.';
        }

        if ($this->globalCount() >= $this->globalMax()) {
            return sprintf(
                'Достигнут лимит сервера: %d WhatsApp-подключений на все компании. Увеличьте RAM или WHATSAPP_MAX_SESSIONS_GLOBAL.',
                $this->globalMax(),
            );
        }

        if ($this->tenantCount($companyId) >= $this->perTenantMax($companyId)) {
            return sprintf(
                'Достигнут лимит для компании: %d подключений (ёмкость сервера).',
                $this->perTenantMax($companyId),
            );
        }

        return null;
    }

    /**
     * @return array{
     *     global_max: int,
     *     global_count: int,
     *     tenant_max: int,
     *     tenant_count: int,
     *     remaining: int,
     *     can_create: bool,
     * }
     */
    public function payload(?int $companyId = null): array
    {
        $companyId ??= app(TenantContext::class)->companyIdOrNull();
        $tenantMax = $companyId !== null ? $this->perTenantMax($companyId) : $this->perTenantMax();
        $tenantCount = $this->tenantCount($companyId);

        return [
            'global_max' => $this->globalMax(),
            'global_count' => $this->globalCount(),
            'tenant_max' => $tenantMax,
            'tenant_count' => $tenantCount,
            'remaining' => $this->remainingForTenant($companyId),
            'can_create' => $this->canCreate($companyId),
        ];
    }
}
