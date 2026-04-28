<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Community extends Model
{
    use HasFactory;

    protected $fillable = [
        'whatsapp_session_id',
        'created_by',
        'name',
        'description',
        'avatar_path',
        'is_archived',
    ];

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
        ];
    }

    public function whatsappSession(): BelongsTo
    {
        return $this->belongsTo(WhatsappSession::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Chat> */
    public function groups(): HasMany
    {
        return $this->hasMany(Chat::class, 'community_id')
            ->where('is_group', true);
    }
}
