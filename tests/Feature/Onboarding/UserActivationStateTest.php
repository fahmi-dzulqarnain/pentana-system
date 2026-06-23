<?php

namespace Tests\Feature\Onboarding;

use App\Models\MemberCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserActivationStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_imported_user_can_have_no_password_and_is_not_activated(): void
    {
        $category = MemberCategory::create(['name' => 'Grade 7-10', 'default_fee' => 50]);

        $user = User::create([
            'name' => 'Imported Member',
            'email' => 'imported@example.com',
            'member_category_id' => $category->id,
            'password' => null,
        ]);

        $this->assertNull($user->fresh()->password);
        $this->assertNull($user->activated_at);
        $this->assertFalse($user->isActivated());
    }

    public function test_setting_activated_at_marks_the_user_activated(): void
    {
        $user = User::factory()->create(['activated_at' => now()]);

        $this->assertTrue($user->isActivated());
        $this->assertInstanceOf(Carbon::class, $user->activated_at);
    }
}
