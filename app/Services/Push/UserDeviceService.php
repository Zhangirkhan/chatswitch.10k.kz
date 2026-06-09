<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\User;
use App\Models\UserDevice;
use App\Tenancy\TenantContext;

final class UserDeviceService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  array{platform: string, fcm_token: string, device_name?: ?string, app_version?: ?string}  $data
     */
    public function register(User $user, array $data): UserDevice
    {
        $companyId = (int) ($user->company_id ?? $this->tenantContext->companyId());
        $token = trim($data['fcm_token']);

        $device = UserDevice::query()
            ->withoutGlobalScope('tenant')
            ->where('fcm_token', $token)
            ->first();

        if ($device === null) {
            $device = new UserDevice;
            $device->fcm_token = $token;
        }

        $device->forceFill([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'platform' => $data['platform'],
            'device_name' => $data['device_name'] ?? null,
            'app_version' => $data['app_version'] ?? null,
        ])->save();

        return $device->fresh() ?? $device;
    }

    public function unregisterByToken(User $user, string $fcmToken): bool
    {
        $deleted = UserDevice::query()
            ->where('user_id', $user->id)
            ->where('fcm_token', trim($fcmToken))
            ->delete();

        return $deleted > 0;
    }

    public function deleteOwned(User $user, UserDevice $device): void
    {
        if ((int) $device->user_id !== (int) $user->id) {
            abort(404);
        }

        $device->delete();
    }
}
