<?php

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_with_a_finance_role_can_manage_finance(): void
    {
        $this->assertFalse(User::factory()->create()->canManageFinance());
        $this->assertFalse(User::factory()->admin()->create()->canManageFinance());
        $this->assertTrue(User::factory()->treasurer()->create()->canManageFinance());
        $this->assertTrue(User::factory()->superAdmin()->create()->canManageFinance());
        $this->assertFalse(User::factory()->create(['is_admin' => false, 'is_treasurer' => true])->canManageFinance());
    }

    public function test_make_super_admin_command_promotes_the_user(): void
    {
        $user = User::factory()->create(['email' => 'alice@example.com']);

        $this->artisan('app:make-super-admin', ['email' => 'alice@example.com'])->assertSuccessful();

        $user->refresh();
        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->is_super_admin);
    }

    public function test_make_super_admin_command_fails_for_unknown_email(): void
    {
        $this->artisan('app:make-super-admin', ['email' => 'nobody@example.com'])
            ->expectsOutputToContain('No user')
            ->assertFailed();
    }
}
