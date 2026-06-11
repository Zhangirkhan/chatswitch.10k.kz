<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserDevice extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'company_id',
        'user_id',
        'platform',
        'fcm_token',
        'device_name',
        'device_model',
        'device_manufacturer',
        'os_version',
        'locale',
        'is_physical_device',
        'app_version',
        'last_seen_ip',
    ];

    protected function casts(): array
    {
        return [
            'is_physical_device' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
