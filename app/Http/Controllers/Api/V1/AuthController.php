<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MobileLoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController extends Controller
{
    public function login(MobileLoginRequest $request): JsonResponse
    {
        $user = $request->authenticateUser();
        $user->load('department');
        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
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

        return (new UserResource($user))->response();
    }
}
