<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Pages\Welcome;
use App\Livewire\ClaimAccount;
use App\Livewire\RequestClaimLink;
use App\Models\MemberCategory;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Services\AccountClaimService;
use App\Services\MemberInviter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ClaimAccountPageTest extends TestCase
{
    use OpensClaimLinks;
    use RefreshDatabase;

    private function unclaimed(array $attributes = []): User
    {
        return User::factory()->create([
            'name' => 'Aisyah Rahman',
            'email' => 'aisyah@example.com',
            'password' => null,
            'activated_at' => null,
            'invited_at' => now()->subDay(),
            ...$attributes,
        ]);
    }

    private function completeClaim(User $user, string $confirmation = 'sup3r-secret-pw')
    {
        return $this->openClaimLink($user)
            ->fillForm([
                'birthday' => '1995-04-12',
                'password' => 'sup3r-secret-pw',
                'password_confirmation' => $confirmation,
            ])
            ->call('submit');
    }

    public function test_the_claim_page_asks_for_an_email_to_send_a_link_to(): void
    {
        // Assets aren't built in the test env; we only care that the route + layout boot.
        $this->withoutVite();

        $this->get('/claim')->assertOk()->assertSeeLivewire(RequestClaimLink::class);
    }

    public function test_requesting_a_link_emails_an_invited_member_without_extending_the_invitation(): void
    {
        Notification::fake();
        $user = $this->unclaimed();
        $invitedAt = $user->invited_at->toIso8601String();

        Livewire::test(RequestClaimLink::class)
            ->set('email', 'aisyah@example.com')
            ->call('send')
            ->assertSet('sent', true)
            // Nothing about the account is shown: the page is public.
            ->assertDontSee('Aisyah Rahman');

        Notification::assertSentTo($user, MemberInvitation::class);
        $this->assertSame($invitedAt, $user->fresh()->invited_at->toIso8601String());
    }

    public function test_requesting_a_link_invites_a_member_who_was_never_invited(): void
    {
        Notification::fake();
        $user = $this->unclaimed(['invited_at' => null]);

        Livewire::test(RequestClaimLink::class)->set('email', 'aisyah@example.com')->call('send');

        Notification::assertSentTo($user, MemberInvitation::class);
        $this->assertNotNull($user->fresh()->invited_at);
    }

    public function test_the_answer_is_the_same_for_unknown_active_and_disabled_addresses(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'done@example.com', 'activated_at' => now()]);
        User::factory()->create(['email' => 'off@example.com', 'activated_at' => null, 'disabled_at' => now()]);

        foreach (['nobody@example.com', 'done@example.com', 'off@example.com'] as $email) {
            Livewire::test(RequestClaimLink::class)
                ->set('email', $email)
                ->call('send')
                ->assertSet('sent', true)
                ->assertSee('If '.$email.' is waiting to be activated');
        }

        Notification::assertNothingSent();
    }

    public function test_one_link_per_address_every_ten_minutes(): void
    {
        Notification::fake();
        $user = $this->unclaimed();

        app(AccountClaimService::class)->sendLink('aisyah@example.com');
        app(AccountClaimService::class)->sendLink('AISYAH@example.com ');
        Notification::assertSentToTimes($user, MemberInvitation::class, 1);

        $this->travel(AccountClaimService::LINK_DECAY_SECONDS + 1)->seconds();
        app(AccountClaimService::class)->sendLink('aisyah@example.com');
        Notification::assertSentToTimes($user, MemberInvitation::class, 2);
    }

    public function test_the_page_itself_is_rate_limited(): void
    {
        $page = Livewire::test(RequestClaimLink::class);

        foreach (range(1, 5) as $i) {
            $page->set('email', "n{$i}@example.com")->call('send')->assertSet('throttleMessage', null);
        }

        $page->set('email', 'n6@example.com')->call('send');
        $this->assertStringStartsWith('Too many attempts.', $page->get('throttleMessage'));
    }

    public function test_the_signed_link_opens_the_wizard_for_that_member(): void
    {
        $this->withoutVite();
        $category = MemberCategory::create(['name' => 'Senior', 'default_fee' => 50]);
        $user = $this->unclaimed(['member_category_id' => $category->id]);

        $this->get(app(AccountClaimService::class)->link($user))
            ->assertOk()
            ->assertSeeLivewire(ClaimAccount::class)
            ->assertSee('Aisyah Rahman')
            // The grade is confidential.
            ->assertDontSee('Senior');
    }

    public function test_a_link_without_a_valid_signature_is_refused(): void
    {
        $user = $this->unclaimed();
        $other = $this->unclaimed(['email' => 'other@example.com']);

        $this->get(route('claim.accept', ['user' => $user->id, 'invited' => $user->invited_at->getTimestamp()]))->assertForbidden();

        // Swapping the id in a genuine link breaks the signature.
        $tampered = str_replace('/claim/'.$user->id.'?', '/claim/'.$other->id.'?', app(AccountClaimService::class)->link($user));
        $this->get($tampered)->assertForbidden();
    }

    public function test_an_expired_link_is_refused(): void
    {
        $url = app(AccountClaimService::class)->link($this->unclaimed());

        $this->travel(User::INVITATION_VALID_DAYS + 1)->days();

        $this->get($url)->assertForbidden();
    }

    public function test_resending_the_invitation_retires_the_old_link(): void
    {
        Notification::fake();
        $user = $this->unclaimed(['invited_at' => now()->subDays(3)]);
        $old = $this->openClaimLink($user);

        app(MemberInviter::class)->invite($user);

        $old->fillForm(['birthday' => '1995-04-12', 'password' => 'sup3r-secret-pw', 'password_confirmation' => 'sup3r-secret-pw'])
            ->call('submit')
            ->assertForbidden();
        $this->assertNull($user->fresh()->activated_at);

        $this->openClaimLink($user->fresh())->assertSet('linkValid', true);
    }

    public function test_a_used_link_no_longer_works(): void
    {
        $user = $this->unclaimed();
        $this->completeClaim($user);
        Auth::logout();

        $this->openClaimLink($user->fresh())
            ->assertSet('linkValid', false)
            ->assertSee('This link no longer works');
    }

    public function test_the_wizard_form_state_is_initialized_on_mount(): void
    {
        // Guards a browser-only entangle bug: the Wizard's native(false) date picker uses
        // $wire.entangle('data.birthday'), which throws unless the form state is filled on
        // mount so the 'birthday' key exists on the component's $data.
        $data = $this->openClaimLink($this->unclaimed())->get('data');

        $this->assertArrayHasKey('birthday', $data);
    }

    public function test_completing_the_claim_activates_the_account_and_logs_the_member_in(): void
    {
        $user = $this->unclaimed();

        $this->completeClaim($user)->assertRedirect(Welcome::getUrl(panel: 'account'));

        $user->refresh();
        $this->assertNotNull($user->activated_at);
        $this->assertSame('1995-04-12', $user->birthday->toDateString());
        $this->assertTrue(Hash::check('sup3r-secret-pw', $user->password));
        $this->assertSame($user->id, Auth::id());
    }

    public function test_submit_is_forbidden_without_a_link(): void
    {
        $this->unclaimed();

        Livewire::test(ClaimAccount::class, ['user' => 999])
            ->assertSet('linkValid', false)
            ->call('submit')
            ->assertForbidden();
    }

    public function test_submit_claims_only_the_member_the_link_was_for(): void
    {
        $owner = $this->unclaimed(['email' => 'owner@example.com']);
        $victim = $this->unclaimed(['email' => 'victim@example.com']);

        $this->openClaimLink($owner)
            ->set('memberName', $victim->name)
            ->fillForm(['birthday' => '1990-01-01', 'password' => 'sup3r-secret-pw', 'password_confirmation' => 'sup3r-secret-pw'])
            ->call('submit');

        $this->assertNull($victim->fresh()->activated_at);
        $this->assertNotNull($owner->fresh()->activated_at);
        $this->assertSame($owner->id, Auth::id());
    }

    public function test_submit_validates_the_wizard_and_does_not_activate_on_a_mismatched_password(): void
    {
        $user = $this->unclaimed();

        $this->completeClaim($user, 'does-not-match')->assertHasErrors(['data.password']);

        $this->assertNull($user->fresh()->activated_at);
        $this->assertFalse(Auth::check());
    }
}
