<?php

namespace Tests\Feature\Lunch;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\CatererMealOption;
use App\Models\Lunch;
use App\Models\LunchMenu;
use App\Models\LunchOption;
use App\Models\MealOption;
use App\Services\LunchPlanningService;
use App\Services\LunchPlanningStats;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LunchPlanningTest extends TestCase
{
    use RefreshDatabase;

    private function lunchWithMenu(array $optionPrices): Lunch
    {
        $caterer = Caterer::factory()->create();
        $meal = CatererMeal::create(['caterer_id' => $caterer->id, 'name' => 'Set A', 'price' => 10]);
        foreach ($optionPrices as $name => $price) {
            $opt = MealOption::factory()->create(['name' => $name]);
            CatererMealOption::create(['caterer_meal_id' => $meal->id, 'meal_option_id' => $opt->id, 'price' => $price]);
        }

        $lunch = Lunch::create(['date' => '2025-07-01', 'deadline' => '2025-06-30 12:00', 'status' => 'draft']);
        LunchMenu::create(['lunch_id' => $lunch->id, 'caterer_id' => $caterer->id, 'caterer_meal_id' => $meal->id]);

        return $lunch;
    }

    private function sync(Lunch $lunch): void
    {
        foreach ($lunch->menus()->get() as $menu) {
            app(LunchPlanningService::class)->syncOptionsFromMenu($menu);
        }
    }

    public function test_sync_creates_one_enabled_option_per_menu_option(): void
    {
        $lunch = $this->lunchWithMenu(['Beef' => 15, 'Vegan' => null]);

        $this->sync($lunch);

        $this->assertSame(2, $lunch->options()->count());
        $this->assertTrue((bool) $lunch->options()->first()->enabled);
        $this->assertSame(0, $lunch->options()->first()->default_count);
    }

    public function test_sync_is_idempotent(): void
    {
        $lunch = $this->lunchWithMenu(['Beef' => 15, 'Vegan' => null]);

        $this->sync($lunch);
        $this->sync($lunch->fresh());

        $this->assertSame(2, $lunch->options()->count());
    }

    public function test_estimated_cost_uses_enabled_options_and_effective_price(): void
    {
        $lunch = $this->lunchWithMenu(['Beef' => 15, 'Vegan' => null]); // Vegan falls back to meal price 10
        $this->sync($lunch);
        $lunch->load('options');
        // set counts: Beef 4, Vegan 2
        LunchOption::where('lunch_id', $lunch->id)->where('meal_option_id', $lunch->options[0]->meal_option_id)->update(['default_count' => 4]);
        LunchOption::where('lunch_id', $lunch->id)->where('meal_option_id', $lunch->options[1]->meal_option_id)->update(['default_count' => 2]);

        // Beef 4×15=60 ; Vegan 2×10=20 ; total 80
        $this->assertSame(80.0, $lunch->fresh()->load('menus.catererMeal.options', 'menus.options')->estimatedCost());
    }

    public function test_estimated_cost_ignores_disabled_options(): void
    {
        $lunch = $this->lunchWithMenu(['Beef' => 15, 'Vegan' => null]);
        $this->sync($lunch);
        LunchOption::where('lunch_id', $lunch->id)->update(['default_count' => 5]);
        // disable the second option
        LunchOption::where('lunch_id', $lunch->id)->latest('id')->first()->update(['enabled' => false]);

        // only the enabled Beef counts: 5 × 15 = 75
        $this->assertSame(75.0, $lunch->fresh()->load('menus.catererMeal.options', 'menus.options')->estimatedCost());
    }

    public function test_working_days_counts_weekdays_in_month(): void
    {
        // July 2025 has 23 weekdays (Mon–Fri)
        $this->assertSame(23, app(LunchPlanningStats::class)->workingDays(Carbon::parse('2025-07-15')));
    }

    public function test_month_estimated_cost_and_days_planned(): void
    {
        $stats = app(LunchPlanningStats::class);
        $lunch = $this->lunchWithMenu(['Beef' => 15]); // date 2025-07-01
        $this->sync($lunch);
        LunchOption::where('lunch_id', $lunch->id)->update(['default_count' => 3]); // 3 × 15 = 45

        $this->assertSame(45.0, $stats->estimatedCostForMonth(Carbon::parse('2025-07-10')));
        $this->assertSame(1, $stats->daysPlanned(Carbon::parse('2025-07-10')));
    }

    public function test_estimated_cost_sums_every_menu_at_its_own_prices(): void
    {
        $optA = MealOption::factory()->create();
        $optB = MealOption::factory()->create();
        $mealOne = CatererMeal::factory()->create(['price' => 10]);
        $mealTwo = CatererMeal::factory()->create(['price' => 12]);
        CatererMealOption::create(['caterer_meal_id' => $mealOne->id, 'meal_option_id' => $optA->id, 'price' => 10]);
        CatererMealOption::create(['caterer_meal_id' => $mealTwo->id, 'meal_option_id' => $optB->id, 'price' => 12]);

        $lunch = Lunch::factory()->withMenu($mealOne, [$optA])->withMenu($mealTwo, [$optB])->create();
        LunchOption::where('meal_option_id', $optA->id)->update(['default_count' => 3]);
        LunchOption::where('meal_option_id', $optB->id)->update(['default_count' => 2]);

        // 3×10 + 2×12 = 54
        $this->assertSame(54.0, $lunch->fresh()->load('menus.catererMeal.options', 'menus.options')->estimatedCost());
        $this->assertTrue($lunch->fresh()->load('menus')->hasSeveralMenus());
    }

    public function test_same_meal_option_can_sit_on_two_menus_of_one_day(): void
    {
        $option = MealOption::factory()->create();

        $lunch = Lunch::factory()->withMenu(null, [$option])->withMenu(null, [$option])->create();

        $this->assertSame(2, LunchOption::where('lunch_id', $lunch->id)->where('meal_option_id', $option->id)->count());
    }

    public function test_is_locked_when_closed_or_past_deadline(): void
    {
        $this->assertFalse(Lunch::factory()->make(['status' => 'published', 'deadline' => now()->addDay()])->isLocked());
        $this->assertTrue(Lunch::factory()->make(['status' => 'closed', 'deadline' => now()->addDay()])->isLocked());
        $this->assertTrue(Lunch::factory()->make(['status' => 'published', 'deadline' => now()->subMinute()])->isLocked());
    }
}
