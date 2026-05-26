<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Company;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $context = app(TenantContext::class);
            if (! $context->shouldApplyTenantScope()) {
                return;
            }

            $builder->where(
                $builder->getModel()->getTable().'.company_id',
                $context->companyId(),
            );
        });

        static::creating(function (Model $model): void {
            $context = app(TenantContext::class);
            if (! $context->shouldApplyTenantScope()) {
                return;
            }

            if ($model->getAttribute('company_id') === null) {
                $model->setAttribute('company_id', $context->companyId());
            }
        });
    }

    public function tenantCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
