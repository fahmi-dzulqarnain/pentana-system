<?php

namespace Tests\Feature\Localisation;

use App\Models\Activity;
use App\Models\Lunch;
use App\Models\User;
use App\Notifications\ActivityPublished;
use App\Notifications\BillReminder;
use App\Notifications\LunchPublished;
use App\Notifications\LunchVoteNudge;
use App\Notifications\WaitlistPromoted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Traits\Localizable;
use Tests\TestCase;

class NotificationsLocalisedTest extends TestCase
{
    use Localizable, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.locales' => 'en,ms,id']);
        Carbon::setTestNow('2026-11-06 09:00'); // a Friday
    }

    public function test_one_send_localises_per_recipient_and_restores_locale(): void
    {
        $ms = User::factory()->create(['locale' => 'ms']);
        $id = User::factory()->create(['locale' => 'id']);
        $en = User::factory()->create(['locale' => null]);
        $lunch = Lunch::factory()->create(['date' => now(), 'deadline' => now()->addHours(3)]);

        app()->setLocale('en');
        Notification::send([$ms, $id, $en], new LunchPublished($lunch));

        $this->assertSame('en', app()->getLocale(), 'locale leaked out of the send');

        // Day/month names follow the recipient; the meridiem stays AM/PM, matching the portal's
        // translatedFormat() output (Carbon keeps the 'en' fallback meridiem under a global locale).
        $text = fn (User $u) => json_encode($u->notifications()->first()->data, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('"title":"Menu makan tengah hari diterbitkan: ', $text($ms));
        $this->assertStringContainsString('"body":"Pesan sebelum 12:00 PM, Jum 6 Nov."', $text($ms));
        $this->assertStringContainsString('"title":"Menu makan siang diterbitkan: ', $text($id));
        $this->assertStringContainsString('"body":"Pesan sebelum 12:00 PM, Jum 6 Nov."', $text($id));
        $this->assertStringContainsString('"body":"Order by 12:00 PM, Fri 6 Nov."', $text($en));
    }

    public function test_members_without_an_enabled_locale_get_english_whatever_the_sender_uses(): void
    {
        config(['app.locales' => 'en,ms']);
        $none = User::factory()->create(['locale' => null]);
        $disabled = User::factory()->create(['locale' => 'id']);
        $lunch = Lunch::factory()->create(['date' => now(), 'deadline' => now()->addHours(3)]);

        app()->setLocale('ms'); // e.g. an admin browsing in Malay publishes the lunch
        Notification::send([$none, $disabled], new LunchPublished($lunch));

        $this->assertSame('ms', app()->getLocale(), 'the sender\'s locale was not restored');

        foreach ([$none, $disabled] as $user) {
            $text = json_encode($user->notifications()->first()->data, JSON_UNESCAPED_UNICODE);
            $this->assertStringContainsString('"body":"Order by 12:00 PM, Fri 6 Nov."', $text);
        }
    }

    public function test_mail_and_push_follow_the_recipient(): void
    {
        $id = User::factory()->create(['locale' => 'id']);
        $lunch = Lunch::factory()->create(['date' => now(), 'deadline' => now()->addHours(3)]);
        $notification = new LunchPublished($lunch);

        $mail = $this->withLocale('id', fn () => $notification->toMail($id));
        $this->assertSame('Menu makan siang untuk Jum, 6 Nov', $mail->subject);
        $this->assertStringContainsString('Jumat, 6 Nov', implode(' ', $mail->introLines));
        $this->assertSame('Buat pesanan Anda', $mail->actionText);

        $push = $this->withLocale('id', fn () => $notification->toApn($id));
        $this->assertStringStartsWith('Menu makan siang diterbitkan: ', $push->title);
    }

    public function test_admin_authored_names_stay_as_written(): void
    {
        $user = User::factory()->create(['locale' => 'ms']);
        $activity = Activity::factory()->create(['title' => 'Futsal Night', 'location' => 'Arena KL', 'starts_at' => now()->addDay()]);

        $mail = $this->withLocale('ms', fn () => (new ActivityPublished($activity))->toMail($user));

        $this->assertSame('Aktiviti baharu: Futsal Night', $mail->subject);
        $this->assertContains('Futsal Night diadakan pada Sabtu, 7 Nov · 9:00 AM.', $mail->introLines);
        $this->assertContains('Lokasi: Arena KL', $mail->introLines);
    }

    public function test_every_notification_renders_in_malay_and_indonesian(): void
    {
        $user = User::factory()->create();
        $lunch = Lunch::factory()->create(['date' => now(), 'deadline' => now()->addHours(3)]);
        $activity = Activity::factory()->create(['starts_at' => now()->addDay()]);

        $this->withLocale('ms', function () use ($user, $lunch, $activity) {
            $this->assertSame('Peringatan yuran tertunggak', (new BillReminder(50, 2))->toDatabase($user)['title']);
            $this->assertStringContainsString('Undian ditutup pada', (new LunchVoteNudge($lunch))->toDatabase($user)['body']);
            $this->assertStringStartsWith('Ada tempat kosong: Sabtu', (new WaitlistPromoted($activity))->toDatabase($user)['body']);
        });

        $this->withLocale('id', function () use ($user) {
            $mail = (new BillReminder(50, 2))->toMail($user);
            $this->assertSame('Pengingat: iuran tertunggak', $mail->subject);
            $this->assertContains('Anda memiliki tunggakan MYR 50.00 dari 2 tagihan.', $mail->introLines);
        });
    }

    public function test_translated_dates(): void
    {
        $this->assertSame('Jumaat', now()->locale('ms')->translatedFormat('l'));
        $this->assertSame('Jumat', now()->locale('id')->translatedFormat('l'));
    }
}
