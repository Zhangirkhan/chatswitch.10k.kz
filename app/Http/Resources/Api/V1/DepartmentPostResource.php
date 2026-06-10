<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\DepartmentPost;
use App\Models\User;
use App\Support\OrganizationRichTextSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DepartmentPost */
final class DepartmentPostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $assignees = $this->relationLoaded('assignees')
            ? $this->assignees->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
            ])->values()->all()
            : [];

        $attachments = $this->relationLoaded('attachments')
            ? DepartmentPostAttachmentResource::collection($this->attachments)->resolve()
            : [];

        return [
            'id' => $this->id,
            'department_id' => $this->department_id,
            'title' => $this->title,
            'body' => OrganizationRichTextSanitizer::sanitize($this->body),
            'status' => $this->status,
            'due_at' => $this->due_at?->toIso8601String(),
            'author' => $this->author ? [
                'id' => $this->author->id,
                'name' => $this->author->name,
            ] : null,
            'assignees' => $assignees,
            'comments_count' => (int) ($this->comments_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'attachments' => $attachments,
        ];
    }
}
