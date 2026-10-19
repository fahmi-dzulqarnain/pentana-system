<?php

namespace Tests\Feature\Birthday;

use App\Models\AutoEmail;
use App\Models\User;
use App\Notifications\BirthdayWish;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendBirthdayEmailsTest extends TestCase
{
    use RefreshDatabase;

    private function birthdayTemplate(bool $active = true): AutoEmail
    {
        return AutoEmail::create([
            'type' => 'birthday',
            'is_active' => $active,
            'subject' => 'Happy Birthday!',
            'body' => 'Hi {name}, happy birthday from PENTANA!',
        ]);
    }

    public function test_it_emails_users_whose_birthday_is_today_with_personalized_body(): void
    {
        Notification::fake();
        $this->birthdayTemplate();
        $user = User::factory()->create(['name' => 'Aisyah', 'birthday' => now()->subYears(30)->toDateString()]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertSentTo($user, BirthdayWish::class, function (BirthdayWish $n) {
            return str_contains($n->body, 'Aisyah') && ! str_contains($n->body, '{name}');
        });
        $this->assertNotNull($user->fresh()->birthday_email_sent_at);
    }

    public function test_it_skips_users_whose_birthday_is_not_today(): void
    {
        Notification::fake();
        $this->birthdayTemplate();
        $user = User::factory()->create(['birthday' => now()->addDay()->subYears(30)->toDateString()]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertNotSentTo($user, BirthdayWish::class);
    }

    public function test_it_does_nothing_without_an_active_template(): void
    {
        Notification::fake();
        $this->birthdayTemplate(active: false);
        $user = User::factory()->create(['birthday' => now()->subYears(20)->toDateString()]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_it_does_not_resend_within_the_same_year(): void
    {
        Notification::fake();
        $this->birthdayTemplate();
        $user = User::factory()->create([
            'birthday' => now()->subYears(25)->toDateString(),
            'birthday_email_sent_at' => now(),
        ]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertNotSentTo($user, BirthdayWish::class);
    }

    public function test_a_blank_template_sends_the_built_in_message(): void
    {
        Notification::fake();
        AutoEmail::create(['type' => 'birthday', 'is_active' => true, 'subject' => null, 'body' => '<p></p>']);
        $user = User::factory()->create(['birthday' => now()->subYears(30)->toDateString()]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertSentTo($user, BirthdayWish::class, fn (BirthdayWish $n) => $n->subject === null && $n->body === null);
    }

    public function test_names_are_personalised_in_the_subject_and_escaped_in_the_body(): void
    {
        Notification::fake();
        AutoEmail::create(['type' => 'birthday', 'is_active' => true, 'subject' => 'Happy birthday {name}', 'body' => '<p>Hi {name}</p>']);
        $user = User::factory()->create(['name' => 'Tom & Jerry', 'birthday' => now()->subYears(30)->toDateString()]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertSentTo($user, BirthdayWish::class, fn (BirthdayWish $n) => $n->subject === 'Happy birthday Tom & Jerry'
            && $n->body === '<p>Hi Tom &amp; Jerry</p>');
    }

    public function test_the_built_in_message_greets_the_member_by_nickname(): void
    {
        $user = User::factory()->create(['name' => 'Muhammad Fahmi bin Dzulqarnain', 'nickname' => 'Fahmi']);

        $mail = (new BirthdayWish)->toMail($user);

        $this->assertSame('Happy birthday, Fahmi! 🎂', $mail->subject);
    }

    public function test_a_custom_template_subject_uses_the_nickname_when_set(): void
    {
        Notification::fake();
        AutoEmail::create(['type' => 'birthday', 'is_active' => true, 'subject' => 'Hi {name}', 'body' => '<p>Hi {name}</p>']);
        $user = User::factory()->create([
            'name' => 'Muhammad Fahmi bin Dzulqarnain',
            'nickname' => 'Fahmi',
            'birthday' => now()->subYears(30)->toDateString(),
        ]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertSentTo($user, BirthdayWish::class, fn (BirthdayWish $n) => $n->subject === 'Hi Fahmi');
    }

    public function test_a_custom_template_subject_falls_back_to_the_name_without_a_nickname(): void
    {
        Notification::fake();
        AutoEmail::create(['type' => 'birthday', 'is_active' => true, 'subject' => 'Hi {name}', 'body' => '<p>Hi {name}</p>']);
        $user = User::factory()->create([
            'name' => 'Muhammad Fahmi bin Dzulqarnain',
            'nickname' => null,
            'birthday' => now()->subYears(30)->toDateString(),
        ]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertSentTo($user, BirthdayWish::class, fn (BirthdayWish $n) => $n->subject === 'Hi Muhammad Fahmi bin Dzulqarnain');
    }

    public function test_a_nickname_is_escaped_in_the_body(): void
    {
        Notification::fake();
        AutoEmail::create(['type' => 'birthday', 'is_active' => true, 'subject' => 'Hi {name}', 'body' => '<p>Hi {name}</p>']);
        $user = User::factory()->create(['nickname' => '<b>Al</b>', 'birthday' => now()->subYears(30)->toDateString()]);

        $this->artisan('app:send-birthday-emails')->assertSuccessful();

        Notification::assertSentTo($user, BirthdayWish::class, fn (BirthdayWish $n) => $n->body === '<p>Hi &lt;b&gt;Al&lt;/b&gt;</p>');
    }
}
