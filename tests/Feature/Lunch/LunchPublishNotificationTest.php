<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\Pages\EditLunch;
use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchPublished;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class LunchPublishNotificationTest extends TestCase
{
    use RefreshDatabase;

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
}
