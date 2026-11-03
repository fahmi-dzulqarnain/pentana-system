<?php

namespace Tests\Feature\Onboarding;

use App\Filament\Auth\RequestPasswordReset;
use App\Models\DeviceToken;
use App\Models\QueuedEmail;
use App\Models\User;
use App\Notifications\MemberInvitation;
use App\Notifications\PasswordResetLink;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(['email' => 'aisyah@example.com', 'activated_at' => now(), 'password' => str_repeat('0ld-pass-', 2), ...$attributes]);
    }

    public function test_the_sign_in_page_links_to_forgot_password(): void
    {
        $this->get('/account/login')->assertOk()->assertSee(Filament::getPanel('account')->getRequestPasswordResetUrl(), false);
    }

    public function test_an_active_member_is_emailed_a_reset_link_inline(): void
    {
        Notification::fake();
        $user = $this->member();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'aisyah@example.com'])
            ->call('request')
            ->assertNotified();

        Notification::assertSentTo($user, PasswordResetLink::class);
        // Filament's own is ShouldQueue, which would never leave: there is no queue worker.
        $this->assertNotInstanceOf(ShouldQueue::class, app(FilamentResetPassword::class, ['token' => 't']));
    }

    public function test_the_reset_email_jumps_the_spool_and_expires_with_its_token(): void
    {
        $notification = new PasswordResetLink('token');
        $notification->url = 'https://pentana.test/reset';
        $mail = $notification->toMail($this->member());

        $this->assertSame('https://pentana.test/reset', $mail->actionUrl);

        $email = new Email;
        foreach ($mail->callbacks as $callback) {
            $callback($email);
        }
        $this->assertSame('10', $email->getHeaders()->get(QueuedEmail::PRIORITY_HEADER)->getBodyAsString());
        $this->assertNotNull($email->getHeaders()->get(QueuedEmail::EXPIRES_HEADER));
    }

    public function test_account_emails_still_go_by_email_when_the_member_has_push_on(): void
    {
        // Routine notices go push-instead-of-email to members with the app; these must not:
        // the link is the point, and only email proves the inbox.
        config(['broadcasting.connections.apn.key_id' => 'test-key']);
        $user = $this->member();
        DeviceToken::create(['user_id' => $user->id, 'token' => str_repeat('a', 64), 'platform' => 'ios']);
        $this->assertNotEmpty($user->routeNotificationForApn());

        $this->assertSame(['mail'], (new PasswordResetLink('token'))->via($user));
        $this->assertSame(['mail'], (new MemberInvitation)->via($user));
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        Notification::fake();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'nobody@example.com'])
            ->call('request')
            ->assertNotified(__(Password::RESET_LINK_SENT));

        Notification::assertNothingSent();
    }

    public function test_a_member_who_never_claimed_is_sent_their_claim_link_instead(): void
    {
        Notification::fake();
        $user = $this->member(['activated_at' => null, 'password' => null, 'invited_at' => now()]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'aisyah@example.com'])
            ->call('request')
            ->assertNotified(__(Password::RESET_LINK_SENT));

        Notification::assertSentTo($user, MemberInvitation::class);
        Notification::assertNotSentTo($user, PasswordResetLink::class);
    }

    public function test_a_disabled_member_is_not_sent_a_link(): void
    {
        Notification::fake();
        $this->member(['disabled_at' => now()]);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => 'aisyah@example.com'])
            ->call('request');

        Notification::assertNothingSent();
    }

    public function test_resetting_sets_the_password_and_signs_out_the_apps(): void
    {
        $user = $this->member();
        $user->createToken('iphone');
        $token = Password::broker()->createToken($user);
        $newPassword = str_repeat('n3w-pass-', 2);

        Livewire::withQueryParams(['email' => $user->email, 'token' => $token])
            ->test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->fillForm(['password' => $newPassword, 'passwordConfirmation' => $newPassword])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check($newPassword, $user->fresh()->password));
        $this->assertSame(0, $user->tokens()->count());
    }
}
