<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ConversationAudit extends Model
{
    protected $fillable = [
        'company_id',
        'chat_id',
        'trigger_message_id',
        'sales_score',
        'conversation_quality',
        'missed_questions',
        'missed_opportunities',
        'qualification_quality',
        'risk_level',
        'raw_response',
        'model',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'chat_id' => 'integer',
            'trigger_message_id' => 'integer',
            'sales_score' => 'integer',
            'missed_questions' => 'array',
            'missed_opportunities' => 'array',
            'raw_response' => 'array',
        ];
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
