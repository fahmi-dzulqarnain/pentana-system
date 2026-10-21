<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\User;
use App\Notifications\LunchVoteNudge;
use App\Services\LunchReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class ManualLunchReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
        Notification::fake();
    }

    private function openLunch(): Lunch
    {
        return Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addHours(30)]);
    }

    public function test_reminds_only_enabled_non_responders_and_stamps(): void
    {
        $lunch = $this->openLunch();
        $admin = User::factory()->create(['is_admin' => true]);
        $admin->forceFill(['disabled_at' => now()])->save();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $responder = User::factory()->create();
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $responder->id, 'meal_option_id' => null]);
        $disabled = User::factory()->create();
        $disabled->forceFill(['disabled_at' => now()])->save();

        $count = app(LunchReminderService::class)->remindNonResponders($lunch, $admin);

        $this->assertSame(2, $count);
        Notification::assertSentTo($a, LunchVoteNudge::class);
        Notification::assertSentTo($b, LunchVoteNudge::class);
        Notification::assertNotSentTo($responder, LunchVoteNudge::class);
        Notification::assertNotSentTo($disabled, LunchVoteNudge::class);
        Notification::assertSentTimes(LunchVoteNudge::class, 2);
        $fresh = $lunch->fresh();
        $this->assertNotNull($fresh->manual_reminder_sent_at);
        $this->assertSame($admin->id, $fresh->manual_reminder_sent_by);
        $this->assertTrue($fresh->manualReminderSender->is($admin));
    }

    public function test_does_not_touch_the_automatic_nudge_stamp(): void
    {
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addHours(12)]);
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();

        app(LunchReminderService::class)->remindNonResponders($lunch, $admin);

        $this->assertNull($lunch->fresh()->vote_nudge_sent_at);

        $this->artisan('app:send-lunch-vote-nudges')->assertSuccessful();

        $this->assertNotNull($lunch->fresh()->vote_nudge_sent_at);
        Notification::assertSentToTimes($member, LunchVoteNudge::class, 2);
    }

    public function test_can_be_sent_repeatedly(): void
    {
        $lunch = $this->openLunch();
        $first = User::factory()->create(['is_admin' => true]);
        $second = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create();

        $this->travelTo(now()->subMinutes(10));
        app(LunchReminderService::class)->remindNonResponders($lunch, $first);
        $firstStamp = $lunch->fresh()->manual_reminder_sent_at;
        $this->travelBack();

        app(LunchReminderService::class)->remindNonResponders($lunch->fresh(), $second);

        Notification::assertSentToTimes($member, LunchVoteNudge::class, 2);
        $fresh = $lunch->fresh();
        $this->assertTrue($fresh->manual_reminder_sent_at->gt($firstStamp));
        $this->assertSame($second->id, $fresh->manual_reminder_sent_by);
    }

    public function test_returns_zero_without_stamping_when_everyone_responded(): void
    {
        $lunch = $this->openLunch();
        $admin = User::factory()->create(['is_admin' => true]);
        LunchResponse::create(['lunch_id' => $lunch->id, 'user_id' => $admin->id, 'meal_option_id' => null]);

        $this->assertSame(0, app(LunchReminderService::class)->remindNonResponders($lunch, $admin));

        Notification::assertNothingSent();
        $this->assertNull($lunch->fresh()->manual_reminder_sent_at);
    }

    public function test_refused_after_deadline_or_when_not_published(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->create();

        foreach ([
            ['status' => 'published', 'deadline' => now()->subMinute()],
            ['status' => 'draft', 'deadline' => now()->addDay()],
            ['status' => 'closed', 'deadline' => now()->addDay()],
        ] as $attrs) {
            $lunch = Lunch::factory()->create($attrs);

            try {
                app(LunchReminderService::class)->remindNonResponders($lunch, $admin);
                $this->fail('Expected a RuntimeException for '.$attrs['status']);
            } catch (RuntimeException) {
                $this->assertNull($lunch->fresh()->manual_reminder_sent_at);
            }
        }

        Notification::assertNothingSent();
    }
}
