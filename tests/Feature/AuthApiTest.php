<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_returns_a_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Fayza',
            'email' => 'fayza@example.com',
            'password' => 'rahasia123',
            'password_confirmation' => 'rahasia123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'fayza@example.com')
            ->assertJsonStructure(['data' => ['token']]);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_with_valid_credentials_returns_a_working_token(): void
    {
        User::factory()->create(['email' => 'adi@example.com', 'password' => 'rahasia123']);

        $token = $this->postJson('/api/login', [
            'email' => 'adi@example.com',
            'password' => 'rahasia123',
        ])->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email', 'adi@example.com');
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'adi@example.com', 'password' => 'rahasia123']);

        $this->postJson('/api/login', ['email' => 'adi@example.com', 'password' => 'salah'])
            ->assertUnprocessable()
            ->assertJsonPath('status', 'error')
            ->assertJsonValidationErrors('email');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_protected_routes_return_401_json_without_token_even_without_accept_header(): void
    {
        $this->post('/api/chat-ai', ['prompt' => 'Halo'])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('status', 'error');

        $this->getJson('/api/chat-ai/history')->assertUnauthorized();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->withToken('1|token-palsu')->postJson('/api/chat-ai', ['prompt' => 'Halo'])
            ->assertUnauthorized();
    }
}
