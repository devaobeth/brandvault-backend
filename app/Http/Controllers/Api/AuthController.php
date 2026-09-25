<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Http\Resources\WorkspaceResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AuthController extends Controller
{
    public function __construct(private AuthService $authService) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register($request->validated());

        return response()->json([
            'token' => Arr::get($result, 'token'),
            'user' => new UserResource(Arr::get($result, 'user')),
            'workspace' => new WorkspaceResource(Arr::get($result, 'workspace')),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login($request->validated());

        return response()->json([
            'token' => Arr::get($result, 'token'),
            'user' => new UserResource(Arr::get($result, 'user')),
            'workspace' => new WorkspaceResource(Arr::get($result, 'workspace')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('workspace');

        return response()->json([
            'user' => new UserResource($user),
            'workspace' => filled($user->workspace)
                ? new WorkspaceResource($user->workspace)
                : null,
        ]);
    }
}
