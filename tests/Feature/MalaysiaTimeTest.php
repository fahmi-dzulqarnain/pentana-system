<?php

namespace Tests\Feature;

use App\Models\Lunch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Admins type times as Malaysia wall-clock time. They must be stored, compared and sent
 * to the iOS app as Malaysia time, or deadlines drift by eight hours.
 */
class MalaysiaTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_10am_deadline_closes_at_10am_malaysia_time(): void
    {
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => '2026-10-16 10:00:00']);

        $this->travelTo(Carbon::parse('2026-10-16 09:59', 'Asia/Kuala_Lumpur'));
        $this->assertTrue($lunch->fresh()->isOpenForVoting());

        $this->travelTo(Carbon::parse('2026-10-16 10:01', 'Asia/Kuala_Lumpur'));
        $this->assertFalse($lunch->fresh()->isOpenForVoting());
    }

    public function test_the_app_receives_the_deadline_with_the_malaysia_offset(): void
    {
        $lunch = Lunch::factory()->create(['deadline' => '2026-10-16 10:00:00']);

        $this->assertSame('2026-10-16T10:00:00+08:00', $lunch->fresh()->deadline->toIso8601String());
    }
}
