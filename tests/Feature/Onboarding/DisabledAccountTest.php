<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Auth\Login;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Livewire\ClaimAccount;
use App\Models\Lunch;
use App\Models\Payment\FeePeriod;
use App\Models\User;
use App\Notifications\BillReminder;
use App\Notifications\LunchVoteNudge;
use App\Notifications\MemberInvitation;
use App\Services\AccountClaimService;
use App\Services\MemberInviter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class DisabledAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 09:00');
    }

    private function disabled(array $attributes = []): User
    {
        return User::factory()->create([
            'activated_at' => null,
            'password' => null,
            'invited_at' => now()->subDays(31),
            ...$attributes,
        ]);
    }

    public function test_an_unclaimed_member_is_disabled_once_the_invitation_is_a_month_old(): void
    {
        $neverInvited = User::factory()->create(['activated_at' => null, 'invited_at' => null]);
        $fresh = User::factory()->create(['activated_at' => null, 'invited_at' => now()->subDays(29)]);
        $expired = $this->disabled();
        $claimed = User::factory()->create(['activated_at' => now(), 'invited_at' => now()->subDays(90)]);

        $this->assertFalse($neverInvited->isDisabled());
        $this->assertFalse($fresh->isDisabled());
        $this->assertTrue($expired->isDisabled());
        $this->assertFalse($claimed->isDisabled());

        // The query scopes agree with isDisabled().
        $this->assertEquals([$expired->id], User::disabled()->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$neverInvited->id, $fresh->id, $claimed->id],
            User::enabled()->pluck('id')->all(),
        );
    }

    public function test_a_disabled_member_cannot_claim(): void
    {
        $user = $this->disabled(['email' => 'late@example.com']);

        $this->assertNull(app(AccountClaimService::class)->claimable('late@example.com'));

        Livewire::test(ClaimAccount::class)
            ->set('email', 'late@example.com')
            ->call('lookup')
            ->assertSet('stage', 'lookup')
            ->assertSet('lookupMessage', 'Your invitation expired on '.$user->invitationExpiresAt()->translatedFormat('j F Y').'. Ask your admin to send you a new one.');
    }

    public function test_a_disabled_member_is_told_why_they_cannot_log_in(): void
    {
        $this->disabled(['email' => 'late@example.com']);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(Login::class)
            ->fillForm(['email' => 'late@example.com', 'password' => 'anything'])
            ->call('authenticate')
            ->assertHasErrors(['data.email'])
            ->assertSee('Your invitation has expired, so this account is disabled.');
    }

    public function test_disabled_members_are_not_billed(): void
    {
        $enabled = User::factory()->create(['activated_at' => now(), 'custom_fee' => 20]);
        $disabled = $this->disabled(['custom_fee' => 20]);

        $this->artisan('app:generate-monthly-fees')->assertExitCode(0);

        $this->assertTrue(FeePeriod::where('user_id', $enabled->id)->exists());
        $this->assertFalse(FeePeriod::where('user_id', $disabled->id)->exists());
    }

    public function test_disabled_members_get_no_reminders_or_nudges(): void
    {
        Notification::fake();
        $disabled = $this->disabled();
        FeePeriod::create(['user_id' => $disabled->id, 'month_date' => now()->startOfMonth(), 'amount_due' => 20, 'status' => 'unpaid']);
        Lunch::factory()->create(['status' => 'published', 'date' => now()->addDay(), 'deadline' => now()->addHours(5)]);

        $this->artisan('app:send-bill-reminders')->assertExitCode(0);
        $this->artisan('app:send-lunch-vote-nudges')->assertExitCode(0);

        Notification::assertNotSentTo($disabled, BillReminder::class);
        Notification::assertNotSentTo($disabled, LunchVoteNudge::class);
    }

    public function test_resending_the_invitation_re_enables_the_account(): void
    {
        Notification::fake();
        $user = $this->disabled(['email' => 'late@example.com']);

        app(MemberInviter::class)->invite($user);

        $this->assertFalse($user->fresh()->isDisabled());
        $this->assertNotNull(app(AccountClaimService::class)->claimable('late@example.com'));
        Notification::assertSentTo($user, MemberInvitation::class);
    }

    public function test_the_invitation_states_when_the_offer_expires(): void
    {
        $user = User::factory()->create(['activated_at' => null, 'invited_at' => now()]);

        $mail = (new MemberInvitation)->toMail($user);
        $html = view($mail->view['html'], $mail->viewData)->render();

        $this->assertStringContainsString('Offer valid until 20 December 2026', $html);
    }

    public function test_admins_can_filter_and_see_disabled_members(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
        $disabled = $this->disabled();
        $unclaimed = User::factory()->create(['activated_at' => null, 'invited_at' => now()->subDays(3)]);

        Livewire::test(ListUsers::class)
            ->filterTable('account_status', 'disabled')
            ->assertCanSeeTableRecords([$disabled])
            ->assertCanNotSeeTableRecords([$unclaimed]);

        Livewire::test(ListUsers::class)
            ->filterTable('account_status', 'unclaimed')
            ->assertCanSeeTableRecords([$unclaimed])
            ->assertCanNotSeeTableRecords([$disabled]);
    }
}
