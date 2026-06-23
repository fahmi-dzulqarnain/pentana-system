<?php

namespace Tests\Feature\Activity;

use App\Filament\Pages\MyActivities;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyActivitiesPageTest extends TestCase
{
    use RefreshDatabase;

    private function actAsPanelUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        return $user;
    }

    private function published(array $overrides = []): Activity
    {
        return Activity::factory()->create(array_merge([
            'status' => 'published',
            'starts_at' => now()->addWeek(),
        ], $overrides));
    }

    public function test_any_logged_in_user_can_access(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertTrue(MyActivities::canAccess());
    }

    public function test_lists_upcoming_published_and_registers(): void
    {
        $activity = $this->published(['title' => 'Beach Cleanup', 'capacity' => 5]);
        $user = $this->actAsPanelUser();

        Livewire::test(MyActivities::class)
            ->assertSuccessful()
            ->assertSee('Beach Cleanup')
            ->call('register', $activity->id);

        $this->assertDatabaseHas('activity_registrations', [
            'activity_id' => $activity->id, 'user_id' => $user->id, 'status' => 'registered',
        ]);
    }

    public function test_registering_a_full_activity_waitlists(): void
    {
        $activity = $this->published(['capacity' => 1]);
        ActivityRegistration::factory()->create([
            'activity_id' => $activity->id, 'user_id' => User::factory()->create()->id, 'status' => 'registered',
        ]);
        $user = $this->actAsPanelUser();

        Livewire::test(MyActivities::class)->call('register', $activity->id);

        $this->assertDatabaseHas('activity_registrations', [
            'activity_id' => $activity->id, 'user_id' => $user->id, 'status' => 'waitlisted',
        ]);
    }

    public function test_cancel_marks_the_registration_cancelled(): void
    {
        $activity = $this->published(['capacity' => 5]);
        $user = $this->actAsPanelUser();
        ActivityRegistration::factory()->create([
            'activity_id' => $activity->id, 'user_id' => $user->id, 'status' => 'registered',
        ]);

        Livewire::test(MyActivities::class)->call('cancelRegistration', $activity->id);

        $this->assertDatabaseHas('activity_registrations', [
            'activity_id' => $activity->id, 'user_id' => $user->id, 'status' => 'cancelled',
        ]);
    }

    public function test_cannot_register_a_draft_activity(): void
    {
        $activity = Activity::factory()->create(['status' => 'draft', 'starts_at' => now()->addWeek()]);
        $user = $this->actAsPanelUser();

        Livewire::test(MyActivities::class)->call('register', $activity->id);

        $this->assertDatabaseMissing('activity_registrations', [
            'activity_id' => $activity->id, 'user_id' => $user->id,
        ]);
    }

    public function test_does_not_list_draft_or_past_activities(): void
    {
        $this->published(['title' => 'Upcoming Published']);
        Activity::factory()->create(['status' => 'draft', 'title' => 'Hidden Draft', 'starts_at' => now()->addWeek()]);
        Activity::factory()->create(['status' => 'published', 'title' => 'Old Activity', 'starts_at' => now()->subWeek()]);
        $this->actAsPanelUser();

        Livewire::test(MyActivities::class)
            ->assertSee('Upcoming Published')
            ->assertDontSee('Hidden Draft')
            ->assertDontSee('Old Activity');
    }

    public function test_past_registered_activity_appears_in_history(): void
    {
        $past = Activity::factory()->create(['status' => 'published', 'title' => 'Past Trip', 'starts_at' => now()->subWeek()]);
        $user = $this->actAsPanelUser();
        ActivityRegistration::factory()->create([
            'activity_id' => $past->id, 'user_id' => $user->id, 'status' => 'registered', 'attended' => true,
        ]);

        Livewire::test(MyActivities::class)->assertSee('Past Trip');
    }
}
