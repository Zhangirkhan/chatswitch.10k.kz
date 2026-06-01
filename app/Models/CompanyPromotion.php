<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CompanyPromotion extends Model
{
    use BelongsToTenant;

    public const TYPE_PERCENT = 'percent';

    public const TYPE_FIXED = 'fixed';

    /** N+M: купи N — получи M в подарок (1+1, 2+1 и т.д.). */
    public const TYPE_BOGO = 'bogo';

    /** Подарок при покупке. */
    public const TYPE_GIFT = 'gift';

    /** Комплект / набор по спец. условию. */
    public const TYPE_BUNDLE = 'bundle';

    /** Бесплатная доставка. */
    public const TYPE_FREE_DELIVERY = 'free_delivery';

    public const TYPE_CUSTOM = 'custom';

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::TYPE_PERCENT,
            self::TYPE_FIXED,
            self::TYPE_BOGO,
            self::TYPE_GIFT,
            self::TYPE_BUNDLE,
            self::TYPE_FREE_DELIVERY,
            self::TYPE_CUSTOM,
        ];
    }

    protected $fillable = [
        'company_id',
        'name',
        'discount_type',
        'percent',
        'fixed_amount',
        'buy_quantity',
        'get_quantity',
        'valid_from',
        'valid_until',
        'conditions',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'percent' => 'integer',
            'fixed_amount' => 'decimal:2',
            'buy_quantity' => 'integer',
            'get_quantity' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isCurrentlyValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $today = now()->startOfDay();

        if ($this->valid_from !== null && $this->valid_from->gt($today)) {
            return false;
        }

        if ($this->valid_until !== null && $this->valid_until->lt($today)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{id: string, label: string, type: string, benefit: string|null, percent: int|null, fixed_amount: string|null, buy_quantity: int|null, get_quantity: int|null, valid_until: string|null, note: string|null}
     */
    public function toPromptArray(): array
    {
        return [
            'id' => (string) $this->id,
            'label' => $this->name,
            'type' => $this->discount_type,
            'benefit' => $this->benefitSummary() ?: null,
            'percent' => $this->discount_type === self::TYPE_PERCENT ? $this->percent : null,
            'fixed_amount' => $this->discount_type === self::TYPE_FIXED && $this->fixed_amount !== null
                ? (string) $this->fixed_amount
                : null,
            'buy_quantity' => $this->discount_type === self::TYPE_BOGO ? $this->buy_quantity : null,
            'get_quantity' => $this->discount_type === self::TYPE_BOGO ? $this->get_quantity : null,
            'valid_until' => $this->valid_until?->format('Y-m-d'),
            'note' => $this->conditions,
        ];
    }

    public function benefitSummary(): string
    {
        return match ($this->discount_type) {
            self::TYPE_PERCENT => $this->percent !== null ? "−{$this->percent}%" : '',
            self::TYPE_FIXED => $this->fixed_amount !== null ? "−{$this->fixed_amount} ₸" : '',
            self::TYPE_BOGO => $this->bogoSummary(),
            self::TYPE_GIFT => 'Подарок при покупке',
            self::TYPE_BUNDLE => 'Комплект / набор',
            self::TYPE_FREE_DELIVERY => 'Бесплатная доставка',
            default => '',
        };
    }

    private function bogoSummary(): string
    {
        $buy = max(1, (int) ($this->buy_quantity ?? 1));
        $get = max(1, (int) ($this->get_quantity ?? 1));

        return "{$buy}+{$get}";
    }
}
