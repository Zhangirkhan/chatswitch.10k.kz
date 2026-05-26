<?php

declare(strict_types=1);

namespace App\Services\SuperAdmin;

use App\Models\Company;
use App\Models\SuperAdminAuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class SuperAdminAuditLogger
{
    public function log(
        ?Company $company,
        ?User $actor,
        string $action,
        ?Model $subject = null,
        array $meta = [],
    ): SuperAdminAuditLog {
        return SuperAdminAuditLog::query()->create([
            'company_id' => $company?->id,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta !== [] ? $meta : null,
            'created_at' => now(),
        ]);
    }
}
