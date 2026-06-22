<?php

namespace Tests\Feature\Lunch;

use App\Models\Caterer;
use App\Models\CatererMeal;
use App\Models\CatererMealOption;
use App\Models\Lunch;
use App\Models\MealOption;
use App\Services\LunchPlanningService;
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

        return Lunch::create([
            'date' => '2025-07-01', 'caterer_id' => $caterer->id,
            'caterer_meal_id' => $meal->id, 'deadline' => '2025-06-30 12:00', 'status' => 'draft',
        ]);
    }

    public function test_sync_creates_one_enabled_option_per_menu_option(): void
    {
        $lunch = $this->lunchWithMenu(['Beef' => 15, 'Vegan' => null]);

        app(LunchPlanningService::class)->syncOptionsFromMenu($lunch);

        $this->assertSame(2, $lunch->options()->count());
        $this->assertTrue((bool) $lunch->options()->first()->enabled);
        $this->assertSame(0, $lunch->options()->first()->default_count);
    }

    public function test_sync_is_idempotent(): void
    {
        $lunch = $this->lunchWithMenu(['Beef' => 15, 'Vegan' => null]);
        $service = app(LunchPlanningService::class);

        $service->syncOptionsFromMenu($lunch);
        $service->syncOptionsFromMenu($lunch->fresh());

        $this->assertSame(2, $lunch->options()->count());
    }
}
