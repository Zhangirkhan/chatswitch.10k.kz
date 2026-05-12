<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DepartmentPostComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_post_id',
        'author_id',
        'body',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'department_post_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
