<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

final class Company extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'bin',
        'legal_address',
        'business_activity',
        'phone',
        'email',
        'website',
        'description',
        'is_active',
        'ai_promotions_enabled',
        'owner_user_id',
        'provisioned_by_user_id',
        'plan_id',
        'subscription_status',
        'trial_ends_at',
        'current_period_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'ai_promotions_enabled' => 'boolean',
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company): void {
            if (filled($company->slug)) {
                return;
            }

            $base = Str::slug((string) $company->name) ?: 'company';
            $slug = $base;
            $suffix = 0;

            while (
                self::query()
                    ->withoutGlobalScopes()
                    ->where('slug', $slug)
                    ->exists()
            ) {
                $slug = $base.'-'.(++$suffix);
            }

            $company->slug = $slug;
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function provisionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provisioned_by_user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function whatsappSessions(): HasMany
    {
        return $this->hasMany(WhatsappSession::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)
            ->withPivot('position')
            ->withTimestamps();
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function knowledgeRules(): HasMany
    {
        return $this->hasMany(KnowledgeRule::class);
    }

    public function toneProfile(): HasOne
    {
        return $this->hasOne(CompanyToneProfile::class);
    }

    public function funnels(): HasMany
    {
        return $this->hasMany(Funnel::class);
    }

    public function tenantUrl(string $path = '/'): string
    {
        $scheme = config('app.env') === 'production' ? 'https' : 'http';
        $root = config('tenancy.root_domain', 'accel.kz');

        return $scheme.'://'.$this->slug.'.'.$root.$path;
    }
}
