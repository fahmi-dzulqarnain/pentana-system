<?php

namespace Tests\Feature\Nickname;

use App\Models\User;
use App\Services\MemberAccess;
use App\Support\Nickname;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NicknameDisableTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabling_clears_nickname(): void
    {
        $user = User::factory()->create(['nickname' => 'Fahmi']);

        app(MemberAccess::class)->disable($user);

        $user->refresh();
        $this->assertNull($user->nickname);
        $this->assertNotNull($user->disabled_at);
    }

    public function test_enabling_does_not_restore_nickname(): void
    {
        $user = User::factory()->create(['nickname' => 'Fahmi']);

        app(MemberAccess::class)->disable($user);
        app(MemberAccess::class)->enable($user);

        $this->assertNull($user->fresh()->nickname);
    }

    public function test_disabled_members_nickname_is_free(): void
    {
        $a = User::factory()->create(['nickname' => 'Fahmi']);
        $b = User::factory()->create();

        app(MemberAccess::class)->disable($a);

        $validator = validator(['nickname' => 'Fahmi'], ['nickname' => Nickname::rules($b)]);

        $this->assertFalse($validator->fails());
    }
}
