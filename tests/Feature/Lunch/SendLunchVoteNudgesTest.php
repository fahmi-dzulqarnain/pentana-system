<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\User;
use App\Notifications\LunchVoteNudge;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SendLunchVoteNudgesTest extends TestCase
{
    use RefreshDatabase;

    public function test_nudges_non_responders_of_lunches_closing_within_a_day(): void
    {
        Notification::fake();
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addHours(12)]);

        $responder = User::factory()->create();
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $responder->id, 'meal_option_id' => null]);
        $nonResponder = User::factory()->create();

        $this->artisan('app:send-lunch-vote-nudges')->assertSuccessful();

        Notification::assertSentTo($nonResponder, LunchVoteNudge::class);
        Notification::assertNotSentTo($responder, LunchVoteNudge::class);
        $this->assertNotNull($lunch->fresh()->vote_nudge_sent_at);
    }

    public function test_does_not_nudge_twice(): void
    {
        Notification::fake();
        Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addHours(12)]);
        User::factory()->create();

        $this->artisan('app:send-lunch-vote-nudges')->assertSuccessful();
        $this->artisan('app:send-lunch-vote-nudges')->assertSuccessful();

        Notification::assertSentTimes(LunchVoteNudge::class, 1);
    }

    public function test_ignores_draft_closed_far_future_and_past_deadline_lunches(): void
    {
        Notification::fake();
        User::factory()->create();
        Lunch::factory()->create(['status' => 'draft', 'deadline' => now()->addHours(6)]);
        Lunch::factory()->create(['status' => 'closed', 'deadline' => now()->addHours(6)]);
        Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addWeek()]);
        Lunch::factory()->create(['status' => 'published', 'deadline' => now()->subHour()]);

        $this->artisan('app:send-lunch-vote-nudges')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_a_failed_send_does_not_abort_the_rest_of_the_run(): void
    {
        Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addHours(12)]);
        $failing = User::factory()->create();
        $succeeding = User::factory()->create();

        // User::notify() resolves the Dispatcher contract from the container, so a
        // bound mock lets us make one send blow up (e.g. a bad APNs token) while
        // recording who else still got notified.
        $notified = [];
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('send')
            ->andReturnUsing(function (User $notifiable, LunchVoteNudge $notification) use ($failing, &$notified) {
                if ($notifiable->is($failing)) {
                    throw new RuntimeException('APNs: bad device token');
                }
                $notified[] = $notifiable->id;
            });
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->artisan('app:send-lunch-vote-nudges')->assertSuccessful();

        $this->assertSame([$succeeding->id], $notified);
    }
}
