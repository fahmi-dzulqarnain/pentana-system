<?php

namespace Tests\Feature\Lunch;

use App\Models\Lunch;
use App\Models\LunchMenu;
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

        return [$lunch, $option, $lunch->options()->first()];
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
        [$lunch, $option, $lunchOption] = $this->openLunchWithOption();
        $user = User::factory()->create();
        $service = app(LunchVotingService::class);

        $service->respond($lunch, $user, $lunchOption->id);
        $this->assertDatabaseHas('lunch_responses', ['lunch_id' => $lunch->id, 'user_id' => $user->id, 'meal_option_id' => $option->id, 'lunch_option_id' => $lunchOption->id]);

        // Change to not attending - still one row.
        $service->respond($lunch, $user, null);
        $this->assertSame(1, LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->count());
        $this->assertNull(LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->first()->meal_option_id);
    }

    public function test_respond_rejects_a_disabled_option(): void
    {
        [$lunch] = $this->openLunchWithOption(enabled: false);
        $disabledOptionId = $lunch->options()->first()->id;
        $service = app(LunchVotingService::class);
        $user = User::factory()->create();

        $this->expectException(\RuntimeException::class);
        $service->respond($lunch, $user, $disabledOptionId);
    }

    public function test_respond_rejects_an_option_not_offered_on_this_lunch(): void
    {
        // This is the tampering vector: a valid MealOption that has no LunchOption row on $lunch.
        [$lunch] = $this->openLunchWithOption();
        $strangerOption = LunchOption::factory()->create();
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

    private function twoMenuLunch(bool $sameMealOption = false): array
    {
        $regular = MealOption::factory()->create();
        $other = $sameMealOption ? $regular : MealOption::factory()->create();
        $lunch = Lunch::factory()->withMenu(null, [$regular])->withMenu(null, [$other])
            ->create(['status' => 'published', 'deadline' => now()->addDay()]);
        $options = $lunch->options()->orderBy('lunch_menu_id')->get();

        return [$lunch, $options[0], $options[1]];
    }

    public function test_switching_to_another_menu_replaces_the_pick(): void
    {
        [$lunch, $a, $b] = $this->twoMenuLunch();
        $user = User::factory()->create();
        $service = app(LunchVotingService::class);

        $service->respond($lunch, $user, $a->id);
        $service->respond($lunch, $user, $b->id);

        $this->assertSame(1, LunchResponse::where(['lunch_id' => $lunch->id, 'user_id' => $user->id])->count());
        $this->assertSame([$b->id => 1], $lunch->fresh()->optionCountsByLunchOption());
    }

    public function test_same_meal_option_on_two_menus_records_the_chosen_menu(): void
    {
        [$lunch, $a, $b] = $this->twoMenuLunch(sameMealOption: true);
        $user = User::factory()->create();

        $response = app(LunchVotingService::class)->respond($lunch, $user, $b->id);

        $this->assertSame($b->id, $response->lunch_option_id);
        $this->assertSame($b->meal_option_id, $response->meal_option_id);
    }

    public function test_option_from_another_lunch_is_rejected(): void
    {
        [$lunch] = $this->twoMenuLunch();
        [, $foreign] = $this->twoMenuLunch();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('That option is not available for this lunch.');
        app(LunchVotingService::class)->respond($lunch, User::factory()->create(), $foreign->id);
    }

    public function test_not_attending_clears_the_option(): void
    {
        [$lunch, $a] = $this->twoMenuLunch();
        $user = User::factory()->create();
        $service = app(LunchVotingService::class);

        $service->respond($lunch, $user, $a->id);
        $response = $service->respond($lunch, $user, null);

        $this->assertNull($response->lunch_option_id);
        $this->assertNull($response->meal_option_id);
    }

    public function test_legacy_meal_option_resolves_on_a_one_menu_day(): void
    {
        [$lunch, $option, $lunchOption] = $this->openLunchWithOption();

        $this->assertSame(
            $lunchOption->id,
            app(LunchVotingService::class)->resolveLegacyMealOption($lunch, $option->id),
        );
    }

    public function test_legacy_meal_option_is_rejected_when_ambiguous(): void
    {
        [$lunch, $a] = $this->twoMenuLunch(sameMealOption: true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Please update the app to order for this day.');
        app(LunchVotingService::class)->resolveLegacyMealOption($lunch, $a->meal_option_id);
    }

    public function test_lunch_menu_factory_can_create_on_its_own(): void
    {
        $menu = LunchMenu::factory()->create();

        $this->assertSame($menu->catererMeal->caterer_id, $menu->caterer_id);
    }
}
