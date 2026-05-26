<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TenantSignupRequest extends Model
{
    protected $fillable = [
        'company_name',
        'bin',
        'desired_slug',
        'contact_name',
        'email',
        'phone',
        'message',
        'terms_accepted_at',
        'status',
        'company_id',
        'processed_by_user_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'company_name' => 'encrypted',
            'bin' => 'encrypted',
            'desired_slug' => 'encrypted',
            'contact_name' => 'encrypted',
            'email' => 'encrypted',
            'phone' => 'encrypted',
            'message' => 'encrypted',
            'processed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by_user_id');
    }
}
