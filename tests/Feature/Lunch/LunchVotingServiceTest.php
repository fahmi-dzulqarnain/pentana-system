<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use App\Models\LunchOption;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use App\Services\LunchVotingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LunchVotingServiceTest extends TestCase
{
    use RefreshDatabase;

    private function openLunchWithOption(bool $enabled = true): array
    {
        $lunch = Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addDay()]);
        $option = MealOption::factory()->create();
        LunchOption::factory()->create([
            'lunch_id' => $lunch->id,
            'meal_option_id' => $option->id,
            'enabled' => $enabled,
        ]);

        return [$lunch, $option];
    }

    public function test_is_open_for_voting_only_when_published_and_before_deadline(): void
    {
        $this->assertTrue(Lunch::factory()->create(['status' => 'published', 'deadline' => now()->addHour()])->isOpenForVoting());
        $this->assertFalse(Lunch::factory()->create(['status' => 'draft', 'deadline' => now()->addHour()])->isOpenForVoting());
        $this->assertFalse(Lunch::factory()->create(['status' => 'closed', 'deadline' => now()->addHour()])->isOpenForVoting());
        $this->assertFalse(Lunch::factory()->create(['status' => 'published', 'deadline' => now()->subHour()])->isOpenForVoting());
    }

    public function test_respond_creates_then_updates_a_single_row(): void
    {
        [$lunch, $option] = $this->openLunchWithOption();
        $user = User::factory()->create();
        $service = app(LunchVotingService::class);

        $service->respond($lunch, $user, $option->id);
        $this->assertDatabaseHas('lunch_responses', ['lunch_id' => $lunch->id, 'user_id' => $user->id, 'meal_option_id' => $option->id]);

        // Change to not attending - still one row.
        $service->respond($lunch, $user, null);
        $this->assertSame(1, LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->count());
        $this->assertNull(LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->first()->meal_option_id);
    }

    public function test_respond_rejects_a_disabled_option(): void
    {
        [$lunch] = $this->openLunchWithOption(enabled: false);
        $disabledOptionId = $lunch->options()->first()->meal_option_id;
        $service = app(LunchVotingService::class);
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $service->respond($lunch, $user, $disabledOptionId);
    }

    public function test_respond_rejects_an_option_not_offered_on_this_lunch(): void
    {
        // This is the tampering vector: a valid MealOption that has no LunchOption row on $lunch.
        [$lunch] = $this->openLunchWithOption();
        $strangerOption = MealOption::factory()->create();
        $service = app(LunchVotingService::class);

        $this->expectException(\RuntimeException::class);
        $service->respond($lunch, User::factory()->create(), $strangerOption->id);
    }

    public function test_respond_refuses_when_lunch_is_not_open(): void
    {
        $lunch = Lunch::factory()->create(['status' => 'closed', 'deadline' => now()->addDay()]);
        $service = app(LunchVotingService::class);

        $this->expectException(\RuntimeException::class);
        $service->respond($lunch, User::factory()->create(), null);
    }
}
