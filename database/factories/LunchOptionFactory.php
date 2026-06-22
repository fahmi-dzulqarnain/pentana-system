<?php

namespace Database\Factories;

use App\Models\Lunch;
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
            'meal_option_id' => MealOption::factory(),
            'enabled' => true,
            'default_count' => 0,
        ];
    }
}
