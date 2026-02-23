<?php

namespace App\Services\Auth;

use Illuminate\Http\Request;

interface AuthServiceInterface
{
    /** @return array{user: \App\Models\User, token: string} */
    public function register(string $name, string $email, string $password, string $deviceName): array;

    /** @return array{user: \App\Models\User, token: string} */
    public function login(string $email, string $password, string $deviceName): array;

    public function logoutCurrentToken(Request $request): void;

    public function logoutAllTokens(Request $request): void;
}
