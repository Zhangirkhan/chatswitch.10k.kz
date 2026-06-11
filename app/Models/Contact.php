<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\PhoneFormatter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Contact extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'company_id',
        'whatsapp_id',
        'phone_number',
        'name',
        'push_name',
        'profile_picture_url',
        'is_business',
        'is_sandbox',
        'messages_cleared_at',
        'ai_funnel_stage_id',
        'ai_enriched_at',
    ];

    protected function casts(): array
    {
        return [
            'is_business' => 'boolean',
            'is_sandbox' => 'boolean',
            'messages_cleared_at' => 'datetime',
            'ai_enriched_at' => 'datetime',
        ];
    }

    public function tags(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ContactTag::class);
    }

    public function aiFunnelStage(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(FunnelStage::class, 'ai_funnel_stage_id');
    }

    public function setPhoneNumberAttribute(?string $value): void
    {
        if ($value === null || trim($value) === '') {
            $this->attributes['phone_number'] = '';

            return;
        }

        $this->attributes['phone_number'] = PhoneFormatter::normalize($value) ?? '';
    }

    public function setWhatsappIdAttribute(?string $value): void
    {
        if ($value === null || $value === '') {
            $this->attributes['whatsapp_id'] = null;

            return;
        }

        // Храним WhatsApp-идентификаторы в исходной форме (поддержка @c.us / @g.us),
        // но если передали просто цифры — сохраняем нормализованные.
        $this->attributes['whatsapp_id'] = str_contains($value, '@')
            ? $value
            : (PhoneFormatter::normalize($value) ?? $value);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function calendarEvents(): HasMany
    {
        return $this->hasMany(CalendarEvent::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->withPivot('position')
            ->withTimestamps();
    }

    /** @return HasMany<ContactFieldValue, $this> */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(ContactFieldValue::class);
    }
}
