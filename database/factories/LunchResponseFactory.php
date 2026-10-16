<?php

namespace Database\Factories;

use App\Models\Lunch;
use App\Models\LunchResponse;
use App\Models\MealOption;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LunchResponse>
 */
class LunchResponseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lunch_id' => Lunch::factory(),
            'user_id' => User::factory(),
            'meal_option_id' => MealOption::factory(),
            'lunch_option_id' => null,
        ];
    }
}
