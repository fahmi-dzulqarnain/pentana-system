<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_claimed_member_can_log_in_and_receive_a_token(): void
    {
        User::factory()->create([
            'email' => 'm@org.com',
            'password' => 'secret123',
            'activated_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'm@org.com', 'password' => 'secret123', 'device_name' => 'iPhone',
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        User::factory()->create(['email' => 'm@org.com', 'password' => 'secret123', 'activated_at' => now()]);

        $this->postJson('/api/v1/login', ['email' => 'm@org.com', 'password' => 'wrong', 'device_name' => 'iPhone'])
            ->assertStatus(401);
    }

    public function test_an_unactivated_passwordless_account_cannot_log_in(): void
    {
        User::factory()->create(['email' => 'u@org.com', 'password' => null, 'activated_at' => null]);

        $this->postJson('/api/v1/login', ['email' => 'u@org.com', 'password' => 'whatever', 'device_name' => 'iPhone'])
            ->assertStatus(401);
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/login', ['email' => 'x@org.com', 'password' => 'bad', 'device_name' => 'iPhone'])
                ->assertStatus(401);
        }

        $this->postJson('/api/v1/login', ['email' => 'x@org.com', 'password' => 'bad', 'device_name' => 'iPhone'])
            ->assertStatus(429);
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_me_returns_the_authenticated_member(): void
    {
        $user = User::factory()->create(['activated_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create(['activated_at' => now()]);
        $token = $user->createToken('iPhone')->plainTextToken;
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/logout')->assertOk();

        // The token row is gone — in production (fresh boot per request) it can no longer
        // authenticate. We assert the revocation directly rather than re-requesting, because
        // the test app stays booted and the sanctum guard caches the already-resolved user.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
