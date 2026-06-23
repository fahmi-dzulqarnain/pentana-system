<?php

namespace Tests\Feature\Activity;

use App\Filament\Widgets\MyNextActivity;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyNextActivityWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function actAsMember(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('account'));

        return $user;
    }

    private function register(Activity $activity, User $user, string $status): void
    {
        ActivityRegistration::factory()->create([
            'activity_id' => $activity->id,
            'user_id' => $user->id,
            'status' => $status,
        ]);
    }

    public function test_shows_the_soonest_upcoming_registered_activity(): void
    {
        $user = $this->actAsMember();
        $soon = Activity::factory()->create(['status' => 'published', 'title' => 'Soon Trip', 'starts_at' => now()->addDay()]);
        $later = Activity::factory()->create(['status' => 'published', 'title' => 'Later Trip', 'starts_at' => now()->addWeek()]);
        $this->register($soon, $user, 'registered');
        $this->register($later, $user, 'registered');

        Livewire::test(MyNextActivity::class)
            ->assertSee('Soon Trip')
            ->assertDontSee('Later Trip');
    }

    public function test_ignores_waitlisted_cancelled_past_and_other_users(): void
    {
        $user = $this->actAsMember();
        $other = User::factory()->create();

        $waitlisted = Activity::factory()->create(['status' => 'published', 'title' => 'Waitlisted One', 'starts_at' => now()->addDay()]);
        $this->register($waitlisted, $user, 'waitlisted');

        $cancelled = Activity::factory()->create(['status' => 'published', 'title' => 'Cancelled One', 'starts_at' => now()->addDay()]);
        $this->register($cancelled, $user, 'cancelled');

        $past = Activity::factory()->create(['status' => 'published', 'title' => 'Past One', 'starts_at' => now()->subDay()]);
        $this->register($past, $user, 'registered');

        $othersOnly = Activity::factory()->create(['status' => 'published', 'title' => 'Others Only', 'starts_at' => now()->addDay()]);
        $this->register($othersOnly, $other, 'registered');

        Livewire::test(MyNextActivity::class)
            ->assertDontSee('Waitlisted One')
            ->assertDontSee('Cancelled One')
            ->assertDontSee('Past One')
            ->assertDontSee('Others Only');
    }

    public function test_empty_state_when_no_registered_activities(): void
    {
        $this->actAsMember();

        Livewire::test(MyNextActivity::class)->assertSee('no upcoming activities');
    }

    public function test_hidden_for_admins_visible_for_members(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->assertFalse(MyNextActivity::canView());

        $this->actingAs(User::factory()->create());
        $this->assertTrue(MyNextActivity::canView());
    }
}
