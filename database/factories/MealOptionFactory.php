<?php

namespace Database\Factories;

use App\Models\MealOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealOption>
 */
class MealOptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Beef', 'Chicken', 'Vegan', 'Seafood']),
            'is_active' => true,
            'sort' => 0,
        ];
    }
}
