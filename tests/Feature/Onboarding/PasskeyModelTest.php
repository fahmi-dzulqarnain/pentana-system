<?php

namespace Tests\Feature\Onboarding;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasskeyModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_has_a_passkeys_relation_that_starts_empty(): void
    {
        $user = User::factory()->create();
        $this->assertCount(0, $user->passkeys); // relation resolves
    }
}
