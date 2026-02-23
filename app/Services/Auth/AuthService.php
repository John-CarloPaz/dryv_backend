<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService implements AuthServiceInterface
{
    public function register(string $name, string $email, string $password, string $deviceName): array
    {
        return DB::transaction(function () use ($name, $email, $password, $deviceName): array {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($password),
            ]);

            // Avoid token sprawl for repeated logins from the same device name.
            $user->tokens()->where('name', $deviceName)->delete();

            $token = $user->createToken($deviceName)->plainTextToken;

            return [
                'user' => $user,
                'token' => $token,
            ];
        });
    }

    public function login(string $email, string $password, string $deviceName): array
    {
        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Invalid credentials.'],
            ]);
        }

        $user->tokens()->where('name', $deviceName)->delete();

        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    public function logoutCurrentToken(Request $request): void
    {
        $user = $request->user();

        if (!$user) {
            return;
        }

        $token = $user->currentAccessToken();

        if ($token) {
            $token->delete();
        }
    }

    public function logoutAllTokens(Request $request): void
    {
        $user = $request->user();

        if (!$user) {
            return;
        }

        $user->tokens()->delete();
    }
}
