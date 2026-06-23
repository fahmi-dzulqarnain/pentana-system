<?php

namespace Tests\Feature\Activity;

use App\Models\Activity;
use App\Models\ActivityRegistration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityModelTest extends TestCase
{
    use RefreshDatabase;

    private function addRegistration(Activity $activity, string $status, bool $attended = false): ActivityRegistration
    {
        return ActivityRegistration::factory()->create([
            'activity_id' => $activity->id,
            'user_id' => User::factory()->create()->id,
            'status' => $status,
            'attended' => $attended,
        ]);
    }

    public function test_spots_left_is_null_when_capacity_is_unlimited(): void
    {
        $activity = Activity::factory()->create(['capacity' => null]);

        $this->assertNull($activity->spotsLeft());
    }

    public function test_spots_left_subtracts_registered_and_floors_at_zero(): void
    {
        $activity = Activity::factory()->create(['capacity' => 2]);

        $this->addRegistration($activity, 'registered');
        $this->assertSame(1, $activity->fresh()->spotsLeft());

        $this->addRegistration($activity, 'registered');
        $this->addRegistration($activity, 'registered'); // 3 registered vs capacity 2
        $this->assertSame(0, $activity->fresh()->spotsLeft());
    }

    public function test_is_full_only_counts_registered(): void
    {
        $activity = Activity::factory()->create(['capacity' => 1]);

        $this->addRegistration($activity, 'waitlisted');
        $this->addRegistration($activity, 'cancelled');
        $this->assertFalse($activity->fresh()->isFull());

        $this->addRegistration($activity, 'registered');
        $this->assertTrue($activity->fresh()->isFull());
    }

    public function test_is_full_is_false_when_capacity_unlimited(): void
    {
        $activity = Activity::factory()->create(['capacity' => null]);
        $this->addRegistration($activity, 'registered');

        $this->assertFalse($activity->fresh()->isFull());
    }

    public function test_is_open_for_registration_requires_published(): void
    {
        $draft = Activity::factory()->create(['status' => 'draft', 'starts_at' => now()->addWeek()]);
        $this->assertFalse($draft->isOpenForRegistration());

        $published = Activity::factory()->create(['status' => 'published', 'starts_at' => now()->addWeek()]);
        $this->assertTrue($published->isOpenForRegistration());
    }

    public function test_is_open_for_registration_closes_at_start_by_default(): void
    {
        $activity = Activity::factory()->create([
            'status' => 'published',
            'starts_at' => now()->subHour(),
            'registration_closes_at' => null,
        ]);

        $this->assertFalse($activity->isOpenForRegistration());
    }

    public function test_registration_closes_at_overrides_start(): void
    {
        $activity = Activity::factory()->create([
            'status' => 'published',
            'starts_at' => now()->addWeek(),
            'registration_closes_at' => now()->subHour(),
        ]);

        $this->assertFalse($activity->isOpenForRegistration());
        $this->assertTrue($activity->registrationClosesAt()->eq($activity->registration_closes_at));
    }

    public function test_is_past_flips_at_start(): void
    {
        $this->assertFalse(Activity::factory()->create(['starts_at' => now()->addHour()])->isPast());
        $this->assertTrue(Activity::factory()->create(['starts_at' => now()->subHour()])->isPast());
    }

    public function test_counts_only_their_statuses(): void
    {
        $activity = Activity::factory()->create();

        $this->addRegistration($activity, 'registered');
        $this->addRegistration($activity, 'registered', attended: true);
        $this->addRegistration($activity, 'waitlisted');
        $this->addRegistration($activity, 'cancelled');

        $activity->load('registrations');
        $this->assertSame(2, $activity->registeredCount());
        $this->assertSame(1, $activity->waitlistCount());
        $this->assertSame(1, $activity->attendedCount());
    }
}
