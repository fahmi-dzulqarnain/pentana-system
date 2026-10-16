<?php

namespace Database\Factories;

use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchMenu;
use App\Models\LunchOption;
use App\Models\MealOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LunchOption>
 */
class LunchOptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lunch_id' => Lunch::factory(),
            'lunch_menu_id' => function (array $attrs) {
                $lunchId = $attrs['lunch_id'];
                $menu = LunchMenu::where('lunch_id', $lunchId)->orderBy('sort')->orderBy('id')->first();
                if ($menu) {
                    return $menu->id;
                }
                $meal = CatererMeal::factory()->create();

                return LunchMenu::create([
                    'lunch_id' => $lunchId, 'caterer_id' => $meal->caterer_id, 'caterer_meal_id' => $meal->id,
                ])->id;
            },
            'meal_option_id' => MealOption::factory(),
            'enabled' => true,
            'default_count' => 0,
        ];
    }
}
