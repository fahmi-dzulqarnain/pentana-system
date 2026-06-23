<?php

namespace Tests\Feature\Activity;

use App\Models\Activity;
use App\Models\User;
use App\Notifications\ActivityCancelled;
use App\Notifications\ActivityPublished;
use App\Notifications\ActivityReminder;
use App\Notifications\WaitlistPromoted;
use App\Services\ActivityRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ActivityNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_promoting_off_the_waitlist_notifies_only_the_promoted_member(): void
    {
        Notification::fake();

        $activity = Activity::factory()->create([
            'status' => 'published',
            'capacity' => 1,
            'starts_at' => now()->addWeek(),
        ]);
        $service = app(ActivityRegistrationService::class);
        $service->register($activity, $a = User::factory()->create()); // registered
        $service->register($activity, $b = User::factory()->create()); // waitlisted

        $service->cancel($activity, $a); // frees the seat -> promotes B

        Notification::assertSentTo($b, WaitlistPromoted::class);
        Notification::assertNotSentTo($a, WaitlistPromoted::class);
    }

    public function test_no_one_is_notified_when_a_waitlisted_member_cancels(): void
    {
        Notification::fake();

        $activity = Activity::factory()->create([
            'status' => 'published',
            'capacity' => 1,
            'starts_at' => now()->addWeek(),
        ]);
        $service = app(ActivityRegistrationService::class);
        $service->register($activity, User::factory()->create()); // registered
        $service->register($activity, $b = User::factory()->create()); // waitlisted

        $service->cancel($activity, $b);

        Notification::assertNothingSent();
    }

    public function test_every_activity_notification_uses_the_bell_and_email(): void
    {
        $activity = Activity::factory()->create(['starts_at' => now()->addWeek()]);
        $user = User::factory()->create();

        foreach ([ActivityPublished::class, WaitlistPromoted::class, ActivityReminder::class, ActivityCancelled::class] as $class) {
            $notification = new $class($activity);

            $this->assertEqualsCanonicalizing(['database', 'mail'], $notification->via($user));
            $this->assertNotEmpty($notification->toDatabase($user)['title']);
        }
    }
}
