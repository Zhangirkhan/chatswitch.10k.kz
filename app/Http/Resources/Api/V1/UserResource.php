<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
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
            'is_active' => (bool) $this->is_active,
            'roles' => $this->getRoleNames()->values()->all(),
            'department' => $this->when(
                $this->relationLoaded('department'),
                fn () => $this->department !== null
                    ? new DepartmentResource($this->department)
                    : null,
            ),
        ];
    }
}
