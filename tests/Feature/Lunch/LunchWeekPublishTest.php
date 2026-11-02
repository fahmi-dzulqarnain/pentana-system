<?php

namespace Tests\Feature\Lunch;

use App\Filament\Resources\Lunches\Pages\ListLunches;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\User;
use App\Notifications\LunchesPublished;
use App\Notifications\LunchPublished;
use App\Services\LunchPublisher;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class LunchWeekPublishTest extends TestCase
{
    use RefreshDatabase;

    /** Publishing only stamps the day; members hear about it once the burst has gone quiet. */
    private function announceAfterQuietPeriod(): void
    {
        $this->travel(LunchPublisher::QUIET_MINUTES)->minutes();
        $this->artisan('app:announce-lunches')->assertSuccessful();
    }

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('account'));
    }

    private function draftOn(string $date, ?string $meal = null): Lunch
    {
        $factory = $meal ? Lunch::factory()->withMenu(CatererMeal::factory()->create(['name' => $meal])) : Lunch::factory();

        return $factory->create(['status' => 'draft', 'date' => $date, 'deadline' => Carbon::parse($date)->setTime(10, 0)]);
    }

    public function test_publishing_a_week_sends_each_member_one_notification_for_every_day(): void
    {
        Notification::fake();
        $this->withoutDefer();
        $member = User::factory()->create();

        $week = collect(['2026-11-06', '2026-11-02', '2026-11-04', '2026-11-03', '2026-11-05'])
            ->map(fn ($date) => $this->draftOn($date));

        Livewire::test(ListLunches::class)->callTableBulkAction('publish', $week)->assertNotified();

        $this->announceAfterQuietPeriod();

        $week->each(fn (Lunch $lunch) => $this->assertSame('published', $lunch->fresh()->status));

        Notification::assertNotSentTo($member, LunchPublished::class);
        Notification::assertSentToTimes($member, LunchesPublished::class, 1);
        Notification::assertSentTimes(LunchesPublished::class, 2); // admin + member

        Notification::assertSentTo($member, LunchesPublished::class, function (LunchesPublished $n) {
            return $n->lunches->map(fn (Lunch $l) => $l->date->toDateString())->all()
                === ['2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'];
        });
    }

    public function test_only_drafts_in_the_selection_are_published_and_announced(): void
    {
        Notification::fake();
        $this->withoutDefer();

        $drafts = collect([$this->draftOn('2026-11-02'), $this->draftOn('2026-11-03')]);
        $published = Lunch::factory()->create(['status' => 'published', 'date' => '2026-11-04', 'deadline' => now()->addDay()]);
        $closed = Lunch::factory()->create(['status' => 'closed', 'date' => '2026-11-05', 'deadline' => now()->subDay()]);

        Livewire::test(ListLunches::class)->callTableBulkAction('publish', [...$drafts, $published, $closed]);

        $this->announceAfterQuietPeriod();

        $this->assertSame('closed', $closed->fresh()->status);
        Notification::assertSentTo($this->admin, LunchesPublished::class, fn (LunchesPublished $n) => $n->lunches->pluck('id')->all() === $drafts->pluck('id')->all());
    }

    public function test_publishing_a_single_draft_from_the_list_sends_the_one_day_notification(): void
    {
        Notification::fake();
        $this->withoutDefer();

        $lunch = $this->draftOn('2026-11-02');

        Livewire::test(ListLunches::class)->callTableBulkAction('publish', [$lunch]);

        $this->announceAfterQuietPeriod();

        Notification::assertSentToTimes($this->admin, LunchPublished::class, 1);
        Notification::assertNotSentTo($this->admin, LunchesPublished::class);
    }

    public function test_a_selection_without_drafts_publishes_and_sends_nothing(): void
    {
        Notification::fake();
        $this->withoutDefer();

        $published = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);

        Livewire::test(ListLunches::class)->callTableBulkAction('publish', [$published]);

        $this->announceAfterQuietPeriod();

        Notification::assertNothingSent();
    }

    public function test_the_week_notification_names_each_day_and_its_menus(): void
    {
        Mail::fake();
        $member = User::factory()->create();
        $lunches = collect([
            $this->draftOn('2026-11-02', 'Nasi Lemak Special'),
            $this->draftOn('2026-11-03', 'Mee Goreng Mamak'),
        ]);

        $notification = new LunchesPublished($lunches);

        $database = $notification->toDatabase($member);
        $this->assertStringContainsString('2 days', $database['title']);

        $mail = $notification->toMail($member);
        $lines = implode("\n", $mail->introLines);
        $this->assertStringContainsString('Mon, 2 Nov', $lines);
        $this->assertStringContainsString('Nasi Lemak Special', $lines);
        $this->assertStringContainsString('Tue, 3 Nov', $lines);
        $this->assertStringContainsString('Mee Goreng Mamak', $lines);

        $member->notify($notification);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $member->id, 'type' => LunchesPublished::class]);
    }
}
