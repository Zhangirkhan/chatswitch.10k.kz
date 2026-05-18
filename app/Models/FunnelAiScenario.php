<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FunnelAiScenario extends Model
{
    protected $fillable = [
        'company_id',
        'funnel_id',
        'enabled',
        'customer_identity',
        'booking_horizon_days',
        'fallback_manager_user_id',
        'fallback_department_id',
        'manager_confirmation_required',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'funnel_id' => 'integer',
            'enabled' => 'boolean',
            'booking_horizon_days' => 'integer',
            'fallback_manager_user_id' => 'integer',
            'fallback_department_id' => 'integer',
            'manager_confirmation_required' => 'boolean',
        ];
    }

    /** @return BelongsTo<Funnel, $this> */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    /** @return BelongsTo<User, $this> */
    public function fallbackManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fallback_manager_user_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function fallbackDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'fallback_department_id');
    }
}
