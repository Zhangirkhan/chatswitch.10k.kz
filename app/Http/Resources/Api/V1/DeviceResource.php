<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserDevice */
final class DeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'fcm_token' => $this->fcm_token,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
