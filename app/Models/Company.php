<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Company extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'website',
        'description',
    ];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)
            ->withPivot('position')
            ->withTimestamps();
    }
}
