<?php

namespace Tests\Feature\Activity;

use App\Filament\Pages\MyActivities;
use App\Filament\Resources\Activities\Pages\CreateActivity;
use App\Filament\Resources\Activities\Pages\EditActivity;
use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use App\Notifications\ActivityDateConfirmed;
use App\Notifications\ActivityPublished;
use App\Notifications\ActivityReminder;
use App\Services\ActivityRegistrationService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class ComingSoonActivityTest extends TestCase
{
    use RefreshDatabase;

    private function comingSoon(array $overrides = []): Activity
    {
        return Activity::factory()->create(array_merge([
            'status' => 'published',
            'starts_at' => null,
            'timeframe' => 'December 2026',
        ], $overrides));
    }

    private function registration(Activity $activity, string $status): ActivityRegistration
    {
        return ActivityRegistration::factory()->create([
            'activity_id' => $activity->id,
            'user_id' => User::factory()->create()->id,
            'status' => $status,
        ]);
    }

    private function actAsAdmin(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    public function test_admin_can_create_an_activity_with_only_a_timeframe(): void
    {
        $this->actAsAdmin();

        Livewire::test(CreateActivity::class)
            ->fillForm(['title' => 'Family Day', 'starts_at' => null, 'timeframe' => 'December 2026'])
            ->call('create')
            ->assertHasNoFormErrors();

        $activity = Activity::firstWhere('title', 'Family Day');
        $this->assertNull($activity->starts_at);
        $this->assertSame('December 2026', $activity->timeframe);
    }

    public function test_an_activity_needs_a_start_date_or_a_timeframe(): void
    {
        $this->actAsAdmin();

        Livewire::test(CreateActivity::class)
            ->fillForm(['title' => 'Family Day', 'starts_at' => null, 'timeframe' => null])
            ->call('create')
            ->assertHasFormErrors(['timeframe' => 'required']);
    }

    public function test_a_coming_soon_activity_is_open_and_never_past(): void
    {
        $activity = $this->comingSoon();

        $this->assertTrue($activity->isComingSoon());
        $this->assertFalse($activity->isPast());
        $this->assertNull($activity->registrationClosesAt());
        $this->assertTrue($activity->isOpenForRegistration());
        $this->assertSame('Coming soon · December 2026', $activity->whenLabel());
    }

    public function test_an_explicit_deadline_still_closes_a_coming_soon_activity(): void
    {
        $activity = $this->comingSoon(['registration_closes_at' => now()->subDay()]);

        $this->assertFalse($activity->isOpenForRegistration());
    }

    public function test_members_can_register_before_the_date_is_known(): void
    {
        $activity = $this->comingSoon(['capacity' => 1]);

        $first = app(ActivityRegistrationService::class)->register($activity, User::factory()->create());
        $second = app(ActivityRegistrationService::class)->register($activity->fresh(), User::factory()->create());

        $this->assertSame('registered', $first->status);
        $this->assertSame('waitlisted', $second->status);
    }

    public function test_upcoming_lists_dated_activities_first_then_coming_soon(): void
    {
        $later = Activity::factory()->create(['starts_at' => now()->addMonth()]);
        $soon = $this->comingSoon();
        $sooner = Activity::factory()->create(['starts_at' => now()->addDay()]);
        Activity::factory()->create(['starts_at' => now()->subDay()]);

        $this->assertSame([$sooner->id, $later->id, $soon->id], Activity::upcoming()->pluck('id')->all());
    }

    public function test_api_returns_coming_soon_activities_with_a_null_start_and_the_timeframe(): void
    {
        $activity = $this->comingSoon();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/activities')
            ->assertOk()
            ->assertJsonPath('data.0.id', $activity->id)
            ->assertJsonPath('data.0.starts_at', null)
            ->assertJsonPath('data.0.timeframe', 'December 2026')
            ->assertJsonPath('data.0.is_open', true);
    }

    public function test_my_activities_shows_the_timeframe(): void
    {
        $this->comingSoon(['title' => 'Family Day']);
        $this->actingAs(User::factory()->create());
        Filament::setCurrentPanel(Filament::getPanel('account'));

        Livewire::test(MyActivities::class)
            ->assertSee('Family Day')
            ->assertSee('Coming soon · December 2026');
    }

    public function test_setting_the_date_notifies_everyone_signed_up(): void
    {
        $this->actAsAdmin();
        Notification::fake();
        $this->withoutDefer();
        $activity = $this->comingSoon();
        $registered = $this->registration($activity, 'registered');
        $waitlisted = $this->registration($activity, 'waitlisted');
        $cancelled = $this->registration($activity, 'cancelled');

        Livewire::test(EditActivity::class, ['record' => $activity->getKey()])
            ->fillForm(['starts_at' => now()->addMonth()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertNotNull($activity->fresh()->starts_at);
        Notification::assertSentTo($registered->user, ActivityDateConfirmed::class);
        Notification::assertSentTo($waitlisted->user, ActivityDateConfirmed::class);
        Notification::assertNotSentTo($cancelled->user, ActivityDateConfirmed::class);
    }

    public function test_setting_the_date_on_a_draft_notifies_nobody(): void
    {
        $this->actAsAdmin();
        Notification::fake();
        $this->withoutDefer();
        $activity = $this->comingSoon(['status' => 'draft']);
        $this->registration($activity, 'registered');

        Livewire::test(EditActivity::class, ['record' => $activity->getKey()])
            ->fillForm(['starts_at' => now()->addMonth()])
            ->call('save')
            ->assertHasNoFormErrors();

        Notification::assertNothingSent();
    }

    public function test_moving_an_already_scheduled_date_does_not_send_a_confirmation(): void
    {
        $this->actAsAdmin();
        Notification::fake();
        $this->withoutDefer();
        $activity = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek()]);
        $this->registration($activity, 'registered');

        Livewire::test(EditActivity::class, ['record' => $activity->getKey()])
            ->fillForm(['starts_at' => now()->addWeeks(2)])
            ->call('save')
            ->assertHasNoFormErrors();

        Notification::assertNothingSent();
    }

    public function test_reminders_skip_coming_soon_activities(): void
    {
        Notification::fake();
        $activity = $this->comingSoon();
        $this->registration($activity, 'registered');

        $this->artisan('app:send-activity-reminders')->assertSuccessful();

        Notification::assertNotSentTo($activity->registrations->first()->user, ActivityReminder::class);
        $this->assertNull($activity->fresh()->reminder_sent_at);
    }

    public function test_the_announcement_email_names_the_timeframe(): void
    {
        $activity = $this->comingSoon(['title' => 'Family Day']);

        $html = (string) (new ActivityPublished($activity))->toMail(User::factory()->create())->render();

        $this->assertStringContainsString('Family Day is coming soon (December 2026)', $html);
    }
}
