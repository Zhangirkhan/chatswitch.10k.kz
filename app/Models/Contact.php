<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'whatsapp_id',
        'phone_number',
        'name',
        'push_name',
        'profile_picture_url',
        'is_business',
    ];

    protected function casts(): array
    {
        return [
            'is_business' => 'boolean',
        ];
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }
}
