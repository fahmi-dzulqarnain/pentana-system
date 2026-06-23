<?php

namespace Tests\Feature\Onboarding;

use App\Models\User;
use App\Services\AccountClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class AccountClaimServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_claimable_returns_the_unclaimed_user_for_a_known_email(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => null, 'activated_at' => null]);
        $this->assertTrue(app(AccountClaimService::class)->claimable('a@example.com')->is($user));
    }

    public function test_claimable_is_null_for_unknown_or_already_activated(): void
    {
        $svc = app(AccountClaimService::class);
        $this->assertNull($svc->claimable('missing@example.com'));

        User::factory()->create(['email' => 'done@example.com', 'activated_at' => now()]);
        $this->assertNull($svc->claimable('done@example.com'));
    }

    public function test_claim_sets_password_birthday_and_activates(): void
    {
        $user = User::factory()->create(['email' => 'a@example.com', 'password' => null, 'activated_at' => null]);

        $claimed = app(AccountClaimService::class)->claim($user, '2000-05-01', 'sup3r-secret-pw');

        $claimed->refresh();
        $this->assertNotNull($claimed->activated_at);
        $this->assertSame('2000-05-01', $claimed->birthday->toDateString());
        $this->assertTrue(Hash::check('sup3r-secret-pw', $claimed->password));
    }

    public function test_claim_refuses_an_already_activated_account(): void
    {
        $user = User::factory()->create(['activated_at' => now()]);
        $this->expectException(RuntimeException::class);
        app(AccountClaimService::class)->claim($user, '2000-05-01', 'pw');
    }
}
