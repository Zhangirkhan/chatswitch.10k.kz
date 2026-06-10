<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\DepartmentPostComment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DepartmentPostComment */
final class DepartmentPostCommentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'post_id' => $this->department_post_id,
            'body' => $this->body,
            'author' => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
