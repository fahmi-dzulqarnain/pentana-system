<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeNicknameTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_includes_nickname_and_display_name(): void
    {
        $user = User::factory()->create(['name' => 'Fahmi Dzulqarnain', 'nickname' => null]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.nickname', null)
            ->assertJsonPath('data.display_name', 'Fahmi Dzulqarnain')
            ->assertJsonPath('data.name', 'Fahmi Dzulqarnain');
    }

    public function test_member_can_set_nickname(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/me', ['nickname' => ' Fahmi '])
            ->assertOk()
            ->assertJsonPath('data.nickname', 'Fahmi')
            ->assertJsonPath('data.display_name', 'Fahmi');
    }

    public function test_member_can_clear_nickname(): void
    {
        Sanctum::actingAs(User::factory()->create(['nickname' => 'Fahmi']));

        $this->patchJson('/api/v1/me', ['nickname' => null])
            ->assertOk()
            ->assertJsonPath('data.nickname', null);
    }

    public function test_duplicate_is_422(): void
    {
        User::factory()->create(['nickname' => 'fahmi']);
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/me', ['nickname' => 'Fahmi'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That nickname is already taken.')
            ->assertJsonValidationErrors(['nickname']);
    }

    public function test_losing_a_race_for_a_nickname_is_422_not_500(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // Another member takes the nickname after validation passed, just before our save.
        $fired = false;
        User::updating(function () use (&$fired) {
            if (! $fired) {
                $fired = true;
                User::factory()->create(['nickname' => 'Fahmi']);
            }
        });

        $this->patchJson('/api/v1/me', ['nickname' => 'Fahmi'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That nickname is already taken.')
            ->assertJsonValidationErrors(['nickname']);

        $this->assertNull($user->fresh()->nickname);
    }

    public function test_keeping_own_nickname_is_allowed(): void
    {
        Sanctum::actingAs(User::factory()->create(['nickname' => 'Fahmi']));

        $this->patchJson('/api/v1/me', ['nickname' => 'fahmi'])->assertOk();
    }

    public function test_too_long_is_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/me', ['nickname' => str_repeat('a', 31)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nickname']);
    }

    public function test_missing_nickname_key_is_422_and_does_not_clear(): void
    {
        $user = User::factory()->create(['nickname' => 'Fahmi']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nickname']);

        $this->assertSame('Fahmi', $user->fresh()->nickname);
    }

    public function test_non_string_nickname_is_422(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->patchJson('/api/v1/me', ['nickname' => ['x']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nickname']);
    }

    public function test_unauthenticated_is_401(): void
    {
        $this->patchJson('/api/v1/me', ['nickname' => 'Fahmi'])->assertStatus(401);
    }

    public function test_cannot_change_other_fields(): void
    {
        $user = User::factory()->create(['name' => 'Original']);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/me', ['name' => 'X', 'nickname' => 'Y'])->assertOk();

        $this->assertSame('Original', $user->fresh()->name);
        $this->assertSame('Y', $user->fresh()->nickname);
    }
}
