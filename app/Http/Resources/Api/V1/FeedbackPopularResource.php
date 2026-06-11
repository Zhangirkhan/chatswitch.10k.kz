<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\UserFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserFeedback */
final class FeedbackPopularResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'message' => $this->message,
            'likes_count' => (int) $this->likes_count,
            'liked_by_me' => (bool) ($this->liked_by_me ?? false),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
