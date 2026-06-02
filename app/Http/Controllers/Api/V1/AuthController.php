<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MobileLoginRequest;
use App\Http\Requests\Api\V1\MobilePinLoginRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function login(MobileLoginRequest $request): JsonResponse
    {
        $user = $request->authenticateUser();
        $user->load('department');

        return $this->tokenResponse($user);
    }

    public function loginPin(MobilePinLoginRequest $request): JsonResponse
    {
        $user = $request->authenticateUser();
        $user->load('department');

        return $this->tokenResponse($user);
    }

    private function tokenResponse(User $user): JsonResponse
    {
        $token = $user->createToken('mobile')->plainTextToken;
        $tenant = app(TenantContext::class)->company();

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'tenant' => $tenant !== null ? new TenantResource($tenant) : null,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();
        if ($token !== null) {
            $token->delete();
        }

        return response()->json(['message' => 'Выход выполнен.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user?->load('department');

        $tenant = app(TenantContext::class)->company();

        return response()->json([
            'data' => new UserResource($user),
            'tenant' => $tenant !== null ? new TenantResource($tenant) : null,
        ]);
    }
}
