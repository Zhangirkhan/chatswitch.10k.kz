<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Plan extends Model
{
    protected $fillable = [
        'code',
        'name',
        'price_cents',
        'currency',
        'interval',
        'trial_days',
        'features',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'trial_days' => 'integer',
            'features' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function isOneTime(): bool
    {
        return $this->interval === 'once';
    }

    public function formattedPrice(): string
    {
        $amount = (int) round($this->price_cents / 100);

        return number_format($amount, 0, ',', ' ').' ₸';
    }

    public function priceLabel(): string
    {
        if ($this->isOneTime()) {
            return $this->formattedPrice().' разово';
        }

        return match ($this->interval) {
            'year' => $this->formattedPrice().' / год',
            default => $this->formattedPrice().' / мес.',
        };
    }

    public function pricePerMonthLabel(): string
    {
        if ($this->isOneTime()) {
            return $this->priceLabel();
        }

        return $this->formattedPrice().' / мес.';
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
