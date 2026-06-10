<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\DepartmentPostAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DepartmentPostAttachment */
final class DepartmentPostAttachmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_name' => $this->original_name,
            'url' => $this->url(),
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'is_image' => $this->isImage(),
            'uploaded_by' => $this->uploaded_by,
        ];
    }
}
