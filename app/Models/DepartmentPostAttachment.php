<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

final class DepartmentPostAttachment extends Model
{
    protected $fillable = [
        'department_post_id',
        'uploaded_by',
        'original_name',
        'path',
        'mime_type',
        'size',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(DepartmentPost::class, 'department_post_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function url(): string
    {
        return Storage::url($this->path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }
}
