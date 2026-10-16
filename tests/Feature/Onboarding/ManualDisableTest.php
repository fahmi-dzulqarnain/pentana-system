<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Auth\Login;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Livewire\ClaimAccount;
use App\Models\Payment\FeePeriod;
use App\Models\User;
use App\Services\MemberAccess;
use App\Services\MemberInviter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManualDisableTest extends TestCase
{
    use RefreshDatabase;

    private function member(array $attributes = []): User
    {
        return User::factory()->create(['activated_at' => now(), 'password' => 'secret-password', ...$attributes]);
    }

    public function test_disabling_a_member_signs_them_out_everywhere(): void
    {
        $member = $this->member();
        $member->createToken('iphone');
        $member->deviceTokens()->create(['token' => 'abc123']);

        app(MemberAccess::class)->disable($member);

        $member->refresh();
        $this->assertTrue($member->isDisabled());
        $this->assertFalse($member->canAccessPanel(Filament::getPanel('account')));
        $this->assertSame(0, $member->tokens()->count());
        $this->assertSame(0, $member->deviceTokens()->count());
        $this->assertEquals([$member->id], User::disabled()->pluck('id')->all());
    }

    public function test_a_disabled_member_cannot_sign_in_on_the_web_or_the_app(): void
    {
        $member = $this->member(['email' => 'gone@example.com', 'disabled_at' => now()]);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(Login::class)
            ->fillForm(['email' => 'gone@example.com', 'password' => 'secret-password'])
            ->call('authenticate')
            ->assertHasErrors(['data.email'])
            ->assertSee('This account has been disabled.');
        $this->assertGuest();

        $credentials = ['email' => 'gone@example.com', 'password' => 'secret-password', 'device_name' => 'iphone'];
        $this->postJson('/api/v1/login', $credentials)->assertUnauthorized();

        // Same credentials work once enabled, so the 401 above is the disable, not a typo.
        app(MemberAccess::class)->enable($member);
        $this->postJson('/api/v1/login', $credentials)->assertOk();
    }

    public function test_an_open_session_is_refused_once_disabled(): void
    {
        $member = $this->member();
        $this->actingAs($member)->get('/account')->assertOk();

        app(MemberAccess::class)->disable($member);

        $this->actingAs($member->fresh())->get('/account')->assertForbidden();
    }

    public function test_disabled_members_are_not_billed_and_cannot_claim_or_be_invited(): void
    {
        $claimed = $this->member(['custom_fee' => 20, 'disabled_at' => now()]);
        $unclaimed = User::factory()->create(['email' => 'new@example.com', 'activated_at' => null, 'disabled_at' => now()]);

        $this->artisan('app:generate-monthly-fees')->assertExitCode(0);
        $this->assertFalse(FeePeriod::where('user_id', $claimed->id)->exists());

        $this->assertFalse(app(MemberInviter::class)->canInvite($unclaimed));
        Livewire::test(ClaimAccount::class)
            ->set('email', 'new@example.com')
            ->call('lookup')
            ->assertSet('stage', 'lookup')
            ->assertSet('lookupMessage', 'This account has been disabled. Please contact your admin.');
    }

    public function test_admins_can_disable_and_enable_from_the_users_table(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $member = $this->member();

        Livewire::test(ListUsers::class)
            ->assertTableActionHidden('disable', $admin)
            ->assertTableActionHidden('enable', $member)
            ->callTableAction('disable', $member);
        $this->assertTrue($member->fresh()->isManuallyDisabled());

        Livewire::test(ListUsers::class)
            ->filterTable('account_status', 'disabled')
            ->assertCanSeeTableRecords([$member])
            ->assertTableActionHidden('disable', $member)
            ->callTableAction('enable', $member);
        $this->assertFalse($member->fresh()->isDisabled());
    }
}
