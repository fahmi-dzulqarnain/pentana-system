<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Auth\Profile;
use App\Livewire\ClaimAccount;
use App\Models\MemberCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class ClaimAccountPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_claim_page_renders_for_guests(): void
    {
        // Assets aren't built in the test env; we only care that the route + layout boot.
        $this->withoutVite();

        $this->get('/claim')->assertOk()->assertSeeLivewire(ClaimAccount::class);
    }

    public function test_the_wizard_form_state_is_initialized_on_mount(): void
    {
        // Guards a browser-only entangle bug: the Wizard's native(false) date picker uses
        // $wire.entangle('data.birthday'), which throws unless the form state is filled on
        // mount so the 'birthday' key exists on the component's $data.
        $data = Livewire::test(ClaimAccount::class)->get('data');

        $this->assertArrayHasKey('birthday', $data);
    }

    public function test_lookup_reveals_the_wizard_for_an_unclaimed_member(): void
    {
        $category = MemberCategory::create(['name' => 'Senior', 'default_fee' => 50]);
        $user = User::factory()->create([
            'name' => 'Aisyah Rahman',
            'email' => 'aisyah@example.com',
            'password' => null,
            'activated_at' => null,
            'member_category_id' => $category->id,
        ]);

        Livewire::test(ClaimAccount::class)
            ->set('email', 'aisyah@example.com')
            ->call('lookup')
            ->assertSet('stage', 'claim')
            ->assertSet('userId', $user->id)
            ->assertSet('memberName', 'Aisyah Rahman')
            ->assertSet('memberGrade', 'Senior')
            ->assertSet('lookupMessage', null)
            ->assertSchemaExists('claimForm')
            ->assertWizardStepExists(1)
            ->assertWizardStepExists(2)
            ->assertSee('Aisyah Rahman')
            ->assertSee('Senior');
    }

    public function test_lookup_reports_an_unknown_email(): void
    {
        Livewire::test(ClaimAccount::class)
            ->set('email', 'nobody@example.com')
            ->call('lookup')
            ->assertSet('stage', 'lookup')
            ->assertSet('lookupMessage', "We couldn't find that email — please check with your admin.");
    }

    public function test_lookup_reports_an_already_active_account(): void
    {
        User::factory()->create(['email' => 'done@example.com', 'activated_at' => now()]);

        Livewire::test(ClaimAccount::class)
            ->set('email', 'done@example.com')
            ->call('lookup')
            ->assertSet('stage', 'lookup')
            ->assertSet('lookupMessage', 'This account is already active — please log in.');
    }

    public function test_completing_the_claim_activates_the_account_and_logs_the_member_in(): void
    {
        $user = User::factory()->create([
            'email' => 'aisyah@example.com',
            'password' => null,
            'activated_at' => null,
        ]);

        Livewire::test(ClaimAccount::class)
            ->set('email', 'aisyah@example.com')
            ->call('lookup')
            ->assertSet('stage', 'claim')
            ->fillForm([
                'birthday' => '1995-04-12',
                'password' => 'sup3r-secret-pw',
                'password_confirmation' => 'sup3r-secret-pw',
            ])
            ->call('submit')
            ->assertRedirect(Profile::getUrl(panel: 'account'));

        $user->refresh();
        $this->assertNotNull($user->activated_at);
        $this->assertSame('1995-04-12', $user->birthday->toDateString());
        $this->assertTrue(Hash::check('sup3r-secret-pw', $user->password));
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_submit_is_forbidden_without_a_resolved_user(): void
    {
        Livewire::test(ClaimAccount::class)
            ->call('submit')
            ->assertForbidden();
    }

    public function test_submit_ignores_a_tampered_user_id_and_only_claims_the_email_owner(): void
    {
        // The email the attacker actually controls / knows (e.g. their own listed account).
        $owner = User::factory()->create(['email' => 'owner@example.com', 'password' => null, 'activated_at' => null]);
        // A different unclaimed account they try to take over by guessing its id.
        $victim = User::factory()->create(['email' => 'victim@example.com', 'password' => null, 'activated_at' => null]);

        Livewire::test(ClaimAccount::class)
            ->set('email', 'owner@example.com')
            ->call('lookup')
            ->set('userId', $victim->id) // tamper: point the client state at the victim
            ->fillForm([
                'birthday' => '1990-01-01',
                'password' => 'sup3r-secret-pw',
                'password_confirmation' => 'sup3r-secret-pw',
            ])
            ->call('submit');

        // The victim is untouched; only the owner of the submitted email is claimed + logged in.
        $this->assertNull($victim->fresh()->activated_at);
        $this->assertNotNull($owner->fresh()->activated_at);
        $this->assertSame($owner->id, Auth::id());
    }

    public function test_submit_validates_the_wizard_and_does_not_activate_on_a_mismatched_password(): void
    {
        $user = User::factory()->create([
            'email' => 'aisyah@example.com',
            'password' => null,
            'activated_at' => null,
        ]);

        Livewire::test(ClaimAccount::class)
            ->set('email', 'aisyah@example.com')
            ->call('lookup')
            ->fillForm([
                'birthday' => '1995-04-12',
                'password' => 'sup3r-secret-pw',
                'password_confirmation' => 'does-not-match',
            ])
            ->call('submit')
            ->assertHasErrors(['data.password']);

        $user->refresh();
        $this->assertNull($user->activated_at);
        $this->assertFalse(Auth::check());
    }
}
