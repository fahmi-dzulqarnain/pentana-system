<?php

namespace Database\Factories;

use App\Models\CatererMeal;
use App\Models\Lunch;
use App\Models\LunchMenu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LunchMenu>
 */
class LunchMenuFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lunch_id' => Lunch::factory(),
            'caterer_meal_id' => CatererMeal::factory(),
            'caterer_id' => fn (array $attrs) => CatererMeal::find($attrs['caterer_meal_id'])->caterer_id,
            'sort' => 0,
        ];
    }
}
