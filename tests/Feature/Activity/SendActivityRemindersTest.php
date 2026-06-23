<?php

namespace Tests\Feature\Activity;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use App\Notifications\ActivityReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendActivityRemindersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Activity, 1: User} */
    private function activityWithRegistrant(array $overrides, string $status = 'registered'): array
    {
        $activity = Activity::factory()->create(array_merge(['status' => 'published'], $overrides));
        $user = User::factory()->create();
        ActivityRegistration::factory()->create([
            'activity_id' => $activity->id, 'user_id' => $user->id, 'status' => $status,
        ]);

        return [$activity, $user];
    }

    public function test_reminds_registered_members_of_activities_within_a_day(): void
    {
        Notification::fake();
        [$activity, $registered] = $this->activityWithRegistrant(['starts_at' => now()->addHours(12)]);

        $waitlisted = User::factory()->create();
        ActivityRegistration::factory()->create([
            'activity_id' => $activity->id, 'user_id' => $waitlisted->id, 'status' => 'waitlisted',
        ]);

        $this->artisan('app:send-activity-reminders')->assertSuccessful();

        Notification::assertSentTo($registered, ActivityReminder::class);
        Notification::assertNotSentTo($waitlisted, ActivityReminder::class);
        $this->assertNotNull($activity->fresh()->reminder_sent_at);
    }

    public function test_does_not_remind_twice(): void
    {
        Notification::fake();
        $this->activityWithRegistrant(['starts_at' => now()->addHours(12)]);

        $this->artisan('app:send-activity-reminders')->assertSuccessful();
        $this->artisan('app:send-activity-reminders')->assertSuccessful();

        Notification::assertSentTimes(ActivityReminder::class, 1);
    }

    public function test_ignores_draft_far_future_and_already_reminded(): void
    {
        Notification::fake();
        $this->activityWithRegistrant(['status' => 'draft', 'starts_at' => now()->addHours(6)]);
        $this->activityWithRegistrant(['starts_at' => now()->addWeek()]);
        $this->activityWithRegistrant(['starts_at' => now()->addHours(6), 'reminder_sent_at' => now()->subHour()]);

        $this->artisan('app:send-activity-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
