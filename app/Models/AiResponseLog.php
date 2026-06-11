<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiResponseLog extends Model
{
    protected $fillable = [
        'company_id',
        'chat_id',
        'trigger_message_id',
        'message_id',
        'user_id',
        'mode',
        'model',
        'prompt_hash',
        'metadata',
        'tokens_prompt',
        'tokens_completion',
        'status',
        'error',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'tokens_prompt' => 'integer',
            'tokens_completion' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function triggerMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'trigger_message_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
