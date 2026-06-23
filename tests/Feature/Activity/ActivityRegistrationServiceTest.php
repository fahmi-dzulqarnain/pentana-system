<?php

namespace Tests\Feature\Activity;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use App\Services\ActivityRegistrationService;
use App\Services\RegistrationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActivityRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ActivityRegistrationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ActivityRegistrationService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function published(array $attributes = []): Activity
    {
        return Activity::factory()->create(array_merge([
            'status' => 'published',
            'starts_at' => now()->addWeek(),
        ], $attributes));
    }

    private function statusOf(Activity $activity, User $user): ?string
    {
        return ActivityRegistration::where('activity_id', $activity->id)
            ->where('user_id', $user->id)
            ->value('status');
    }

    public function test_registers_into_an_open_activity_with_free_seats(): void
    {
        $activity = $this->published(['capacity' => 5]);

        $reg = $this->service->register($activity, User::factory()->create());

        $this->assertSame('registered', $reg->status);
        $this->assertNotNull($reg->registered_at);
    }

    public function test_overflow_registrations_are_waitlisted(): void
    {
        $activity = $this->published(['capacity' => 1]);

        $this->service->register($activity, $a = User::factory()->create());
        $this->service->register($activity, $b = User::factory()->create());

        $this->assertSame('registered', $this->statusOf($activity, $a));
        $this->assertSame('waitlisted', $this->statusOf($activity, $b));
    }

    public function test_unlimited_capacity_never_waitlists(): void
    {
        $activity = $this->published(['capacity' => null]);

        $this->service->register($activity, $a = User::factory()->create());
        $this->service->register($activity, $b = User::factory()->create());

        $this->assertSame('registered', $this->statusOf($activity, $a));
        $this->assertSame('registered', $this->statusOf($activity, $b));
    }

    public function test_cancelling_a_registered_member_promotes_the_earliest_waitlisted(): void
    {
        Carbon::setTestNow('2026-07-01 09:00:00');
        $activity = $this->published(['capacity' => 1]);
        $this->service->register($activity, $a = User::factory()->create());
        Carbon::setTestNow('2026-07-01 09:01:00');
        $this->service->register($activity, $b = User::factory()->create());
        Carbon::setTestNow('2026-07-01 09:02:00');
        $this->service->register($activity, $c = User::factory()->create());

        Carbon::setTestNow('2026-07-01 09:03:00');
        $this->service->cancel($activity, $a);

        $this->assertSame('cancelled', $this->statusOf($activity, $a));
        $this->assertSame('registered', $this->statusOf($activity, $b)); // earliest waitlisted promoted
        $this->assertSame('waitlisted', $this->statusOf($activity, $c));
    }

    public function test_cancelling_a_waitlisted_member_does_not_promote(): void
    {
        $activity = $this->published(['capacity' => 1]);
        $this->service->register($activity, $a = User::factory()->create()); // registered
        $this->service->register($activity, $b = User::factory()->create()); // waitlisted
        $this->service->register($activity, $c = User::factory()->create()); // waitlisted

        $this->service->cancel($activity, $b);

        $this->assertSame('registered', $this->statusOf($activity, $a)); // unchanged
        $this->assertSame('cancelled', $this->statusOf($activity, $b));
        $this->assertSame('waitlisted', $this->statusOf($activity, $c)); // not promoted
    }

    public function test_register_is_blocked_when_draft(): void
    {
        $activity = Activity::factory()->create(['status' => 'draft', 'starts_at' => now()->addWeek()]);
        $user = User::factory()->create();

        try {
            $this->service->register($activity, $user);
            $this->fail('Expected RegistrationException');
        } catch (RegistrationException) {
            // expected
        }

        $this->assertNull($this->statusOf($activity, $user));
    }

    public function test_register_is_blocked_when_past(): void
    {
        $activity = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->subDay()]);
        $user = User::factory()->create();

        try {
            $this->service->register($activity, $user);
            $this->fail('Expected RegistrationException');
        } catch (RegistrationException) {
            // expected
        }

        $this->assertNull($this->statusOf($activity, $user));
    }

    public function test_double_register_is_idempotent(): void
    {
        $activity = $this->published(['capacity' => 5]);
        $user = User::factory()->create();

        $this->service->register($activity, $user);
        $this->service->register($activity, $user);

        $this->assertSame(1, ActivityRegistration::where('activity_id', $activity->id)
            ->where('user_id', $user->id)->count());
        $this->assertSame('registered', $this->statusOf($activity, $user));
    }

    public function test_double_cancel_is_idempotent(): void
    {
        $activity = $this->published(['capacity' => 5]);
        $user = User::factory()->create();
        $this->service->register($activity, $user);

        $this->service->cancel($activity, $user);
        $this->service->cancel($activity, $user);

        $this->assertSame('cancelled', $this->statusOf($activity, $user));
    }

    public function test_re_register_after_cancel_goes_to_back_of_the_queue(): void
    {
        Carbon::setTestNow('2026-07-01 09:00:00');
        $activity = $this->published(['capacity' => 1]);
        $this->service->register($activity, $a = User::factory()->create()); // A registered
        Carbon::setTestNow('2026-07-01 09:01:00');
        $this->service->register($activity, $b = User::factory()->create()); // B waitlisted

        Carbon::setTestNow('2026-07-01 09:02:00');
        $this->service->cancel($activity, $a); // B promoted
        $this->assertSame('registered', $this->statusOf($activity, $b));

        Carbon::setTestNow('2026-07-01 09:03:00');
        $reg = $this->service->register($activity, $a); // A re-registers -> back of queue (waitlisted)

        $this->assertSame('waitlisted', $reg->status);
        $this->assertSame('waitlisted', $this->statusOf($activity, $a));
    }

    public function test_reconcile_waitlist_promotes_when_capacity_increases(): void
    {
        $activity = $this->published(['capacity' => 1]);
        $this->service->register($activity, $a = User::factory()->create()); // registered
        $this->service->register($activity, $b = User::factory()->create()); // waitlisted
        $this->service->register($activity, $c = User::factory()->create()); // waitlisted

        $activity->update(['capacity' => 3]);
        $this->service->reconcileWaitlist($activity->fresh());

        $this->assertSame('registered', $this->statusOf($activity, $a));
        $this->assertSame('registered', $this->statusOf($activity, $b));
        $this->assertSame('registered', $this->statusOf($activity, $c));
    }
}
