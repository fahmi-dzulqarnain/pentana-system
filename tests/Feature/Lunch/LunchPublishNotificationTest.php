<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\Pages\EditLunch;
use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchPublished;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class LunchPublishNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishing_notifies_every_user_on_both_channels(): void
    {
        Notification::fake();

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

    public function test_publish_action_is_hidden_once_published(): void
    {
        $admin = User::factory()->admin()->create();
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(EditLunch::class, ['record' => $lunch->getRouteKey()])
            ->assertActionHidden('publish');
    }
}
