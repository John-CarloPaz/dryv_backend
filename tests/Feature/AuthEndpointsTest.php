<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_bearer_token(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'device_name' => 'flutter-emulator',
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token']);

        $this->assertDatabaseHas('users', ['email' => 'john@example.com']);
    }

    public function test_login_me_logout_flow(): void
    {
        $user = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123!'),
        ]);

        $login = $this->postJson('/api/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'Password123!',
            'device_name' => 'pixel-7',
        ])->assertOk();

        $token = $login->json('token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }
}
