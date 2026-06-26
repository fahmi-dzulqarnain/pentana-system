<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\LaravelPasskeys\Models\Passkey;
use Tests\TestCase;

class PasskeyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_options_returns_a_state_and_challenge(): void
    {
        $this->postJson('/api/v1/passkeys/login/options')
            ->assertOk()
            ->assertJsonStructure(['state', 'publicKey' => ['challenge', 'rpId']]);
    }

    public function test_login_verify_rejects_an_expired_or_unknown_state(): void
    {
        $this->postJson('/api/v1/passkeys/login/verify', [
            'state' => 'does-not-exist',
            'credential' => ['id' => 'x'],
        ])->assertStatus(422);
    }

    public function test_login_verify_rejects_an_invalid_credential(): void
    {
        // A real state from options, but a bogus credential -> no matching passkey.
        $state = $this->postJson('/api/v1/passkeys/login/options')->json('state');

        $this->postJson('/api/v1/passkeys/login/verify', [
            'state' => $state,
            'credential' => ['id' => 'bogus', 'rawId' => 'bogus', 'type' => 'public-key', 'response' => []],
        ])->assertStatus(422);
    }

    public function test_register_and_management_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/passkeys/register/options')->assertStatus(401);
        $this->getJson('/api/v1/passkeys')->assertStatus(401);
        $this->deleteJson('/api/v1/passkeys/1')->assertStatus(401);
    }

    public function test_register_options_returns_state_and_creation_options(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/passkeys/register/options')
            ->assertOk()
            ->assertJsonStructure(['state', 'publicKey' => ['challenge', 'rp', 'user']]);
    }

    public function test_it_lists_only_the_members_own_passkeys(): void
    {
        $member = User::factory()->create();
        Passkey::factory()->create(['authenticatable_id' => $member->id, 'name' => 'My iPhone']);
        Passkey::factory()->create(['authenticatable_id' => User::factory()->create()->id]);

        Sanctum::actingAs($member);

        $this->getJson('/api/v1/passkeys')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'My iPhone');
    }

    public function test_a_member_can_remove_their_passkey_but_not_others(): void
    {
        $member = User::factory()->create();
        $mine = Passkey::factory()->create(['authenticatable_id' => $member->id]);
        $other = Passkey::factory()->create(['authenticatable_id' => User::factory()->create()->id]);

        Sanctum::actingAs($member);

        $this->deleteJson("/api/v1/passkeys/{$mine->id}")->assertOk();
        $this->deleteJson("/api/v1/passkeys/{$other->id}")->assertOk(); // scoped: no-op

        $this->assertDatabaseMissing('passkeys', ['id' => $mine->id]);
        $this->assertDatabaseHas('passkeys', ['id' => $other->id]);
    }
}
