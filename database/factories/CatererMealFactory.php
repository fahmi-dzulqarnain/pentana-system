<?php

namespace Database\Factories;

use App\Models\Caterer;
use App\Models\CatererMeal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CatererMeal>
 */
class CatererMealFactory extends Factory
{
    public function definition(): array
    {
        return [
            'caterer_id' => Caterer::factory(),
            'name' => fake()->words(2, true),
            'price' => fake()->randomFloat(2, 8, 25),
            'image' => null,
        ];
    }
}
