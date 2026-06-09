<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'department_id' => $this->department_id,
            'department_ids' => $this->when(
                $this->relationLoaded('departments'),
                fn () => $this->departments->pluck('id')->map(fn ($v) => (int) $v)->all(),
            ),
            'is_active' => (bool) $this->is_active,
            'roles' => $this->getRoleNames()->values()->all(),
            'role' => $this->getRoleNames()->first(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values()->all(),
            'department' => $this->when(
                $this->relationLoaded('department'),
                fn () => $this->department !== null
                    ? new DepartmentResource($this->department)
                    : null,
            ),
            'departments' => $this->when(
                $this->relationLoaded('departments'),
                fn () => DepartmentResource::collection($this->departments),
            ),
        ];
    }
}
