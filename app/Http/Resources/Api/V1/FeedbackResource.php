<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\UserFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserFeedback */
final class FeedbackResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'message' => $this->message,
            'source' => $this->source->value,
            'status' => $this->status->value,
            'app_version' => $this->app_version,
            'device_platform' => $this->device_platform,
            'device_model' => $this->device_model,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
