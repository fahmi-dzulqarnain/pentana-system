<?php

namespace Tests\Feature;

use App\Filament\Auth\Login;
use App\Filament\Auth\Profile;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MultiFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const SET_UP_URL = '/account/multi-factor-authentication/set-up';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    private function app(): AppAuthentication
    {
        return Filament::getPanel('account')->getMultiFactorAuthenticationProviders()['app'];
    }

    private function admin(array $attributes = []): User
    {
        return User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'activated_at' => now(),
            ...$attributes,
        ]);
    }

    /** An admin with an authenticator app already set up; returns the TOTP secret and plain recovery codes. */
    private function enrolledAdmin(): array
    {
        $admin = $this->admin();
        $secret = app(Google2FA::class)->generateSecretKey(16);
        $codes = $this->app()->generateRecoveryCodes();

        $this->app()->saveSecret($admin, $secret);
        $this->app()->saveRecoveryCodes($admin, $codes);

        return [$admin->refresh(), $secret, $codes];
    }

    private function codeFor(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    private function signInWithPassword()
    {
        return Livewire::test(Login::class)
            ->fillForm(['email' => 'admin@example.com', 'password' => 'secret-password'])
            ->call('authenticate');
    }

    public function test_an_admin_without_mfa_is_sent_to_set_up_once_the_cutoff_passes(): void
    {
        config(['auth.admin_mfa_enforced_from' => now()->subDay()->toDateString()]);
        $this->actingAs($this->admin());

        $this->get('/account')->assertRedirect(self::SET_UP_URL);
        $this->get(self::SET_UP_URL)->assertOk();
    }

    public function test_the_cutoff_day_itself_is_enforced(): void
    {
        config(['auth.admin_mfa_enforced_from' => now()->toDateString()]);
        $this->actingAs($this->admin());

        $this->get('/account')->assertRedirect(self::SET_UP_URL);
    }

    public function test_an_unset_cutoff_means_mfa_is_already_required(): void
    {
        config(['auth.admin_mfa_enforced_from' => null]);
        $this->actingAs($this->admin());

        $this->get('/account')->assertRedirect(self::SET_UP_URL);
    }

    public function test_an_admin_in_the_grace_period_gets_in_and_is_reminded(): void
    {
        config(['auth.admin_mfa_enforced_from' => '2026-11-06']);
        $this->travelTo('2026-10-23 09:00');
        $this->actingAs($this->admin());

        $this->get('/account')->assertOk()
            ->assertSee('Set up two-factor authentication')
            ->assertSee('6 November 2026');
    }

    public function test_an_enrolled_admin_gets_in_without_a_reminder(): void
    {
        [$admin] = $this->enrolledAdmin();

        config(['auth.admin_mfa_enforced_from' => now()->subDay()->toDateString()]);
        $this->actingAs($admin)->get('/account')->assertOk()->assertDontSee('Set up two-factor authentication');

        config(['auth.admin_mfa_enforced_from' => now()->addWeek()->toDateString()]);
        $this->actingAs($admin)->get('/account')->assertOk()->assertDontSee('Set up two-factor authentication');
    }

    public function test_members_are_never_made_to_set_up_mfa(): void
    {
        config(['auth.admin_mfa_enforced_from' => now()->subDay()->toDateString()]);
        $this->actingAs(User::factory()->create(['activated_at' => now()]));

        $this->get('/account')->assertOk()->assertDontSee('Set up two-factor authentication');
    }

    public function test_an_admin_sets_up_an_authenticator_app_from_their_profile(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        $page = Livewire::test(Profile::class)
            ->mountAction(TestAction::make('setUpAppAuthentication')->schemaComponent('app', schema: 'content'));
        $secret = decrypt($page->instance()->mountedActions[0]['arguments']['encrypted'])['secret'];

        $page->setActionData(['code' => '000000', 'password' => 'secret-password'])
            ->callMountedAction()
            ->assertHasFormErrors(['code']);
        $page->setActionData(['code' => $this->codeFor($secret), 'password' => 'wrong-password'])
            ->callMountedAction()
            ->assertHasFormErrors(['password']);
        $this->assertNull($admin->refresh()->getAppAuthenticationSecret());

        $page->setActionData(['code' => $this->codeFor($secret), 'password' => 'secret-password'])
            ->callMountedAction()
            ->assertHasNoFormErrors();

        $admin->refresh();
        $this->assertSame($secret, $admin->getAppAuthenticationSecret());
        $this->assertCount(8, $admin->getAppAuthenticationRecoveryCodes());
        $this->assertFalse($admin->mustSetUpMultiFactorAuthentication());
    }

    public function test_the_secret_and_recovery_codes_are_encrypted_at_rest(): void
    {
        [$admin, $secret, $codes] = $this->enrolledAdmin();

        $row = DB::table('users')->where('id', $admin->id)->first();
        $this->assertNotSame($secret, $row->app_authentication_secret);
        $this->assertStringNotContainsString($secret, $row->app_authentication_recovery_codes);
        $this->assertStringNotContainsString($codes[0], decrypt($row->app_authentication_recovery_codes, unserialize: false));
        $this->assertArrayNotHasKey('app_authentication_secret', $admin->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $admin->toArray());
    }

    public function test_password_sign_in_asks_for_the_authenticator_code(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();

        $login = $this->signInWithPassword()->assertHasNoErrors();
        $this->assertGuest();
        $this->assertNotNull($login->get('userUndertakingMultiFactorAuthentication'));

        $login->set('data.multiFactor.app.code', $this->codeFor($secret))
            ->call('authenticate')
            ->assertHasNoErrors();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_wrong_authenticator_code_is_refused(): void
    {
        [, $secret] = $this->enrolledAdmin();
        $wrong = $this->codeFor($secret) === '123456' ? '654321' : '123456';

        $this->signInWithPassword()
            ->set('data.multiFactor.app.code', $wrong)
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code']);
        $this->assertGuest();
    }

    public function test_the_password_alone_never_signs_an_enrolled_admin_in(): void
    {
        $this->enrolledAdmin();

        $this->signInWithPassword()->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.code']);
        $this->assertGuest();
    }

    public function test_a_recovery_code_signs_in_once(): void
    {
        [$admin, , $codes] = $this->enrolledAdmin();

        $this->signInWithPassword()
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $codes[0])
            ->call('authenticate')
            ->assertHasNoErrors();
        $this->assertAuthenticatedAs($admin);
        $this->assertCount(7, $admin->refresh()->getAppAuthenticationRecoveryCodes());

        auth()->logout();

        $this->signInWithPassword()
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $codes[0])
            ->call('authenticate')
            ->assertHasErrors(['data.multiFactor.app.recoveryCode']);
        $this->assertGuest();
    }
}
