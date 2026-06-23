<?php

namespace Tests\Feature\Activity;

use App\Filament\Resources\Activities\Pages\EditActivity;
use App\Filament\Resources\Activities\RelationManagers\RegistrationsRelationManager;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use App\Notifications\ActivityCancelled;
use App\Notifications\ActivityPublished;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ActivityAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    private function registration(Activity $activity, string $status): ActivityRegistration
    {
        return ActivityRegistration::factory()->create([
            'activity_id' => $activity->id,
            'user_id' => User::factory()->create()->id,
            'status' => $status,
        ]);
    }

    public function test_publish_sets_status_and_notifies_only_activated_members(): void
    {
        Notification::fake();
        $activated = User::factory()->count(2)->create(['activated_at' => now()]);
        $unactivated = User::factory()->create(['activated_at' => null]);
        $activity = Activity::factory()->create(['status' => 'draft', 'starts_at' => now()->addWeek()]);

        Livewire::test(EditActivity::class, ['record' => $activity->getKey()])
            ->callAction('publish')
            ->assertHasNoErrors();

        $this->assertSame('published', $activity->fresh()->status);
        Notification::assertSentTo($activated[0], ActivityPublished::class);
        Notification::assertSentTo($activated[1], ActivityPublished::class);
        Notification::assertNotSentTo($unactivated, ActivityPublished::class);
    }

    public function test_publish_is_hidden_for_past_or_already_published(): void
    {
        $past = Activity::factory()->create(['status' => 'draft', 'starts_at' => now()->subDay()]);
        Livewire::test(EditActivity::class, ['record' => $past->getKey()])
            ->assertActionHidden('publish');

        $published = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek()]);
        Livewire::test(EditActivity::class, ['record' => $published->getKey()])
            ->assertActionHidden('publish');
    }

    public function test_cancel_sets_status_and_notifies_active_registrants(): void
    {
        Notification::fake();
        $activity = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek(), 'capacity' => 5]);
        $registered = $this->registration($activity, 'registered');
        $waitlisted = $this->registration($activity, 'waitlisted');
        $alreadyCancelled = $this->registration($activity, 'cancelled');

        Livewire::test(EditActivity::class, ['record' => $activity->getKey()])
            ->callAction('cancelActivity')
            ->assertHasNoErrors();

        $this->assertSame('cancelled', $activity->fresh()->status);
        Notification::assertSentTo($registered->user, ActivityCancelled::class);
        Notification::assertSentTo($waitlisted->user, ActivityCancelled::class);
        Notification::assertNotSentTo($alreadyCancelled->user, ActivityCancelled::class);
    }

    public function test_registrations_panel_lists_registrants_with_an_attended_column(): void
    {
        $activity = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek()]);
        $registration = $this->registration($activity, 'registered');

        Livewire::test(RegistrationsRelationManager::class, [
            'ownerRecord' => $activity,
            'pageClass' => EditActivity::class,
        ])
            ->assertSuccessful()
            ->assertTableColumnExists('attended')
            ->assertCanSeeTableRecords([$registration]);
    }
}
