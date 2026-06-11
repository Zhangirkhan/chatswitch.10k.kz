<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RegisterDeviceRequest;
use App\Http\Requests\Api\V1\UnregisterDeviceRequest;
use App\Http\Resources\Api\V1\DeviceResource;
use App\Models\UserDevice;
use App\Services\Push\UserDeviceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DeviceController extends Controller
{
    public function __construct(
        private readonly UserDeviceService $deviceService,
    ) {}

    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $validated = $request->validated();
        $validated['last_seen_ip'] = $request->ip();
        $existing = UserDevice::query()
            ->where('user_id', $user->id)
            ->where('fcm_token', $validated['fcm_token'])
            ->exists();

        $device = $this->deviceService->register($user, $validated);
        $status = $existing ? 200 : 201;

        return (new DeviceResource($device))
            ->response()
            ->setStatusCode($status);
    }

    public function destroy(Request $request, UserDevice $device): Response
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $this->deviceService->deleteOwned($user, $device);

        return response()->noContent();
    }

    public function unregister(UnregisterDeviceRequest $request): Response|JsonResponse
    {
        $user = $request->user();
        abort_if($user === null, 401);

        $this->deviceService->unregisterByToken($user, $request->validated('fcm_token'));

        return response()->noContent();
    }
}
