<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchesPublished;
use App\Notifications\LunchPublished;
use App\Services\LunchPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AnnounceLunchesTest extends TestCase
{
    use RefreshDatabase;

    private function draft(int $daysAhead): Lunch
    {
        return Lunch::factory()->create([
            'status' => 'draft',
            'date' => today()->addDays($daysAhead)->toDateString(),
            'deadline' => now()->addDays(2),
        ]);
    }

    private function publish(Lunch $lunch): void
    {
        app(LunchPublisher::class)->publish(collect([$lunch]));
    }

    public function test_days_published_one_at_a_time_are_announced_once_together(): void
    {
        Notification::fake();
        $member = User::factory()->create();
        $days = collect([5, 6, 7])->map(fn (int $d) => $this->draft($d));

        foreach ($days as $day) {
            $this->publish($day);
            $this->travel(3)->minutes();
            $this->artisan('app:announce-lunches')->assertSuccessful();
        }

        Notification::assertNothingSent(); // still inside the quiet window after the last publish

        $this->travel(LunchPublisher::QUIET_MINUTES)->minutes();
        $this->artisan('app:announce-lunches')->assertSuccessful();
        $this->artisan('app:announce-lunches')->assertSuccessful();

        Notification::assertSentToTimes($member, LunchesPublished::class, 1);
        Notification::assertNotSentTo($member, LunchPublished::class);
        Notification::assertSentTo($member, LunchesPublished::class,
            fn (LunchesPublished $n) => $n->lunches->pluck('id')->all() === $days->pluck('id')->all());
        $this->assertSame(0, Lunch::whereNull('announced_at')->count());
    }

    public function test_a_day_whose_deadline_passed_before_the_announcement_is_stamped_silently(): void
    {
        Notification::fake();
        User::factory()->create();
        $lunch = $this->draft(1);
        $lunch->update(['deadline' => now()->addMinutes(5)]);
        $this->publish($lunch);

        $this->travel(LunchPublisher::QUIET_MINUTES)->minutes();
        $this->artisan('app:announce-lunches')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNotNull($lunch->fresh()->announced_at);
    }

    public function test_lunches_that_never_went_through_publishing_are_not_announced(): void
    {
        Notification::fake();
        User::factory()->create();
        Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);

        $this->travel(LunchPublisher::QUIET_MINUTES)->minutes();
        $this->artisan('app:announce-lunches')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
