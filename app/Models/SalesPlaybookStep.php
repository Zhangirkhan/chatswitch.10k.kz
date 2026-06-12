<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SalesPlaybookStep extends Model
{
    protected $fillable = [
        'sales_playbook_id',
        'position',
        'step_key',
        'prompt_hint',
        'required_before_next',
    ];

    protected function casts(): array
    {
        return [
            'sales_playbook_id' => 'integer',
            'position' => 'integer',
            'required_before_next' => 'array',
        ];
    }

    /** @return BelongsTo<SalesPlaybook, $this> */
    public function playbook(): BelongsTo
    {
        return $this->belongsTo(SalesPlaybook::class, 'sales_playbook_id');
    }
}
