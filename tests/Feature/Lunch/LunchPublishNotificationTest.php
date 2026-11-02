<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\Pages\EditLunch;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchPublished;
use App\Services\LunchPublisher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class LunchPublishNotificationTest extends TestCase
{
    use RefreshDatabase;

    /** Publishing only stamps the day; members hear about it once the burst has gone quiet. */
    private function announceAfterQuietPeriod(): void
    {
        $this->travel(LunchPublisher::QUIET_MINUTES)->minutes();
        $this->artisan('app:announce-lunches')->assertSuccessful();
    }

    public function test_publishing_notifies_every_user_on_both_channels(): void
    {
        Notification::fake();
        $this->withoutDefer(); // delivery runs after the response; run it inline here

        $admin = User::factory()->admin()->create();
        User::factory()->count(3)->create();
        $lunch = Lunch::factory()->create(['status' => 'draft', 'deadline' => now()->addDay()]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getRouteKey()])
            ->callAction('publish');

        $this->announceAfterQuietPeriod();

        $this->assertSame('published', $lunch->fresh()->status);

        // 4 users total (admin + 3) each notified.
        Notification::assertSentTimes(LunchPublished::class, 4);
        Notification::assertSentTo($admin, LunchPublished::class, function (LunchPublished $n, array $channels) {
            return in_array('database', $channels, true) && in_array('mail', $channels, true);
        });
    }

    public function test_publishing_hands_delivery_off_instead_of_sending_inside_the_request(): void
    {
        Notification::fake();

        $admin = User::factory()->admin()->create();
        $lunch = Lunch::factory()->create(['status' => 'draft', 'deadline' => now()->addDay()]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getRouteKey()])
            ->callAction('publish')
            ->assertNotified();

        $this->assertSame('published', $lunch->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_publish_action_is_hidden_once_published(): void
    {
        $admin = User::factory()->admin()->create();
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getRouteKey()])
            ->assertActionHidden('publish');
    }

    public function test_the_notification_builds_and_persists_a_database_record_when_sent(): void
    {
        // No Notification::fake() - exercise the real toDatabase()/toMail() build paths
        // (Filament getDatabaseMessage + the Action url, and MyLunch::getUrl in the mail).
        Mail::fake();
        $user = User::factory()->create();
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);

        $user->notify(new LunchPublished($lunch));

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            'type' => LunchPublished::class,
        ]);
    }

    public function test_publishing_a_two_menu_day_sends_one_notification_naming_both_menus(): void
    {
        Notification::fake();
        $this->withoutDefer();

        $admin = User::factory()->admin()->create();
        $member = User::factory()->create();
        $lunch = Lunch::factory()
            ->withMenu(CatererMeal::factory()->create(['name' => 'Nasi Lemak Special']))
            ->withMenu(CatererMeal::factory()->create(['name' => 'Mee Goreng Mamak']))
            ->create(['status' => 'draft', 'deadline' => now()->addDay()]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getRouteKey()])->callAction('publish');
        $this->announceAfterQuietPeriod();

        Notification::assertSentToTimes($admin, LunchPublished::class, 1);
        Notification::assertSentToTimes($member, LunchPublished::class, 1);
        Notification::assertSentTimes(LunchPublished::class, 2);

        Notification::assertSentTo($member, LunchPublished::class, function (LunchPublished $n) use ($member) {
            $title = $n->toDatabase($member)['title'];
            $mail = $n->toMail($member);

            return str_contains($title, 'Nasi Lemak Special') && str_contains($title, 'Mee Goreng Mamak')
                && str_contains($mail->introLines[0], 'Nasi Lemak Special')
                && str_contains($mail->introLines[0], 'Mee Goreng Mamak');
        });
    }

    public function test_the_notification_names_menus_of_a_freshly_loaded_lunch_and_falls_back_without_menus(): void
    {
        $user = User::factory()->create();
        $lunch = Lunch::factory()
            ->withMenu(CatererMeal::factory()->create(['name' => 'Roti Canai']))
            ->withMenu(CatererMeal::factory()->create(['name' => 'Laksa Utara']))
            ->create(['status' => 'published', 'deadline' => now()->addDay()]);

        $title = (new LunchPublished(Lunch::find($lunch->id)))->toDatabase($user)['title'];
        $this->assertStringContainsString('Roti Canai · Laksa Utara', $title);

        $empty = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);
        $this->assertStringContainsString('Menu', (new LunchPublished($empty))->toDatabase($user)['title']);
    }
}
