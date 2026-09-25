<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * @param  array{name: string, email: string, password: string}  $data
     * @return array{user: User, workspace: Workspace, token: string}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => Arr::get($data, 'name'),
                'email' => Arr::get($data, 'email'),
                'password' => Arr::get($data, 'password'),
            ]);

            $workspace = Workspace::create([
                'user_id' => $user->id,
                'name' => Arr::get($data, 'name')."'s Workspace",
            ]);

            return [
                'user' => $user,
                'workspace' => $workspace,
                'token' => $user->createToken('api')->plainTextToken,
            ];
        });
    }

    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{user: User, workspace: Workspace, token: string}
     */
    public function login(array $credentials): array
    {
        $user = User::where('email', Arr::get($credentials, 'email'))->first();

        if (blank($user) || ! Hash::check(Arr::get($credentials, 'password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $workspace = $user->workspace;

        if (blank($workspace)) {
            abort(500, 'Workspace not found for this user.');
        }

        return [
            'user' => $user,
            'workspace' => $workspace,
            'token' => $user->createToken('api')->plainTextToken,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }
}
