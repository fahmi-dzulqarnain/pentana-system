<?php

namespace Tests\Feature\Lunch;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use App\Notifications\LunchPickSetByAdmin;
use App\Services\LunchVotingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class AdminSetPickTest extends TestCase
{
    use RefreshDatabase;

    private MealOption $chicken;

    private MealOption $fish;

    private function lunch(string $status = 'published', ?string $date = null): Lunch
    {
        static $n = 0;
        $date ??= Carbon::parse('2026-11-02')->addDays($n++)->toDateString();

        $this->chicken ??= MealOption::factory()->create(['name' => 'Chicken']);
        $this->fish ??= MealOption::factory()->create(['name' => 'Fish']);

        $meal = CatererMeal::factory()
            ->for(Caterer::factory()->create(['name' => 'Kak Ros']))
            ->create(['name' => 'Nasi Lemak']);

        return Lunch::factory()
            ->withMenu($meal, [$this->chicken, $this->fish])
            ->create(['status' => $status, 'date' => $date, 'deadline' => now()->subHour()]);
    }

    private function option(Lunch $lunch, MealOption $mealOption): LunchOption
    {
        return LunchOption::where('lunch_id', $lunch->id)->where('meal_option_id', $mealOption->id)->firstOrFail();
    }

    private function set(Lunch $lunch, User $member, ?int $optionId): bool
    {
        return app(LunchVotingService::class)->setPickAsAdmin($lunch, $member, $optionId);
    }

    public function test_admin_can_set_pick_after_deadline(): void
    {
        $this->withoutDefer();
        Notification::fake();
        $lunch = $this->lunch();
        $member = User::factory()->create();
        $chicken = $this->option($lunch, $this->chicken);

        $this->assertTrue($this->set($lunch, $member, $chicken->id));

        $this->assertDatabaseHas('lunch_responses', [
            'lunch_id' => $lunch->id,
            'user_id' => $member->id,
            'lunch_option_id' => $chicken->id,
            'meal_option_id' => $this->chicken->id,
        ]);
        Notification::assertSentTo($member, LunchPickSetByAdmin::class);
    }

    public function test_admin_can_mark_not_attending_for_a_non_responder(): void
    {
        $this->withoutDefer();
        Notification::fake();
        $lunch = $this->lunch();
        $member = User::factory()->create();

        $this->assertTrue($this->set($lunch, $member, null));

        $response = LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $member->id])->firstOrFail();
        $this->assertNull($response->lunch_option_id);
        $this->assertNull($response->meal_option_id);
        Notification::assertSentTo($member, LunchPickSetByAdmin::class);
    }

    public function test_refused_on_draft_and_closed(): void
    {
        $this->withoutDefer();
        Notification::fake();
        $member = User::factory()->create();

        foreach (['draft', 'closed'] as $status) {
            $lunch = $this->lunch($status);

            try {
                $this->set($lunch, $member, $this->option($lunch, $this->chicken)->id);
                $this->fail("Expected a RuntimeException for a {$status} lunch.");
            } catch (RuntimeException) {
                // expected
            }
        }

        $this->assertSame(0, LunchResponse::count());
        Notification::assertNothingSent();
    }

    public function test_disabled_or_foreign_option_is_refused(): void
    {
        $this->withoutDefer();
        Notification::fake();
        $lunch = $this->lunch();
        $other = $this->lunch();
        $member = User::factory()->create();

        $disabled = $this->option($lunch, $this->fish);
        $disabled->update(['enabled' => false]);

        foreach ([$disabled->id, $this->option($other, $this->chicken)->id] as $optionId) {
            try {
                $this->set($lunch, $member, $optionId);
                $this->fail('Expected a RuntimeException for an unavailable option.');
            } catch (RuntimeException) {
                // expected
            }
        }

        $this->assertSame(0, LunchResponse::count());
        Notification::assertNothingSent();
    }

    public function test_setting_same_pick_is_a_no_op(): void
    {
        $this->withoutDefer();
        Notification::fake();
        $lunch = $this->lunch();
        $member = User::factory()->create();
        $chicken = $this->option($lunch, $this->chicken);
        LunchResponse::create([
            'lunch_id' => $lunch->id,
            'user_id' => $member->id,
            'lunch_option_id' => $chicken->id,
            'meal_option_id' => $this->chicken->id,
        ]);

        $this->assertFalse($this->set($lunch, $member, $chicken->id));
        Notification::assertNothingSent();
    }

    public function test_member_respond_still_refused_after_deadline(): void
    {
        $lunch = $this->lunch();

        $this->expectException(RuntimeException::class);
        app(LunchVotingService::class)->respond($lunch, User::factory()->create(), $this->option($lunch, $this->chicken)->id);
    }

    public function test_notification_copy(): void
    {
        Mail::fake();
        $this->withoutDefer();
        $lunch = $this->lunch(date: '2026-10-23');
        $member = User::factory()->create();

        $this->set($lunch, $member, $this->option($lunch, $this->chicken)->id);
        $data = $member->notifications()->latest()->first()->data;
        $this->assertSame('Your lunch pick for Fri, 23 Oct was changed', $data['title']);
        $this->assertSame('An admin set it to Nasi Lemak · Chicken.', $data['body']);

        $member->notifications()->delete();
        $this->set($lunch, $member, null);
        $this->assertSame('An admin marked you as not attending.', $member->notifications()->latest()->first()->data['body']);
    }
}
