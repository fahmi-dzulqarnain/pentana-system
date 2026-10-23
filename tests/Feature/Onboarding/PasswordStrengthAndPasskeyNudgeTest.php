<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Auth\Profile;
use App\Filament\Pages\Welcome;
use App\Livewire\ClaimAccount;
use App\Livewire\PasskeyNudge;
use App\Livewire\PasskeyWelcome;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class PasswordStrengthAndPasskeyNudgeTest extends TestCase
{
    use RefreshDatabase;

    private const BANNER = 'Add a passkey for faster sign-in';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    private function unclaimed(): User
    {
        return User::factory()->create(['email' => 'aisyah@example.com', 'password' => null, 'activated_at' => null]);
    }

    private function claimWith(string $password)
    {
        return Livewire::test(ClaimAccount::class)
            ->set('email', 'aisyah@example.com')
            ->call('lookup')
            ->fillForm(['birthday' => '1995-04-12', 'password' => $password, 'password_confirmation' => $password])
            ->call('submit');
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(['activated_at' => now(), 'password' => 'secret-password-1', ...$attributes]);
    }

    private function givePasskey(User $user): void
    {
        DB::table('passkeys')->insert([
            'authenticatable_id' => $user->id,
            'name' => 'iPhone',
            'credential_id' => 'cred-'.$user->id,
            'data' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** TestCase fakes an empty breach list first, and the first matching fake wins; start over. */
    private function replaceBreachCheckFake(): void
    {
        Http::swap(new Factory);
    }

    /** Have I Been Pwned's range answer for $password, as if it had been seen in $count breaches. */
    private function breached(string $password, int $count = 42): void
    {
        $hash = strtoupper(sha1($password));
        $this->replaceBreachCheckFake();
        Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).':'.$count)]);
    }

    public function test_a_password_under_twelve_characters_is_refused(): void
    {
        $user = $this->unclaimed();

        $this->claimWith('short-pw-11')->assertHasErrors(['data.password']);
        $this->assertNull($user->refresh()->activated_at);
    }

    public function test_digits_and_symbols_are_not_required(): void
    {
        $this->unclaimed();

        $this->claimWith('teh tarik bawah bulan')->assertHasNoErrors()->assertRedirect(Welcome::getUrl(panel: 'account'));
    }

    public function test_a_breached_password_is_refused(): void
    {
        $user = $this->unclaimed();
        $this->breached('correct horse battery staple');

        $this->claimWith('correct horse battery staple')->assertHasErrors(['data.password']);
        $this->assertNull($user->refresh()->activated_at);
    }

    public function test_an_unreachable_breach_check_lets_the_password_through(): void
    {
        $this->unclaimed();
        $this->replaceBreachCheckFake();
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $this->claimWith('teh tarik bawah bulan')->assertHasNoErrors();
        $this->assertNotNull(User::where('email', 'aisyah@example.com')->first()->activated_at);
    }

    public function test_the_profile_password_change_uses_the_same_rule(): void
    {
        $member = $this->member();
        $this->actingAs($member);

        Livewire::test(Profile::class)
            ->fillForm(['password' => 'short-pw-11', 'passwordConfirmation' => 'short-pw-11', 'currentPassword' => 'secret-password-1'])
            ->call('save')
            ->assertHasFormErrors(['password']);
    }

    public function test_the_claim_and_profile_pages_show_the_meter_and_its_credit(): void
    {
        $this->unclaimed();
        Livewire::test(ClaimAccount::class)
            ->set('email', 'aisyah@example.com')
            ->call('lookup')
            ->assertSee('At least 12 characters')
            ->assertSee('Checked against known breaches when you continue')
            ->assertSeeHtml('https://pages.nist.gov/800-63-4/sp800-63b.html');

        $this->actingAs($this->member())->get('/account/profile')->assertOk()
            ->assertSee('At least 12 characters')
            ->assertSee('NIST SP 800-63B');
    }

    public function test_the_welcome_page_offers_a_passkey(): void
    {
        $this->actingAs($this->member());

        $this->get(Welcome::getUrl(panel: 'account'))->assertOk()
            ->assertSee('Sign in faster next time')
            ->assertSee('Add a passkey')
            ->assertSee('Maybe later')
            ->assertSeeLivewire(PasskeyWelcome::class)
            ->assertDontSee(self::BANNER);
    }

    public function test_the_welcome_page_skips_ahead_when_there_is_already_a_passkey(): void
    {
        $member = $this->member();
        $this->givePasskey($member);
        $this->actingAs($member);

        Livewire::test(Welcome::class)->assertRedirect(Filament::getUrl());
    }

    public function test_the_passkey_is_named_after_the_device(): void
    {
        $this->assertSame('iPhone', PasskeyWelcome::deviceName('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)'));
        $this->assertSame('Mac', PasskeyWelcome::deviceName('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'));
        $this->assertSame('Android', PasskeyWelcome::deviceName('Mozilla/5.0 (Linux; Android 15; Pixel 9)'));
        $this->assertSame('This device', PasskeyWelcome::deviceName(''));
    }

    public function test_members_without_a_passkey_see_the_banner(): void
    {
        $this->actingAs($this->member())->get('/account')->assertOk()->assertSee(self::BANNER);
    }

    public function test_the_banner_is_not_on_the_profile_page(): void
    {
        $this->actingAs($this->member())->get('/account/profile')->assertOk()->assertDontSee(self::BANNER);
    }

    public function test_dismissing_the_banner_hides_it_for_good(): void
    {
        $member = $this->member();
        $this->actingAs($member);

        Livewire::test(PasskeyNudge::class)->call('dismiss')->assertDontSee(self::BANNER);

        $this->assertNotNull($member->refresh()->passkey_prompt_dismissed_at);
        $this->get('/account')->assertOk()->assertDontSee(self::BANNER);
    }

    public function test_members_with_a_passkey_never_see_the_banner(): void
    {
        $member = $this->member();
        $this->givePasskey($member);

        $this->actingAs($member)->get('/account')->assertOk()->assertDontSee(self::BANNER);
    }

    public function test_an_admin_who_still_needs_mfa_sees_only_the_mfa_banner(): void
    {
        config(['auth.admin_mfa_enforced_from' => now()->addWeek()->toDateString()]);

        $this->actingAs($this->member(['is_admin' => true]))->get('/account')->assertOk()
            ->assertSee('Set up two-factor authentication')
            ->assertDontSee(self::BANNER);
    }

    public function test_an_admin_with_mfa_but_no_passkey_sees_the_passkey_banner(): void
    {
        config(['auth.admin_mfa_enforced_from' => now()->addWeek()->toDateString()]);
        $admin = $this->member(['is_admin' => true]);
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $this->actingAs($admin)->get('/account')->assertOk()
            ->assertDontSee('Set up two-factor authentication')
            ->assertSee(self::BANNER);
    }
}
